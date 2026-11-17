<?php

namespace Tests\Feature;

use App\Exceptions\MaintenanceWriteBlockedException;
use App\Models\{Category, Order, PaymentAttempt, PaymentReconciliationJob, Product, Setting};
use App\Services\{MaintenanceWriteBarrier, PaymentReconciliationQueue, PaymentReconciliationService, ReadinessService, ShopBackupService};
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\{Hash, Http, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class RuntimeArchitectureTest extends TestCase
{
    private array $directories = [];

    private function directory(): string
    {
        $directory = base_path('.local/runtime-test-'.Str::uuid());
        mkdir($directory, 0700, true);
        $this->directories[] = $directory;
        return $directory;
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    private function maintenance(bool $active): void
    {
        $maintenance = $this->createMock(MaintenanceMode::class);
        $maintenance->method('active')->willReturn($active);
        $this->instance(MaintenanceMode::class, $maintenance);
    }

    private function order(array $extra = []): Order
    {
        $category = Category::create(['name' => 'Runtime fixture', 'slug' => Str::uuid(), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Runtime fixture', 'slug' => Str::uuid(), 'price' => '10.00', 'is_active' => true]);
        $order = Order::create($extra + ['product_id' => $product->id, 'order_no' => 'RT'.bin2hex(random_bytes(10)),
            'email' => 'runtime@example.test', 'query_password' => Hash::make('runtime-test-password'),
            'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00', 'payment_method' => 'alipay',
            'status' => 'pending', 'ip' => '192.0.2.50', 'expires_at' => now()->addMinutes(10), 'created_at' => now()->subMinutes(3)]);
        // Timestamps are intentionally not part of the buyer-writable fillable fields.
        $order->forceFill(['created_at' => $extra['created_at'] ?? now()->subMinutes(3)])->save();
        return $order;
    }

    private function archive(array $files, array $extra = []): array
    {
        $directory = $this->directory();
        $file = $directory.'/virtual.tar';
        $archive = new \PharData($file);
        $manifest = $extra + ['version' => 1, 'files' => []];
        foreach ($files as $path => $contents) {
            $archive->addFromString($path, $contents);
            $manifest['files'][$path] = ['sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
        }
        $archive->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        unset($archive);
        return [$directory, $file];
    }

    public function test_scheduler_only_enqueues_and_deduplicates_without_gateway_requests(): void
    {
        Http::preventStrayRequests();
        Setting::set('payment_reconciliation_enabled', '1');
        $order = $this->order();
        $this->assertSame(0, $this->artisan('payments:reconcile'));
        $this->assertSame(0, $this->artisan('payments:reconcile'));
        $this->assertSame(1, PaymentReconciliationJob::count());
        $this->assertSame($order->id, PaymentReconciliationJob::first()->order_id);
        Http::assertNothingSent();
    }

    public function test_durable_reconciliation_retries_with_delay_then_requires_explicit_retry(): void
    {
        Setting::set('payment_reconciliation_enabled', '1');
        $order = $this->order();
        $service = $this->createMock(PaymentReconciliationService::class);
        $service->expects($this->exactly(5))->method('sync')->willThrowException(new \RuntimeException('private-gateway-credential-fixture'));
        $this->instance(PaymentReconciliationService::class, $service);
        $queue = app(PaymentReconciliationQueue::class);
        $this->assertSame(1, $queue->enqueueDue());
        for ($i = 1; $i <= 5; $i++) {
            PaymentReconciliationJob::first()->update(['available_at' => now()->subSecond()]);
            $this->assertSame(1, $queue->process(1));
            $job = PaymentReconciliationJob::first();
            $this->assertSame($i, $job->attempts);
            $this->assertNull($job->lease_token);
            $this->assertStringNotContainsString('private-gateway', $job->last_error);
        }
        $this->assertSame('failed', $job->status);
        $this->assertSame(0, $queue->enqueueDue());
        $this->assertSame(1, $queue->retryFailed());
        $this->assertSame('pending', $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->attempts);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_crashed_reconciliation_lease_is_reclaimed_and_paid_orders_skip_remote_io(): void
    {
        Setting::set('payment_reconciliation_enabled', '1');
        $order = $this->order(['status' => 'paid']);
        $job = PaymentReconciliationJob::create(['order_id' => $order->id, 'status' => 'processing', 'attempts' => 1,
            'reserved_at' => now()->subMinutes(6), 'available_at' => now(), 'lease_token' => Str::uuid()]);
        $service = $this->createMock(PaymentReconciliationService::class);
        $service->expects($this->never())->method('sync');
        $this->instance(PaymentReconciliationService::class, $service);
        $this->assertSame(1, app(PaymentReconciliationQueue::class)->process(1));
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(2, $job->fresh()->attempts);
    }

    public function test_reused_completed_job_tracks_the_new_queue_age_for_health(): void
    {
        Setting::set('payment_reconciliation_enabled', '1');
        $order = $this->order();
        $job = PaymentReconciliationJob::create(['order_id' => $order->id, 'status' => 'completed',
            'available_at' => now()->subDay(), 'queued_at' => now()->subDay(), 'created_at' => now()->subDay()]);
        $this->assertSame(1, app(PaymentReconciliationQueue::class)->enqueueDue());
        $this->assertTrue($job->fresh()->queued_at->gt(now()->subMinute()));
        $this->assertFalse((bool) app(\App\Services\HeartbeatService::class)->health()['reconciliation']['backlog_warning']);
    }

    public function test_old_uncertain_attempts_remain_eligible_without_recreating_a_payment(): void
    {
        Http::preventStrayRequests();
        Setting::set('payment_reconciliation_enabled', '1');
        $order = $this->order(['created_at' => now()->subDays(3), 'status' => 'expired', 'payment_method' => 'usdt_trc20']);
        $attempt = PaymentAttempt::create(['order_id' => $order->id, 'method' => 'usdt_trc20', 'status' => 'processing',
            'lease_token' => Str::uuid(), 'lease_expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(PaymentReconciliationQueue::class)->enqueueDue());
        $this->assertSame('uncertain', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->lease_token);
        Http::assertNothingSent();
    }

    public function test_maintenance_blocks_new_requests_and_restore_excludes_inflight_writers(): void
    {
        $file = $this->directory().'/barrier';
        $barrier = new class($file) extends MaintenanceWriteBarrier {
            public function __construct(private readonly string $file) {}
            protected function open() { return fopen($this->file, 'c'); }
        };
        $this->maintenance(false);
        $this->assertSame('writer', $barrier->run(fn () => 'writer'));
        try {
            $barrier->restore(fn () => $this->fail('Live restore without maintenance was allowed.'));
            $this->fail('Expected a maintenance rejection.');
        } catch (MaintenanceWriteBlockedException) { $this->assertTrue(true); }
        $this->maintenance(true);
        $held = fopen($file, 'c');
        flock($held, LOCK_SH);
        try {
            $barrier->restore(fn () => $this->fail('Restore overlapped a writer.'));
            $this->fail('Expected busy barrier rejection.');
        } catch (MaintenanceWriteBlockedException) { $this->assertTrue(true); }
        finally { flock($held, LOCK_UN); fclose($held); }
        $this->assertSame('restore', $barrier->restore(fn () => 'restore'));
        $this->expectException(MaintenanceWriteBlockedException::class);
        $barrier->run(fn () => $this->fail('Writer ran during maintenance.'));
    }

    public function test_failed_database_restore_rolls_back_files_and_uses_one_transaction(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('probe.txt', 'original-public-file');
        [$directory, $file] = $this->archive(['database.dump' => 'virtual-only', 'uploads/probe.txt' => 'restored-public-file', 'uploads/new.txt' => 'new-file']);
        $service = new class($directory) extends ShopBackupService {
            public array $command = [];
            public function __construct(private readonly string $fixtureDirectory) {}
            public function directory(): string { return $this->fixtureDirectory; }
            protected function databaseProcess(array $command, ?string $database = null, ?callable $progress = null): void {
                $this->command = $command;
                throw new \RuntimeException('virtual-restore-failed');
            }
        };
        $this->maintenance(true);
        try {
            $service->restore($file, 'cardshop_testing');
            $this->fail('Expected injected database restore failure.');
        } catch (\RuntimeException $failure) { $this->assertSame('virtual-restore-failed', $failure->getMessage()); }
        $this->assertContains('--single-transaction', $service->command);
        $this->assertSame('original-public-file', Storage::disk('public')->get('probe.txt'));
        Storage::disk('public')->assertMissing('new.txt');
        $this->assertSame([], glob($directory.'/restore-*') ?: []);
    }

    public function test_live_restore_requires_maintenance_and_isolated_restore_never_touches_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('probe.txt', 'untouched-public-file');
        [$directory, $file] = $this->archive(['database.dump' => 'virtual-only', 'uploads/probe.txt' => 'isolated-restore-data']);
        $service = new class($directory) extends ShopBackupService {
            public int $calls = 0;
            public function __construct(private readonly string $fixtureDirectory) {}
            public function directory(): string { return $this->fixtureDirectory; }
            protected function databaseProcess(array $command, ?string $database = null, ?callable $progress = null): void { $this->calls++; }
        };
        $this->maintenance(false);
        try {
            $service->restore($file, 'cardshop_testing');
            $this->fail('Unpaused live restore was allowed.');
        } catch (MaintenanceWriteBlockedException) { $this->assertSame(0, $service->calls); }
        $service->restore($file, 'cardshop_restore_test_fixture');
        $this->assertSame(1, $service->calls);
        $this->assertSame('untouched-public-file', Storage::disk('public')->get('probe.txt'));
    }

    public function test_restore_checks_keyring_identity_and_same_version_key_fingerprints(): void
    {
        $metadata = app(\App\Security\SecretCipher::class)->metadata();
        $metadata['version_fingerprints'][$metadata['active']] = str_repeat('0', 64);
        [$directory, $file] = $this->archive(['database.dump' => 'virtual-only'], ['secrets' => $metadata]);
        $service = new class($directory) extends ShopBackupService {
            public function __construct(private readonly string $fixtureDirectory) {}
            public function directory(): string { return $this->fixtureDirectory; }
            protected function databaseProcess(array $command, ?string $database = null, ?callable $progress = null): void { throw new \LogicException('Subprocess must never run with mismatched keys.'); }
        };
        $this->expectExceptionMessage('独立密钥版本不匹配');
        $service->restore($file, 'cardshop_restore_test_fixture');
    }

    public function test_readiness_fails_closed_for_missing_independent_keys_and_returns_only_booleans(): void
    {
        config(['secrets.keyring_file' => sys_get_temp_dir().'/nonexistent-runtime-fixture-'.Str::uuid()]);
        $result = app(ReadinessService::class)->check();
        $this->assertFalse($result['ready']);
        $this->assertFalse($result['checks']['secrets']);
        $this->assertTrue($result['checks']['database']);
        $this->assertTrue($result['checks']['schema']);
        foreach ($result['checks'] as $value) { $this->assertIsBool($value); }
        $this->assertSame(1, $this->artisan('shop:ready'));
    }
}
