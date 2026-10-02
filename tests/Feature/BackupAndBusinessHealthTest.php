<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\Admin;
use App\Models\BackupRun;
use App\Models\NotificationDelivery;
use App\Models\SeoDelivery;
use App\Services\BackupQueue;
use App\Services\HeartbeatService;
use App\Services\SettingService;
use App\Services\ShopBackupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupAndBusinessHealthTest extends TestCase
{
    private array $fixtures = [];

    protected function tearDown(): void
    {
        // Delete only files created by this test, never enumerate real backup storage.
        foreach (array_reverse($this->fixtures) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        parent::tearDown();
    }

    private function owner(): Admin
    {
        $admin = Admin::create(['username' => 'backup-owner', 'password' => 'test-owner-password-123', 'role' => 'owner', 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        return $admin;
    }

    private function setting(string $key, mixed $value): void
    {
        app(SettingService::class)->set($key, $value, 'maintenance');
    }

    private function directory(bool $outside = false): string
    {
        $root = $outside ? sys_get_temp_dir() : base_path('.local');
        $directory = $root.'/shop-backup-test-'.Str::uuid();
        mkdir($directory, 0700, true);
        $this->fixtures[] = $directory;
        return $directory;
    }

    private function file(string $directory, string $name, string $content = 'virtual-backup-fixture'): string
    {
        $path = $directory.'/'.$name;
        file_put_contents($path, $content);
        $this->fixtures[] = $path;
        return $path;
    }

    private function healthyServices(): HeartbeatService
    {
        $this->setting('backup_min_free_mb', 0);
        $service = app(HeartbeatService::class);
        $service->beat('notifications');
        $service->beat('scheduler');
        return $service;
    }

    public function test_backup_http_is_immediate_deduplicated_and_owner_only(): void
    {
        $owner = $this->owner();
        $backups = $this->createMock(ShopBackupService::class);
        $backups->expects($this->never())->method('create');
        $this->instance(ShopBackupService::class, $backups);
        $first = $this->postJson('/api/admin/maintenance/backups')->assertAccepted()->assertJsonPath('run.status', 'pending');
        $id = $first->json('run.id');
        $this->postJson('/api/admin/maintenance/backups')->assertAccepted()->assertJsonPath('run.id', $id);
        $this->assertSame(1, BackupRun::count());
        $this->getJson('/api/admin/maintenance/backup-runs/'.$id)->assertOk()->assertJsonMissingPath('lease_token');
        $this->getJson('/api/admin/maintenance/backup-runs/9223372036854775808')->assertNotFound();
        $staff = Admin::create(['username' => 'backup-staff', 'password' => 'test-staff-password-123',
            'role' => 'staff', 'permissions' => ['maintenance:write'], 'is_active' => true]);
        Cache::flush(); // This assertion concerns authorization, independently of the submit throttle.
        $this->withSession(['admin_id' => $staff->id, 'admin_pw' => AdminAuth::passwordFingerprint($staff->password)]);
        $this->getJson('/api/admin/maintenance/backup-runs/'.$id)->assertForbidden();
        $this->postJson('/api/admin/maintenance/backups')->assertForbidden();
        $this->assertSame($owner->id, BackupRun::first()->requested_by);
    }

    public function test_worker_claim_progress_validation_and_lock_prevent_parallel_execution(): void
    {
        $path = $this->file($this->directory(), 'shop-virtual.tar.gz');
        $backup = $this->createMock(ShopBackupService::class);
        $backup->expects($this->once())->method('create')->willReturnCallback(function ($progress) use ($path) {
            $progress(45, '打包测试文件');
            $this->assertSame('running', BackupRun::first()->status);
            $this->assertSame(45, BackupRun::first()->progress);
            return $path;
        });
        $backup->expects($this->once())->method('validate')->with($path)->willReturn(['version' => 1]);
        $backup->expects($this->once())->method('prune');
        $queue = new BackupQueue($backup);
        $job = $queue->enqueue();
        $lock = Cache::lock('shop:backup', BackupQueue::LEASE_SECONDS);
        $this->assertTrue($lock->get());
        $this->assertFalse($queue->process());
        $this->assertSame('pending', $job->fresh()->status);
        $lock->release();
        $this->assertTrue($queue->process());
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('shop-virtual.tar.gz', $job->fresh()->filename);
        $this->assertSame(100, $job->fresh()->progress);
        $this->assertNull($job->fresh()->lease_token);
        $this->assertFalse($queue->process());
    }

    public function test_crashed_lease_becomes_failed_and_explicit_retry_clears_ack(): void
    {
        $job = BackupRun::create(['status' => 'running', 'lease_token' => Str::uuid(),
            'lease_expires_at' => now()->subSecond(), 'started_at' => now()->subHours(3)]);
        $backup = $this->createMock(ShopBackupService::class);
        $backup->expects($this->never())->method('create');
        $queue = new BackupQueue($backup);
        $this->assertFalse($queue->process());
        $this->assertSame('failed', $job->fresh()->status);
        $job->update(['health_acknowledged_at' => now()]);
        $retry = $queue->retry($job);
        $this->assertSame('pending', $retry->status);
        $this->assertNull($retry->health_acknowledged_at);
        $this->assertNull($retry->lease_token);
    }

    public function test_durable_running_lease_blocks_another_claim_after_redis_lock_loss(): void
    {
        BackupRun::create(['status' => 'running', 'started_at' => now(), 'lease_expires_at' => now()->addHour()]);
        $pending = BackupRun::create(['status' => 'pending']);
        $backup = $this->createMock(ShopBackupService::class);
        $backup->expects($this->never())->method('create');
        $this->assertFalse((new BackupQueue($backup))->process());
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_worker_failure_never_exposes_transport_or_configuration_secrets(): void
    {
        $backup = $this->createMock(ShopBackupService::class);
        $backup->method('create')->willThrowException(new \RuntimeException('test-secret-fixture-postgres-password'));
        $queue = new BackupQueue($backup);
        $job = $queue->enqueue();
        $this->assertTrue($queue->process());
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertStringNotContainsString('test-secret-fixture', $job->fresh()->last_error);
    }

    public function test_sync_and_retention_failures_preserve_success_and_can_be_explicitly_retried(): void
    {
        $path = $this->file($this->directory(), 'shop-virtual.tar.gz');
        $this->setting('backup_sync_directory', '/explicit-mounted-directory-fixture');
        $backup = $this->createMock(ShopBackupService::class);
        $backup->method('create')->willReturn($path);
        $backup->method('validate')->willReturn(['version' => 1]);
        $backup->method('sync')->willThrowException(new \RuntimeException('test-secret-mount-fixture'));
        $backup->method('prune')->willThrowException(new \RuntimeException('test-secret-prune-fixture'));
        $queue = new BackupQueue($backup);
        $job = $queue->enqueue();
        $queue->process();
        $completed = $job->fresh();
        $this->assertSame('completed', $completed->status);
        $this->assertSame('failed', $completed->sync_status);
        $this->assertNotNull($completed->last_error);
        $this->assertStringNotContainsString('test-secret', $completed->last_error);
        $this->assertFileExists($path);
        $new = $queue->retry($completed);
        $this->assertNotSame($completed->id, $new->id);
        $this->assertSame('pending', $new->status);
        $this->assertNotNull($completed->fresh()->health_acknowledged_at);
    }

    public function test_daily_automatic_backup_is_disabled_by_default_and_deduplicates_with_catch_up(): void
    {
        $queue = app(BackupQueue::class);
        $this->travelTo(now()->startOfDay()->addHours(4));
        $this->assertNull($queue->schedule());
        $this->setting('backup_auto_enabled', 1);
        $this->setting('backup_schedule_time', '05:00');
        $this->assertNull($queue->schedule());
        $this->travel(2)->hours();
        $one = $queue->schedule();
        $this->assertSame('scheduled', $one->source);
        $this->assertSame($one->id, $queue->schedule()->id);
        $one->update(['status' => 'completed', 'finished_at' => now()]);
        $this->travel(1)->days();
        $this->assertNotSame($one->id, $queue->schedule()->id);
        $this->assertSame(2, BackupRun::count());
    }

    public function test_business_failures_make_health_unhealthy_and_ack_only_observed_failures(): void
    {
        $this->owner();
        $health = $this->healthyServices();
        $notification = NotificationDelivery::create(['dedupe_key' => 'health-test', 'type' => 'new_order',
            'status' => 'failed', 'available_at' => now()]);
        $seo = SeoDelivery::create(['dedupe_key' => hash('sha256', 'health-seo'), 'provider' => 'indexnow',
            'url' => 'https://shop.example.test/', 'status' => 'failed', 'available_at' => now()]);
        $snapshot = $health->health();
        $this->assertFalse($snapshot['healthy']);
        $this->assertSame(1, $snapshot['notification_failed']);
        $this->assertSame(1, $snapshot['seo_failed']);
        $later = NotificationDelivery::create(['dedupe_key' => 'later-health-test', 'type' => 'new_order',
            'status' => 'failed', 'available_at' => now()]);
        $this->postJson('/api/admin/maintenance/health/acknowledge', ['type' => 'notifications',
            'through_id' => $snapshot['notification_failure_through_id'], 'observed_at' => $snapshot['observed_at']])->assertOk();
        $this->assertNotNull($notification->fresh()->health_acknowledged_at);
        $this->assertNull($later->fresh()->health_acknowledged_at);
        $this->postJson('/api/admin/maintenance/health/acknowledge', ['type' => 'seo',
            'through_id' => $seo->id, 'observed_at' => now()->toIso8601String()])->assertOk();
        $this->postJson('/api/admin/seo-deliveries/'.$seo->id.'/retry')->assertAccepted();
        $this->assertNull($seo->fresh()->health_acknowledged_at);
        $this->assertSame('pending', $seo->fresh()->status);
    }

    public function test_old_seo_backlog_and_reconciliation_failure_versions_are_monitored(): void
    {
        $health = $this->healthyServices();
        SeoDelivery::create(['dedupe_key' => hash('sha256', 'old-seo'), 'provider' => 'indexnow',
            'url' => 'https://shop.example.test/', 'available_at' => now(),
            'created_at' => now()->subMinutes(20)]);
        $this->assertTrue($health->health()['backlog_warning']);
        $this->setting('payment_reconciliation_enabled', 1);
        for ($i = 0; $i < 3; $i++) {
            $health->recordReconciliation(false);
        }
        $this->assertTrue($health->health()['reconciliation']['failure_warning']);
        $health->acknowledgeReconciliation(3);
        $this->assertFalse($health->health()['reconciliation']['failure_warning']);
        $health->recordReconciliation(false);
        $this->assertTrue($health->health()['reconciliation']['failure_warning']);
        $health->recordReconciliation(true);
        $this->assertSame(0, $health->health()['reconciliation']['consecutive_failures']);
        $this->assertFalse($health->health()['reconciliation']['failure_warning']);
    }

    public function test_bounded_running_lease_avoids_compression_false_alarm_and_expires(): void
    {
        $this->setting('backup_min_free_mb', 0);
        $job = BackupRun::create(['status' => 'running', 'started_at' => now()->subMinutes(20),
            'lease_expires_at' => now()->addMinutes(20)]);
        $health = app(HeartbeatService::class);
        $this->assertTrue($health->backupHealth()['worker_healthy']);
        $this->travel(3)->hours();
        $this->assertFalse($health->backupHealth()['worker_healthy']);
        $job->update(['status' => 'failed', 'last_error' => 'safe-test-error']);
        $this->assertSame(1, $health->backupHealth()['failed']);
    }

    public function test_retention_is_bounded_and_preserves_latest_and_unrelated_files(): void
    {
        $directory = $this->directory();
        $latest = $this->file($directory, 'shop-20301212-latest.tar.gz');
        $old = $this->file($directory, 'shop-20200101-old.tar.gz');
        $unrelated = $this->file($directory, 'keep-this.txt');
        $this->setting('backup_retention_count', 1);
        $this->setting('backup_retention_days', 1);
        $service = new class($directory) extends ShopBackupService {
            public function __construct(private readonly string $fixtureDirectory) {}
            public function directory(): string { return $this->fixtureDirectory; }
        };
        $service->prune();
        $this->assertFileExists($latest);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($unrelated);
    }

    public function test_sync_only_accepts_explicit_controlled_directory_and_copies_virtual_file(): void
    {
        $source = $this->directory();
        $destination = $this->directory(true);
        $service = new class($source) extends ShopBackupService {
            public function __construct(private readonly string $fixtureDirectory) {}
            public function directory(): string { return $this->fixtureDirectory; }
        };
        $this->assertNull($service->syncDirectory());
        foreach (['https://unknown.example.test/', 'relative/path', base_path()] as $invalid) {
            $this->setting('backup_sync_directory', $invalid);
            try {
                $service->syncDirectory();
                $this->fail('Uncontrolled sync destination was accepted.');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        $this->setting('backup_sync_directory', $destination);
        $path = $this->file($source, 'shop-virtual-copy.tar.gz');
        $copy = $destination.'/'.basename($path);
        $this->fixtures[] = $copy;
        $service->sync($path);
        $this->assertSame(hash_file('sha256', $path), hash_file('sha256', $copy));
        $this->expectException(\RuntimeException::class);
        $service->sync($path);
    }

    public function test_seo_pagination_obeys_bounded_page_size(): void
    {
        $this->owner();
        for ($i = 0; $i < 7; $i++) {
            SeoDelivery::create(['provider' => 'indexnow', 'url' => 'https://shop.example.test/'.$i,
                'dedupe_key' => hash('sha256', (string) $i), 'available_at' => now()]);
        }
        $this->getJson('/api/admin/seo-deliveries?per_page=3&page=2')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('total', 7)->assertJsonPath('per_page', 3);
        $this->getJson('/api/admin/seo-deliveries?per_page=201')->assertUnprocessable();
        $this->getJson('/api/admin/seo-deliveries?page=0')->assertUnprocessable();
    }

    public function test_external_health_alert_is_cooled_down_without_real_delivery(): void
    {
        $this->healthyServices();
        NotificationDelivery::create(['dedupe_key' => 'cooldown-test', 'type' => 'new_order',
            'status' => 'failed', 'available_at' => now()]);
        $sender = $this->createMock(\App\Services\NotificationService::class);
        $sender->expects($this->once())->method('sendTelegramNotification')->willReturn(true);
        $this->instance(\App\Services\NotificationService::class, $sender);
        $this->assertSame(1, $this->artisan('shop:health', ['--alert' => true]));
        $this->assertSame(1, $this->artisan('shop:health', ['--alert' => true]));
    }

    public function test_archive_is_verified_and_published_atomically_using_only_virtual_inputs(): void
    {
        $directory = $this->directory();
        $upload = $this->file($directory, 'fixture.txt', 'virtual-original-upload');
        // Override both source discovery and the subprocess. This cannot read .env,
        // real uploads, or the live database, even when run from the real workspace.
        $service = new class($directory, $upload) extends ShopBackupService {
            public function __construct(private readonly string $fixtureDirectory, private readonly string $upload) {}
            public function directory(): string { return $this->fixtureDirectory; }
            protected function backupInputs(string $stage): array
            {
                return ['database.dump' => $stage.'/database.dump', 'uploads/fixture.txt' => $this->upload];
            }
            protected function databaseProcess(array $command, ?string $database = null, ?callable $progress = null): void
            {
                foreach ($command as $argument) {
                    if (str_starts_with($argument, '--file=')) {
                        file_put_contents(substr($argument, 7), 'virtual-database-fixture');
                        return;
                    }
                }
                throw new \RuntimeException('The fixture only allows a dump output file.');
            }
        };
        $file = $service->create(function ($percent) use ($directory, $upload) {
            $this->assertSame([], glob($directory.'/shop-*.tar.gz') ?: []);
            if ($percent === 70) {
                file_put_contents($upload, 'virtual-modified-after-snapshot');
            }
        });
        $this->fixtures[] = $file;
        $manifest = $service->validate($file);
        $this->assertSame(hash('sha256', 'virtual-original-upload'), $manifest['files']['uploads/fixture.txt']['sha256']);
        $this->assertCount(2, $manifest['files']);
        $this->assertSame(1, count(glob($directory.'/shop-*.tar.gz') ?: []));
        $this->assertFalse(is_dir(substr($file, 0, -7)));
    }
}
