<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, ApiToken, Article, ArticleCategory, Card, Category, Coupon, Order, OrderRefund, PaymentReceipt, Product, Setting};
use App\Services\{OrderFulfilmentService, OrderService, RefundService, StockAlertService};
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FullFunctionAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function product(int $stock = 4): Product
    {
        $category = Category::create(['name' => '全量检查分类', 'slug' => uniqid('audit-cat-'), 'is_active' => true]);
        $p = Product::create(['name' => '全量检查商品', 'slug' => uniqid('audit-product-'), 'category_id' => $category->id, 'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true]);
        for ($i = 0; $i < $stock; $i++) {
            Card::create(['product_id' => $p->id, 'content' => 'audit-secret-'.$i, 'status' => 'unsold']);
        }
        return $p;
    }

    private function order(Product $p, array $extra = []): Order
    {
        return app(OrderService::class)->createOrder($extra + ['product_id' => $p->id, 'quantity' => 1,
            'email' => 'audit@example.test', 'query_password' => 'audit-password', 'payment_method' => 'alipay', 'ip' => '192.0.2.80']);
    }

    private function admin(array $extra = []): Admin
    {
        $a = Admin::create($extra + ['username' => uniqid('audit-admin-'), 'password' => 'audit-password-123', 'role' => 'owner', 'is_active' => true]);
        $this->withSession(['admin_id' => $a->id, 'admin_pw' => AdminAuth::passwordFingerprint($a->password)]);
        return $a;
    }

    private function receipt(Order $o, string $trade): PaymentReceipt
    {
        return PaymentReceipt::create(['order_id' => $o->id, 'channel' => 'epay', 'trade_no' => $trade, 'amount' => '10.00', 'received_at' => now()]);
    }

    public function test_coupon_overflow_and_subcent_inputs_are_validation_errors_on_create_and_update(): void
    {
        $this->admin();
        foreach (['value' => '100000000.00', 'min_amount' => '100000000.00', 'max_uses' => '2147483648'] as $field => $value) {
            $this->postJson('/api/admin/coupons', [$field => $value] + ['type' => 'fixed', 'value' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $coupon = Coupon::create(['code' => 'AUDITSAFE', 'type' => 'fixed', 'value' => '1.00', 'is_active' => true]);
        $this->putJson('/api/admin/coupons/'.$coupon->id, ['type' => 'fixed', 'value' => '1.001'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->putJson('/api/admin/coupons/'.$coupon->id, ['type' => 'fixed', 'value' => '1.00', 'min_amount' => '100000000.00'])->assertUnprocessable()->assertJsonValidationErrors('min_amount');
        $this->assertSame('1.00', $coupon->fresh()->value);
    }

    public function test_checkout_quote_uses_actual_tiers_and_coupons_without_reserving_any_resources(): void
    {
        $p = $this->product();
        $p->wholesalePrices()->create(['min_quantity' => 2, 'price' => '8.00']);
        $coupon = Coupon::create(['code' => 'AUDITQUOTE', 'type' => 'fixed', 'value' => '5.00', 'max_uses' => 1, 'is_active' => true]);
        $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 2, 'coupon_code' => $coupon->code, 'total_amount' => '0.00'])
            ->assertOk()->assertJsonPath('data.unit_price', '8.00')->assertJsonPath('data.subtotal', '16.00')
            ->assertJsonPath('data.discount_amount', '5.00')->assertJsonPath('data.total_amount', '11.00')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(4, $p->stockCount());
        $this->assertSame(0, Order::count());
        $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 2, 'coupon_code' => 'BAD'])->assertUnprocessable();
        $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 5])->assertUnprocessable();
        $p->category->update(['is_active' => false]);
        $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 1])->assertNotFound();
    }

    public function test_buyer_cancel_requires_ownership_and_releases_coupon_and_inventory_exactly_once(): void
    {
        $p = $this->product();
        $c = Coupon::create(['code' => 'AUDITCANCEL', 'type' => 'fixed', 'value' => '2.00', 'max_uses' => 1, 'is_active' => true]);
        $o = $this->order($p, ['coupon_code' => $c->code]);
        $this->post('/order/cancel/'.$o->order_no)->assertForbidden();
        $this->withSession(['order_verified_ids' => [$o->id]]);
        foreach (['default', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme);
            $this->get('/order/detail/'.$o->order_no)->assertOk()->assertSee('/order/cancel/'.$o->order_no);
        }
        $this->post('/order/cancel/'.$o->order_no)->assertRedirect('/order/detail/'.$o->order_no);
        $this->assertSame('closed', $o->fresh()->status);
        $this->assertSame(4, $p->stockCount());
        $this->assertSame(0, $c->fresh()->used_count);
        $this->post('/order/cancel/'.$o->order_no)->assertSessionHasErrors('error');
        $this->assertSame(0, $c->fresh()->used_count);
    }

    public function test_paid_and_receipt_review_orders_cannot_be_cancelled(): void
    {
        $p = $this->product();
        $o = $this->order($p);
        $o->update(['payment_no' => 'audit-awaiting-review']);
        $this->receipt($o, 'audit-awaiting-review');
        $this->withSession(['order_verified_ids' => [$o->id]])->post('/order/cancel/'.$o->order_no)->assertSessionHasErrors('error');
        $this->admin();
        $this->postJson('/api/admin/orders/'.$o->id.'/close')->assertUnprocessable();
        $this->assertSame('pending', $o->fresh()->status);
        $this->assertSame(3, $p->stockCount());
        $paid = $this->order($p);
        app(OrderFulfilmentService::class)->fulfilFromGateway($paid->order_no, 'audit-paid', '10.00', 'epay');
        $this->withSession(['order_verified_ids' => [$paid->id]])->post('/order/cancel/'.$paid->order_no)->assertSessionHasErrors('error');
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame(1, $paid->cards()->where('status', 'sold')->count());
    }

    public function test_api_cancel_checks_scope_body_credentials_and_token_ownership(): void
    {
        $token = ApiToken::create(['name' => 'audit-api', 'token' => hash('sha256', 'audit-api'), 'is_active' => true, 'scopes' => ['orders:create', 'orders:query']]);
        $o = $this->order($this->product(), ['api_token_id' => $token->id]);
        $path = '/api/v1/orders/'.$o->order_no.'/cancel';
        $data = ['email' => $o->email, 'query_password' => 'audit-password'];
        $this->withToken('audit-api')->postJson($path, $data)->assertForbidden();
        $token->update(['scopes' => ['orders:cancel']]);
        $this->withToken('audit-api')->postJson($path.'?email=audit@example.test&query_password=audit-password', [])->assertUnprocessable();
        $this->withToken('audit-api')->postJson($path, ['query_password' => 'wrong-password'] + $data)->assertNotFound();
        $other = ApiToken::create(['name' => 'other', 'token' => hash('sha256', 'other-audit'), 'is_active' => true, 'scopes' => ['orders:cancel']]);
        $this->withToken('other-audit')->postJson($path, $data)->assertNotFound();
        $this->withToken('audit-api')->postJson($path, $data)->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame('closed', $o->fresh()->status);
    }

    public function test_legacy_refunds_still_consume_primary_balance_when_receipt_is_added(): void
    {
        $o = $this->order($this->product());
        $o->update(['status' => 'paid']);
        $service = app(RefundService::class);
        $service->request($o, '6.00', 'legacy primary refund');
        $o->update(['payment_no' => 'audit-late-receipt']);
        $main = $this->receipt($o, 'audit-late-receipt');
        $service->request($o, '4.00', 'remaining primary balance', $main->id);
        $extra = $this->receipt($o, 'audit-extra-receipt');
        $this->assertSame('10.00', $service->request($o, '10.00', 'separate extra receipt', $extra->id)->amount);
        $this->expectException(\RuntimeException::class);
        $service->request($o, '0.01', 'must not refund more than the primary payment', $main->id);
    }

    public function test_dashboard_refunds_and_net_sales_do_not_deduct_unrecorded_extra_payment_income(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-01 10:00:00'));
        $p = $this->product();
        $o = $this->order($p);
        app(OrderFulfilmentService::class)->fulfilFromGateway($o->order_no, 'audit-dashboard', '10.00', 'epay');
        $o->refresh();
        $main = $o->paymentReceipts()->firstOrFail();
        $extra = $this->receipt($o, 'audit-dashboard-extra');
        foreach ([[$main->id, '4.00', now()], [$main->id, '1.00', now()->subDay()], [$extra->id, '10.00', now()]] as [$receiptId, $amount, $date]) {
            OrderRefund::create(['order_id' => $o->id, 'payment_receipt_id' => $receiptId, 'amount' => $amount, 'status' => 'completed', 'source' => 'admin', 'reason' => 'audit', 'reference' => 'TEST-ONLY', 'completed_at' => $date]);
        }
        foreach (['requested', 'approved'] as $status) {
            OrderRefund::create(['order_id' => $o->id, 'payment_receipt_id' => $main->id, 'amount' => '1.00', 'status' => $status, 'source' => 'admin', 'reason' => 'audit']);
        }
        $p->category->update(['is_active' => false]);
        $this->admin();
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('today_revenue', 10)
            ->assertJsonPath('today_refund_amount', 14)->assertJsonPath('total_refund_amount', 15)
            ->assertJsonPath('today_net_revenue', 6)->assertJsonPath('total_net_revenue', 5)
            ->assertJsonPath('requested_refunds', 1)->assertJsonPath('approved_refunds', 1)->assertJsonPath('total_products', 0);
    }

    public function test_content_editor_can_select_products_without_catalog_access_or_card_secrets(): void
    {
        $p = $this->product();
        $this->admin(['role' => 'staff', 'permissions' => ['content:write']]);
        $this->getJson('/api/admin/products')->assertForbidden();
        $this->getJson('/api/admin/articles/product-options?q=全量检查')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p->id)->assertJsonMissingPath('data.0.price')->assertJsonMissingPath('data.0.cards');
        $p->category->update(['is_active' => false]);
        $this->getJson('/api/admin/articles/product-options')->assertJsonCount(0, 'data');
        $this->admin(['role' => 'staff', 'permissions' => ['orders:read']]);
        $this->getJson('/api/admin/articles/product-options')->assertForbidden();
    }

    public function test_published_article_categories_are_indexed_and_disabled_stock_alerts_reset(): void
    {
        $cat = ArticleCategory::create(['name' => '文章分类', 'slug' => 'audit-articles']);
        $empty = ArticleCategory::create(['name' => '空分类', 'slug' => 'audit-empty-articles']);
        Article::create(['article_category_id' => $cat->id, 'title' => '检查文章', 'slug' => 'audit-article', 'content' => 'test', 'is_published' => true]);
        $this->get('/sitemap.xml')->assertOk()->assertSee('/articles/category/'.$cat->slug)->assertDontSee('/articles/category/'.$empty->slug);
        $p = $this->product(0);
        $p->update(['low_stock_threshold' => 3]);
        $p->forceFill(['low_stock_notified' => true])->save();
        $p->category->update(['is_active' => false]);
        $this->assertFalse(app(StockAlertService::class)->check($p->id));
        $this->assertFalse($p->fresh()->low_stock_notified);
    }

    public function test_usdt_network_choices_and_checkout_match_the_gateway_capabilities(): void
    {
        $p = $this->product();
        Setting::set('epusdt_api_url', 'https://audit-usdt.example.test');
        Setting::set('epusdt_api_token', 'audit-usdt-key');
        Setting::set('usdt_gateway', 'epusdt');
        foreach (['default', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme);
            $this->get('/product/'.$p->slug)->assertOk()->assertSee('value="usdt_trc20"', false)->assertDontSee('value="usdt_bep20"', false)->assertDontSee('value="usdt_polygon"', false);
        }
        $data = ['product_id' => $p->id, 'quantity' => 1, 'email' => 'audit@example.test', 'query_password' => 'audit-password', 'payment_method' => 'usdt_bep20'];
        $this->post('/order/create', $data)->assertSessionHasErrors('payment_method');
        ApiToken::create(['name' => 'audit-chain', 'token' => hash('sha256', 'audit-chain'), 'is_active' => true]);
        $this->withToken('audit-chain')->postJson('/api/v1/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, Order::count());
        $this->assertSame(4, $p->stockCount());
        Setting::set('usdt_gateway', 'bepusdt');
        $this->get('/product/'.$p->slug)->assertSee('value="usdt_bep20"', false)->assertSee('value="usdt_polygon"', false);
    }

    public function test_bepusdt_api_order_passes_the_selected_network_to_the_gateway(): void
    {
        $p = $this->product();
        Setting::set('epusdt_api_url', 'https://audit-usdt.example.test');
        Setting::set('epusdt_api_token', 'audit-usdt-key');
        Setting::set('usdt_gateway', 'bepusdt');
        ApiToken::create(['name' => 'audit-chain', 'token' => hash('sha256', 'audit-chain'), 'is_active' => true]);
        $data = ['product_id' => $p->id, 'quantity' => 1, 'email' => 'audit@example.test', 'query_password' => 'audit-password', 'payment_method' => 'usdt_bep20'];
        Http::fake(['audit-usdt.example.test/*' => Http::response(['status_code' => 200, 'data' => ['trade_id' => 'audit-chain-trade', 'payment_url' => 'https://audit-usdt.example.test/pay/test']])]);
        $this->withToken('audit-chain')->postJson('/api/v1/orders', $data)->assertCreated();
        Http::assertSent(fn ($r) => $r['trade_type'] === 'usdt.bep20');
    }
}
