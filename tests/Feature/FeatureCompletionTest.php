<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Card;
use App\Models\Category;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\SeoDelivery;
use App\Models\Setting;
use App\Services\AssetMaintenanceService;
use App\Services\HeartbeatService;
use App\Services\OrderFulfilmentService;
use App\Services\OrderLookupService;
use App\Services\OrderService;
use App\Services\PaymentReconciliationService;
use App\Services\RefundService;
use App\Services\SeoQueue;
use App\Services\ShopBackupService;
use App\Support\ContentRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FeatureCompletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function product(int $stock = 2): Product
    {
        $category = Category::create(['name' => '完整功能分类', 'slug' => uniqid('complete-cat-'), 'is_active' => true]);
        $product = Product::create(['name' => '原商品名称', 'slug' => uniqid('complete-product-'), 'category_id' => $category->id, 'price' => '10.00', 'is_active' => true]);
        for ($i = 0; $i < $stock; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'complete-secret-'.$i, 'status' => 'unsold']);
        }

        return $product;
    }

    private function order(Product $product, array $extra = []): Order
    {
        return Order::create($extra + ['order_no' => uniqid('COMPLETE'), 'product_id' => $product->id, 'email' => 'buyer@example.test',
            'query_password' => Hash::make('buyer-password'), 'query_password_key' => Order::passwordKey('buyer@example.test', 'buyer-password'),
            'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00', 'payment_method' => 'alipay', 'status' => 'pending', 'ip' => '192.0.2.1', 'expires_at' => now()->addMinutes(30)]);
    }

    private function admin(array $extra = []): Admin
    {
        $admin = Admin::create($extra + ['username' => uniqid('complete-admin-'), 'password' => 'complete-password-123', 'role' => 'owner', 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);

        return $admin;
    }

    private function token(array $extra = []): ApiToken
    {
        return ApiToken::create($extra + ['name' => 'complete', 'token' => hash('sha256', 'complete-token'), 'is_active' => true]);
    }

    private function epay(): void
    {
        Setting::set('epay_api_url', 'https://gateway.example.test');
        Setting::set('epay_merchant_id', '1');
        Setting::set('epay_merchant_key', 'complete-merchant-secret');
    }

    public function test_multibyte_query_password_cannot_be_silently_truncated_or_reserve_stock(): void
    {
        $p = $this->product();
        $this->epay();
        $data = ['product_id' => $p->id, 'quantity' => 1, 'email' => 'buyer@example.test',
            'query_password' => str_repeat('密', 25), 'payment_method' => 'alipay', 'ip' => '192.0.2.1'];
        $this->post('/order/create', $data)->assertSessionHasErrors('query_password');
        $this->token();
        $this->withToken('complete-token')->postJson('/api/v1/orders', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('query_password')
            ->assertJsonPath('errors.query_password.0', '查询密码不能超过72字节，中文等字符会占用多个字节');
        $this->assertSame(0, Order::count());
        $this->assertSame(2, $p->stockCount());
        try {
            app(OrderService::class)->createOrder($data);
            $this->fail('The service must reject passwords beyond the bcrypt byte limit.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('72', $e->getMessage());
        }
    }

    public function test_old_unique_password_is_found_by_exact_number_and_history_is_pageable(): void
    {
        $p = $this->product();
        $old = $this->order($p, ['query_password' => Hash::make('old-password'), 'query_password_key' => null]);
        for ($i = 0; $i < 70; $i++) {
            $this->order($p, ['query_password_key' => null]);
        }
        $lookup = app(OrderLookupService::class);
        $exact = $lookup->search('BUYER@example.test', 'old-password', $old->order_no);
        $this->assertSame([$old->id], $exact['orders']->pluck('id')->all());
        $this->assertNotNull($old->fresh()->query_password_key);
        $this->post('/order/query', ['email' => $old->email, 'query_password' => 'old-password', 'order_no' => $old->order_no])->assertOk()->assertSee($old->order_no);
        $first = $lookup->search('buyer@example.test', 'buyer-password');
        $this->assertTrue($first['has_more']);
        $found = $first['orders']->pluck('id')->all();
        $page = $first;
        for ($i = 0; $page['has_more'] && $i < 10; $i++) {
            $this->assertNotNull($page['cursor']);
            $next = $lookup->search('buyer@example.test', 'buyer-password', null, $page['cursor']);
            $ids = $next['orders']->pluck('id')->all();
            $this->assertEmpty(array_intersect($found, $ids));
            $found = array_merge($found, $ids);
            $page = $next;
        }
        $this->assertFalse($page['has_more']);
        $this->assertCount(70, $found);
        $this->assertCount(0, $lookup->search('buyer@example.test', 'wrong-password', $old->order_no)['orders']);
    }

    public function test_history_continuation_keeps_verified_orders_and_has_no_password_in_url(): void
    {
        $p = $this->product();
        for ($i = 0; $i < 65; $i++) {
            $this->order($p);
        }
        $r = $this->post('/order/query', ['email' => 'buyer@example.test', 'query_password' => 'buyer-password']);
        $r->assertOk()->assertSee('/order/query/page')->assertDontSee('name="query_password"', false);
        $this->assertNotEmpty(session('order_verified_ids'));
        for ($i = 0; count(session('order_verified_ids')) < 65 && $i < 9; $i++) {
            $this->post('/order/query/page')->assertOk();
        }
        $this->assertCount(65, session('order_verified_ids'));
        $this->travel(11)->minutes();
        $this->post('/order/query/page')->assertRedirect('/order/query');
    }

    public function test_category_disabled_is_hidden_everywhere_and_existing_orders_remain_accessible(): void
    {
        $p = $this->product();
        $o = $this->order($p, ['status' => 'paid']);
        $p->category->update(['is_active' => false]);
        $this->get('/product/'.$p->slug)->assertNotFound();
        $this->get('/category/'.$p->category->slug)->assertNotFound();
        $this->get('/')->assertDontSee($p->slug);
        $this->token();
        $this->withToken('complete-token')->getJson('/api/v1/products/'.$p->id)->assertNotFound();
        $this->withToken('complete-token')->getJson('/api/v1/products')->assertJsonCount(0, 'data');
        $this->withBuyerSession($o)->get('/order/detail/'.$o->order_no)->assertOk();
        $this->expectException(\RuntimeException::class);
        app(OrderService::class)->createOrder(['product_id' => $p->id, 'quantity' => 1, 'email' => $o->email, 'query_password' => 'buyer-password', 'payment_method' => 'alipay', 'ip' => '192.0.2.1']);
    }

    public function test_product_api_bounds_pagination_and_front_search_finds_later_products(): void
    {
        $p = $this->product();
        for ($i = 0; $i < 28; $i++) {
            Product::create(['name' => 'later-product-'.$i, 'slug' => 'later-'.$i, 'category_id' => $p->category_id, 'price' => 10, 'is_active' => true]);
        }
        $this->token();
        foreach (['per_page=-1', 'per_page=101', 'page=0', 'per_page=oops'] as $query) {
            $this->withToken('complete-token')->getJson('/api/v1/products?'.$query)->assertUnprocessable();
        }
        $this->withToken('complete-token')->getJson('/api/v1/products?per_page=5')->assertOk()->assertJsonCount(5, 'data');
        $this->get('/?q=later-product-27')->assertOk()->assertSee('later-product-27')->assertDontSee('later-product-26');
        $this->get('/?page=2')->assertOk()->assertSee('later-product-27');
    }

    public function test_snapshot_and_api_review_survive_rename_and_duplicate_receipts_are_deduplicated(): void
    {
        $p = $this->product();
        $token = $this->token();
        $o = $this->order($p, ['api_token_id' => $token->id]);
        $p->update(['name' => '新的商品名称']);
        $fulfil = app(OrderFulfilmentService::class);
        $this->assertTrue($fulfil->fulfilFromGateway($o->order_no, 'complete-trade-1', '10.00', 'epay')->wasFulfilled());
        $fulfil->fulfilFromGateway($o->order_no, 'complete-trade-2', '10.00', 'epay');
        $fulfil->fulfilFromGateway($o->order_no, 'complete-trade-2', '10.00', 'epay');
        $this->assertSame(2, $o->paymentReceipts()->count());
        $this->assertSame(1, NotificationDelivery::where('type', 'payment_review')->count());
        $this->assertSame('complete-trade-1', $o->fresh()->payment_no);
        $this->assertSame(1, $o->cards()->where('status', 'sold')->count());
        $this->withToken('complete-token')->postJson('/api/v1/orders/'.$o->order_no.'/query', ['email' => $o->email, 'query_password' => 'buyer-password'])
            ->assertOk()->assertJsonPath('data.product.name', '原商品名称')->assertJsonPath('data.payment_review', true)->assertJsonPath('data.payment_received_currency', 'CNY');
        $receipt = $o->paymentReceipts()->where('trade_no', 'complete-trade-2')->first();
        $this->admin();
        $this->postJson('/api/admin/orders/'.$o->id.'/receipts/'.$receipt->id.'/resolve', ['note' => '已完成线下处理'])->assertOk();
        $fulfil->fulfilFromGateway($o->order_no, 'complete-trade-2', '10.00', 'epay');
        $this->assertFalse(Order::paymentReview()->whereKey($o->id)->exists());
    }

    public function test_extra_receipt_during_stock_shortage_still_requires_review_after_delivery(): void
    {
        $p = $this->product(0);
        $o = $this->order($p);
        $service = app(OrderFulfilmentService::class);
        $service->fulfilFromGateway($o->order_no, 'first-shortage', '10.00', 'epay');
        Card::create(['product_id' => $p->id, 'status' => 'unsold', 'content' => 'late-stock']);
        $this->assertTrue($service->fulfilFromGateway($o->order_no, 'second-shortage', '10.00', 'epay')->wasFulfilled());
        $this->assertNotNull($o->paymentReceipts()->where('trade_no', 'first-shortage')->first()->review_reason);
        $this->assertTrue(Order::paymentReview()->whereKey($o->id)->exists());
    }

    public function test_usdt_receipt_keeps_fiat_and_token_amount_separate(): void
    {
        $o = $this->order($this->product(), ['payment_method' => 'usdt_trc20']);
        app(OrderFulfilmentService::class)->fulfilFromGateway($o->order_no, 'usdt-complete', '10.00', 'epusdt', ['actual_amount' => '1.42000001', 'currency' => 'USDT', 'network' => 'TRC20', 'transaction_hash' => 'hash-test']);
        $r = $o->paymentReceipts()->first();
        $this->assertSame('10.00', $r->amount);
        $this->assertSame('1.42000001', $r->actual_amount);
        $this->assertSame('TRC20', $r->network);
    }

    public function test_reconciliation_checks_gateway_binding_and_never_delivers_underpaid_orders(): void
    {
        $this->epay();
        $o = $this->order($this->product());
        $reply = ['code' => 1, 'out_trade_no' => $o->order_no, 'pid' => '1', 'type' => 'alipay', 'status' => 1, 'money' => '0.01', 'trade_no' => 'reconcile-trade'];
        $paidReply = array_replace($reply, ['money' => '10.00']);
        Http::fake(['gateway.example.test/*' => Http::sequence()->push($reply)->push($paidReply)]);
        try {
            app(PaymentReconciliationService::class)->sync($o);
            $this->fail('Underpaid reconciliation must fail');
        } catch (\RuntimeException) {
        }
        $this->assertSame('pending', $o->fresh()->status);
        $this->assertSame(0, PaymentReceipt::count());
        $this->assertTrue(app(PaymentReconciliationService::class)->sync($o)['paid']);
        $this->assertSame('paid', $o->fresh()->status);
    }

    public function test_reconciliation_transport_errors_do_not_store_merchant_secrets(): void
    {
        $this->epay();
        $o = $this->order($this->product());
        Http::fake(fn () => throw new \RuntimeException('https://gateway.example.test/?key=complete-merchant-secret'));
        try {
            app(PaymentReconciliationService::class)->sync($o);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('complete-merchant-secret', $e->getMessage());
        }
        $this->assertStringNotContainsString('complete-merchant-secret', $o->fresh()->reconciliation_error);
        Setting::set('epay_api_url', 'http://unsafe.example.test');
        try {
            app(PaymentReconciliationService::class)->sync($o);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTPS', $e->getMessage());
        }
    }

    public function test_reconciliation_rejects_different_merchant_or_order(): void
    {
        $this->epay();
        $o = $this->order($this->product());
        Http::fake(['gateway.example.test/*' => Http::response(['code' => 1, 'out_trade_no' => 'another-order', 'pid' => '2', 'type' => 'alipay', 'status' => 1, 'money' => 10, 'trade_no' => 'wrong-binding'])]);
        $this->expectException(\RuntimeException::class);
        app(PaymentReconciliationService::class)->sync($o);
    }

    public function test_seo_publish_rename_and_unpublish_have_durable_jobs_and_success_is_checked(): void
    {
        Setting::set('site_url', 'https://shop.example.test');
        Setting::set('baidu_push_token', 'complete-baidu');
        Setting::set('bing_indexnow_key', 'abcdefgh12345678');
        $category = ArticleCategory::create(['name' => '推送分类', 'slug' => 'seo-category']);
        $article = Article::create(['article_category_id' => $category->id, 'title' => '推送测试', 'slug' => 'seo-original', 'content' => 'hello', 'is_published' => true]);
        $this->assertSame(2, SeoDelivery::count());
        $article->update(['slug' => 'seo-renamed']);
        $this->assertTrue(SeoDelivery::where('url', 'https://shop.example.test/articles/seo-original')->count() >= 4);
        $article->update(['is_published' => false]);
        $this->assertSame(8, SeoDelivery::count());
        $this->get('/abcdefgh12345678.txt')->assertOk()->assertSee('abcdefgh12345678');
        $this->get('/wrongkey123.txt')->assertNotFound();
        Http::fake(['data.zz.baidu.com/*' => Http::response(['error' => 401]), 'api.indexnow.org/*' => Http::response('', 200)]);
        app(SeoQueue::class)->process(2);
        $this->assertSame('pending', SeoDelivery::where('provider', 'baidu')->orderBy('id')->first()->status);
        $this->assertSame('sent', SeoDelivery::where('provider', 'indexnow')->orderBy('id')->first()->status);
    }

    public function test_article_product_embeds_are_dynamic_and_do_not_allow_scripts(): void
    {
        $p = $this->product();
        $input = '<p>[[product:'.$p->id.']]</p><script>alert(1)</script>';
        $html = ContentRenderer::toHtml($input, true);
        $this->assertStringContainsString('/product/'.$p->slug, $html);
        $this->assertStringNotContainsString('<script', $html);
        $p->category->update(['is_active' => false]);
        $html = ContentRenderer::toHtml($input, true);
        $this->assertStringNotContainsString('/product/'.$p->slug, $html);
        $this->assertStringNotContainsString('/product/', ContentRenderer::toHtml($input));
    }

    public function test_heartbeat_and_backlog_return_monitorable_exit_status(): void
    {
        $health = app(HeartbeatService::class);
        $health->beat('notifications');
        $health->beat('scheduler');
        $this->assertSame(0, $this->artisan('shop:health'));
        Setting::set('payment_reconciliation_enabled', '1');
        $this->assertSame(1, $this->artisan('shop:health'));
        $health->beat('reconciliation');
        $this->assertSame(0, $this->artisan('shop:health'));
        DB::table('service_heartbeats')->where('name', 'notifications')->update(['last_seen_at' => now()->subMinutes(4)]);
        $this->assertFalse($health->health()['notifications']['healthy']);
        $this->assertSame(1, $this->artisan('shop:health'));
        $health->beat('notifications');
        $this->order($this->product(), ['expires_at' => now()->subMinutes(3)]);
        $this->assertSame(1, $health->health()['overdue_orders']);
        $this->assertSame(1, $this->artisan('shop:health'));
    }

    public function test_staff_read_permission_cannot_mutate_or_download_configuration(): void
    {
        $this->admin(['role' => 'staff', 'permissions' => ['catalog:read', 'maintenance:read']]);
        $this->getJson('/api/admin/products')->assertOk();
        $this->postJson('/api/admin/products', [])->assertForbidden();
        $this->getJson('/api/admin/admins')->assertForbidden();
        $this->getJson('/api/admin/maintenance/backups')->assertForbidden();
        $this->getJson('/api/admin/maintenance/health')->assertOk();
    }

    public function test_owner_self_demotion_and_inactive_accounts_are_blocked(): void
    {
        $admin = $this->admin();
        $longPassword = str_repeat('密', 30);
        $this->postJson('/api/admin/password', ['current_password' => 'complete-password-123', 'new_password' => $longPassword, 'new_password_confirmation' => $longPassword])->assertUnprocessable();
        $this->postJson('/api/admin/admins', ['username' => 'unicode-password', 'password' => str_repeat('密', 30), 'role' => 'staff', 'permissions' => [], 'is_active' => true])->assertUnprocessable();
        $this->putJson('/api/admin/admins/'.$admin->id, ['username' => $admin->username, 'role' => 'staff', 'permissions' => [], 'is_active' => true])->assertUnprocessable();
        $this->assertSame('owner', $admin->fresh()->role);
        $admin->update(['is_active' => false]);
        $this->getJson('/api/admin/products')->assertUnauthorized();
    }

    public function test_token_expiry_scopes_cidr_and_malformed_ip_validation(): void
    {
        $token = $this->token(['expires_at' => now()->subMinute()]);
        $this->withToken('complete-token')->getJson('/api/v1/products')->assertUnauthorized();
        $token->update(['expires_at' => now()->addDay(), 'scopes' => ['orders:query']]);
        $this->withToken('complete-token')->getJson('/api/v1/products')->assertForbidden();
        $token->update(['scopes' => ['products:read'], 'allowed_ips' => ['192.0.2.0/24']]);
        $this->withToken('complete-token')->getJson('/api/v1/products')->assertForbidden();
        $token->update(['allowed_ips' => ['127.0.0.0/8']]);
        $this->withToken('complete-token')->getJson('/api/v1/products')->assertOk();
        $this->admin();
        $this->postJson('/api/admin/api-tokens', ['name' => 'bad-ip', 'allowed_ips' => [['bad']]])->assertUnprocessable();
        $this->postJson('/api/admin/api-tokens', ['name' => 'bad-mask', 'allowed_ips' => ['127.0.0.1/33']])->assertUnprocessable();
    }

    public function test_refund_balance_and_transitions_require_actual_payment_reference(): void
    {
        Setting::set('refund_enabled', '1');
        $o = $this->order($this->product());
        app(OrderFulfilmentService::class)->fulfilFromGateway($o->order_no, 'refund-trade', '10.00', 'epay');
        $service = app(RefundService::class);
        $refund = $service->request($o, '6.00', '退款测试');
        try {
            $service->request($o, '4.01', '超额');
            $this->fail();
        } catch (\RuntimeException) {
        }
        $admin = $this->admin();
        $service->transition($refund, 'approved', null, null, $admin->id);
        try {
            $service->transition($refund, 'completed', null, null, $admin->id);
            $this->fail();
        } catch (\RuntimeException) {
        }
        $service->transition($refund, 'completed', 'bank-reference-test', '实际退款已确认', $admin->id);
        $this->assertSame('completed', $refund->fresh()->status);
        $service->request($o, '4.00', '剩余退款');
        $this->expectException(\RuntimeException::class);
        $service->request($o, '0.01', '重复退款');
    }

    public function test_duplicate_receipt_refund_is_a_separate_budget_and_resolves_review(): void
    {
        Setting::set('refund_enabled', '1');
        $o = $this->order($this->product());
        $f = app(OrderFulfilmentService::class);
        $f->fulfilFromGateway($o->order_no, 'refund-original', '10.00', 'epay');
        $f->fulfilFromGateway($o->order_no, 'refund-extra', '10.00', 'epay');
        $receipt = $o->paymentReceipts()->where('trade_no', 'refund-extra')->first();
        $s = app(RefundService::class);
        $refund = $s->request($o, '10.00', '重复付款退款', $receipt->id);
        $admin = $this->admin();
        $s->transition($refund, 'approved', null, null, $admin->id);
        $s->transition($refund, 'completed', 'actual-refund-extra', null, $admin->id);
        $this->assertNotNull($receipt->fresh()->review_resolved_at);
        $this->assertFalse(Order::paymentReview()->whereKey($o->id)->exists());
        $this->assertNotNull($s->request($o, '10.00', '原订单退款'));
    }

    public function test_buyer_refund_requires_order_ownership(): void
    {
        Setting::set('refund_enabled', '1');
        $o = $this->order($this->product(), ['status' => 'paid']);
        $this->post('/order/refund/'.$o->order_no, ['amount' => 10, 'reason' => 'buyer-refund'])->assertForbidden();
        $this->withBuyerSession($o)->post('/order/refund/'.$o->order_no, ['amount' => 10, 'reason' => 'buyer-refund'])->assertRedirect();
        $this->assertSame('buyer', $o->refunds()->first()->source);
    }

    public function test_asset_quarantine_is_reversible_and_restored_when_later_referenced(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $path = 'uploads/2026/01/'.str_repeat('a', 32).'.png';
        Storage::disk('public')->put($path, 'test-image');
        touch(Storage::disk('public')->path($path), time() - 8 * 86400);
        $s = app(AssetMaintenanceService::class);
        $s->quarantine($path);
        Storage::disk('public')->assertMissing($path);
        $p = $this->product();
        $p->update(['image' => '/storage/'.$path]);
        Storage::disk('public')->assertExists($path);
        Storage::disk('local')->assertMissing('asset-quarantine/'.$path);
        $this->expectException(\RuntimeException::class);
        $s->quarantine($path);
    }

    public function test_backup_manifest_detects_tampering_and_restore_requires_target_confirmation(): void
    {
        $dir = base_path('.local/backup-tests');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $file = $dir.'/'.uniqid('manifest-').'.tar';
        $content = 'fake-dump';
        $archive = new \PharData($file);
        $archive->addFromString('database.dump', $content);
        $archive->addFromString('manifest.json', json_encode(['version' => 1, 'files' => ['database.dump' => ['size' => strlen($content), 'sha256' => hash('sha256', $content)]]]));
        unset($archive);
        $service = app(ShopBackupService::class);
        try {
            $this->assertSame(1, $service->validate($file)['version']);
            $this->assertSame(1, $this->artisan('shop:restore', ['archive' => $file]));
            $archive = new \PharData($file);
            $archive['database.dump'] = 'tampered!';
            unset($archive);
            $this->expectException(\RuntimeException::class);
            $service->validate($file);
        } finally {
            unlink($file);
        }
    }

    public function test_existing_pages_can_be_enqueued_after_seo_configuration(): void
    {
        $p = $this->product();
        $this->assertSame(0, SeoDelivery::count());
        Setting::set('site_url', 'https://shop.example.test');
        Setting::set('bing_indexnow_key', 'abcdefgh12345678');
        $this->admin();
        $this->postJson('/api/admin/seo-deliveries/enqueue')->assertAccepted();
        $this->assertSame(3, SeoDelivery::count());
        $this->assertTrue(SeoDelivery::where('url', 'https://shop.example.test/product/'.$p->slug)->exists());
    }

    public function test_signed_usdt_callback_records_actual_receipt_without_trusting_unsigned_fields(): void
    {
        $o = $this->order($this->product(), ['payment_method' => 'usdt_trc20']);
        Setting::set('epusdt_api_token', 'complete-usdt-token');
        $payload = ['order_id' => $o->order_no, 'trade_id' => 'signed-usdt-complete', 'status' => 2, 'amount' => '10.00', 'actual_amount' => '1.45000001', 'network' => 'TRC20', 'block_transaction_id' => 'signed-hash'];
        ksort($payload);
        $payload['signature'] = md5(urldecode(http_build_query($payload)).'complete-usdt-token');
        $this->postJson('/payment/epusdt/notify', $payload)->assertOk();
        $receipt = $o->paymentReceipts()->firstOrFail();
        $this->assertSame('1.45000001', $receipt->actual_amount);
        $this->assertSame('signed-hash', $receipt->transaction_hash);
        $payload['actual_amount'] = '999.00';
        $this->postJson('/payment/epusdt/notify', $payload)->assertOk();
        $this->assertSame('1.45000001', $receipt->fresh()->actual_amount);
        $this->assertSame(1, $o->paymentReceipts()->count());
    }

    public function test_retention_archives_only_old_finished_operational_records(): void
    {
        Storage::fake('local');
        $o = $this->order($this->product());
        $base = ['type' => 'new_order', 'order_id' => $o->id, 'available_at' => now(), 'created_at' => now()->subDays(400), 'updated_at' => now()];
        NotificationDelivery::create($base + ['dedupe_key' => 'old-done', 'status' => 'sent']);
        NotificationDelivery::create($base + ['dedupe_key' => 'old-pending', 'status' => 'pending']);
        $this->assertSame(0, $this->artisan('shop:archive-records'));
        $this->assertSame(2, NotificationDelivery::count());
        $this->assertSame(0, $this->artisan('shop:archive-records', ['--apply' => true]));
        $this->assertSame(1, NotificationDelivery::count());
        $this->assertTrue(NotificationDelivery::where('dedupe_key', 'old-pending')->exists());
        $this->assertSame(1, Order::count());
        $this->assertNotEmpty(Storage::disk('local')->allFiles('record-archives'));
    }
}
