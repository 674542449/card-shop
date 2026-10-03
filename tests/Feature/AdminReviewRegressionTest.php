<?php

namespace Tests\Feature;

use App\Http\Middleware\{AdminAuth, TrapScanners};
use App\Models\{Admin, Article, ArticleCategory, BackupRun, Blacklist, Category, Order, PaymentReconciliationJob, Product, Setting};
use App\Services\{AssetMaintenanceService, PaymentReconciliationQueue, PaymentReconciliationService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Http, Storage};
use Tests\TestCase;

class AdminReviewRegressionTest extends TestCase
{
    private function admin(array $permissions = [], string $role = 'owner'): void
    {
        $admin = Admin::create(['username' => uniqid('review-'), 'password' => Hash::make('dummy-only-password'),
            'role' => $role, 'permissions' => $permissions, 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Review fixture', 'slug' => uniqid('review-')]);
        return Product::create(['category_id' => $category->id, 'name' => 'Review fixture', 'slug' => uniqid('review-'), 'price' => '10.00']);
    }

    private function job(): PaymentReconciliationJob
    {
        $order = Order::create(['product_id' => $this->product()->id, 'order_no' => uniqid('REVIEW-'),
            'email' => 'review@example.test', 'query_password' => Hash::make('dummy-only-password'),
            'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00', 'payment_method' => 'alipay',
            'status' => 'pending', 'ip' => '192.0.2.70', 'expires_at' => now()->addMinutes(10)]);
        return PaymentReconciliationJob::create(['order_id' => $order->id, 'status' => 'failed', 'attempts' => 5,
            'last_error' => 'Gateway unavailable', 'available_at' => now(), 'finished_at' => now(), 'lease_token' => '00000000-0000-4000-8000-000000000070']);
    }

    public function test_equivalent_ipv6_and_mapped_ipv4_are_blocked_and_duplicate_admin_rules_rejected(): void
    {
        $this->admin();
        $full = '2001:0DB8:0000:0000:0000:0000:0000:0070';
        $this->postJson('/api/admin/blacklists', ['type' => 'ip', 'value' => $full])->assertCreated()->assertJsonPath('value', '2001:db8::70');
        $this->assertTrue(Blacklist::isBlocked('2001:db8::70'));
        $this->assertTrue(Blacklist::isBlocked($full));
        $this->postJson('/api/admin/blacklists', ['type' => 'ip', 'value' => '2001:db8::70'])->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::70'])->get('/')->assertForbidden();
        Blacklist::create(['type' => 'ip', 'value' => '::ffff:192.0.2.71']);
        $this->assertTrue(Blacklist::isBlocked('192.0.2.71'));
        $this->assertTrue(Blacklist::isBlocked('::ffff:c000:247'));
        $this->assertFalse(Blacklist::isBlocked('192.0.2.72'));
    }

    public function test_honeypot_whitelist_uses_the_same_ipv6_identity(): void
    {
        Setting::set('honeypot_enabled', '1');
        Setting::set('honeypot_skip_reserved_ips', '0');
        Setting::set('honeypot_whitelist', '2001:0db8:0000:0000:0000:0000:0000:0070');
        $request = Request::create('/.env', 'GET', server: ['REMOTE_ADDR' => '2001:db8::70']);
        try { app(TrapScanners::class)->handle($request, fn () => response('not-found', 404)); }
        catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { /* Probes remain 404 even when whitelisted. */ }
        $this->assertFalse(Blacklist::isBlocked('2001:db8::70'));
        $this->assertSame(0, Blacklist::count());
    }

    public function test_reconciliation_retry_is_authorized_idempotent_and_does_not_mark_paid(): void
    {
        Http::preventStrayRequests();
        $job = $this->job();
        $path = '/api/admin/maintenance/reconciliation-jobs';
        $this->admin(['maintenance:write'], 'staff');
        $this->getJson($path)->assertForbidden();
        $this->postJson($path.'/'.$job->id.'/retry')->assertForbidden();
        $this->admin(['maintenance:read', 'orders:read'], 'staff');
        $this->getJson($path.'?status=failed')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.lease_token');
        $this->postJson($path.'/'.$job->id.'/retry')->assertForbidden();
        $this->admin(['maintenance:write', 'orders:write'], 'staff');
        $this->postJson($path.'/'.$job->id.'/retry')->assertUnprocessable();
        Setting::set('payment_reconciliation_enabled', '1');
        $this->postJson($path.'/'.$job->id.'/retry')->assertAccepted();
        $this->postJson($path.'/'.$job->id.'/retry')->assertUnprocessable();
        $this->assertSame('pending', $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->attempts);
        $this->assertNull($job->fresh()->lease_token);
        $this->assertSame('pending', $job->order->status);
        $this->assertSame(0, $job->order->paymentReceipts()->count());
        Http::assertNothingSent();
    }

    public function test_manual_query_only_resolves_failed_jobs_after_a_successful_query(): void
    {
        $this->admin(); $job = $this->job();
        $service = $this->createMock(PaymentReconciliationService::class);
        $service->method('sync')->willThrowException(new \RuntimeException('Gateway unavailable'));
        $this->instance(PaymentReconciliationService::class, $service);
        $this->postJson('/api/admin/orders/'.$job->order_id.'/sync')->assertUnprocessable();
        $this->assertSame('failed', $job->fresh()->status);
        $service = $this->createMock(PaymentReconciliationService::class);
        $service->method('sync')->willReturn(['paid' => false]);
        $this->instance(PaymentReconciliationService::class, $service);
        $this->postJson('/api/admin/orders/'.$job->order_id.'/sync')->assertOk();
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertNull($job->fresh()->last_error);
        $this->assertSame('pending', $job->order->status);
        $job->update(['status' => 'processing', 'lease_token' => '00000000-0000-4000-8000-000000000071']);
        app(PaymentReconciliationQueue::class)->completeManualCheck($job->order);
        $this->assertSame('processing', $job->fresh()->status);
        $this->assertSame('00000000-0000-4000-8000-000000000071', $job->fresh()->lease_token);
    }

    public function test_old_backup_failures_are_reachable_and_filterable_beyond_twenty_newer_runs(): void
    {
        $this->admin();
        $old = BackupRun::create(['status' => 'failed', 'last_error' => 'Dummy failure']);
        for ($i = 0; $i < 25; $i++) BackupRun::create(['status' => 'completed']);
        $this->getJson('/api/admin/maintenance/backup-runs?page=2&per_page=20')->assertOk()->assertJsonPath('total', 26)->assertJsonCount(6, 'data');
        $this->getJson('/api/admin/maintenance/backup-runs?needs_attention=1')->assertOk()->assertJsonPath('data.0.id', $old->id)->assertJsonPath('total', 1);
        $this->postJson('/api/admin/maintenance/backup-runs/'.$old->id.'/acknowledge')->assertOk();
        $this->getJson('/api/admin/maintenance/backup-runs?needs_attention=1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/maintenance/backup-runs?status=invalid')->assertUnprocessable();
    }

    public function test_nonempty_categories_cannot_cascade_delete_products_or_articles(): void
    {
        $this->admin(); $product = $this->product();
        $category = ArticleCategory::create(['name' => 'Review content', 'slug' => 'review-content']);
        $article = Article::create(['article_category_id' => $category->id, 'title' => 'Review article', 'slug' => 'review-article', 'content' => 'Dummy']);
        foreach ([['categories', $product->category_id, 'products', $product->id], ['article-categories', $category->id, 'articles', $article->id]] as [$route, $id, $table, $child]) {
            $this->deleteJson('/api/admin/'.$route.'/'.$id)->assertUnprocessable();
            $this->assertDatabaseHas($table, ['id' => $child]);
            // A writer bypassing the controller must still hit the database guard.
            try {
                DB::transaction(fn () => DB::table(str_replace('-', '_', $route))->where('id', $id)->delete());
                $this->fail('The database allowed a cascading delete.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertSame('23503', $error->getCode());
            }
            $this->assertDatabaseHas($table, ['id' => $child]);
        }
    }

    public function test_assets_advertise_age_limit_and_survive_a_reference_appearing_during_archive(): void
    {
        Storage::fake('public'); Storage::fake('local');
        $path = 'uploads/2026/10/'.str_repeat('r', 32).'.png';
        $disk = Storage::disk('public'); $disk->put($path, 'dummy-image');
        $this->assertFalse(app(AssetMaintenanceService::class)->list()[0]['can_quarantine']);
        touch($disk->path($path), time() - 8 * 86400); clearstatcache();
        $product = $this->product();
        // Commit a reference after the first snapshot, reproducing the vulnerable ordering.
        $service = new class($product, $path) extends AssetMaintenanceService {
            private bool $first = true;
            public function __construct(private Product $product, private string $path) {}
            public function references(): string {
                $snapshot = parent::references();
                if ($this->first) { $this->first = false; $this->product->update(['image' => '/storage/'.$this->path]); }
                return $snapshot;
            }
        };
        try { $service->quarantine($path); $this->fail('A newly referenced image was archived.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('刚被引用', $error->getMessage()); }
        $this->assertSame('dummy-image', $disk->get($path));
        Storage::disk('local')->assertMissing('asset-quarantine/'.$path);
    }
}
