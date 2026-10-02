<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, ApiToken, ArticleCategory, Blacklist, Card, Category, Coupon, Order, Product, Setting};
use App\Services\{NotificationService, OrderFulfilmentService, OrderService};
use Illuminate\Support\Facades\{Cache, Hash, Http};
use Tests\TestCase;

class ShopFlowsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('sendOrderEmail')->willReturn(true);
        $this->instance(NotificationService::class, $notifications);
        Http::preventStrayRequests();
    }

    private function product(int $stock = 5): Product
    {
        $category = Category::create(['name' => '测试分类', 'slug' => uniqid('category-'), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => '测试商品', 'slug' => uniqid('product-'), 'price' => '10.00', 'is_active' => true]);
        for ($i = 0; $i < $stock; $i++) {
            Card::create(['product_id' => $product->id, 'content' => "secret-$i", 'status' => 'unsold']);
        }
        return $product;
    }

    private function admin(): void
    {
        $admin = Admin::create(['username' => 'test-admin', 'password' => Hash::make('test-password-123')]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
    }

    private function data(Product $product, array $changes = []): array
    {
        return array_replace(['product_id' => $product->id, 'quantity' => 1, 'email' => 'buyer@example.test', 'query_password' => 'buyer-password', 'payment_method' => 'alipay', 'ip' => '192.0.2.20'], $changes);
    }

    private function order(Product $product, array $changes = []): Order
    {
        return app(OrderService::class)->createOrder($this->data($product, $changes));
    }

    private function epay(): void
    {
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1', 'payment');
        Setting::set('epay_merchant_key', 'test-key', 'payment');
    }

    public function test_checkout_locks_stock_and_applies_wholesale_price(): void
    {
        $product = $this->product();
        $product->wholesalePrices()->create(['min_quantity' => 2, 'price' => '8.50']);
        $this->epay();
        $this->post('/order/create', $this->data($product, ['quantity' => 2]))->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame('17.00', $order->total_amount);
        $this->assertSame(2, $order->cards()->where('status', 'locked')->count());
        $this->assertSame(3, $product->stockCount());
        $this->assertContains($order->id, session('order_verified_ids'));
    }

    public function test_invalid_quantity_does_not_reserve_stock(): void
    {
        $product = $this->product();
        $this->from('/product/'.$product->slug)->post('/order/create', $this->data($product, ['quantity' => 11]))->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
        $this->assertSame(5, $product->stockCount());
    }

    public function test_stock_shortage_does_not_create_an_order(): void
    {
        $product = $this->product(0);
        $this->post('/order/create', $this->data($product))->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
    }

    public function test_coupon_minimum_rejects_without_reserving_stock(): void
    {
        $product = $this->product();
        Coupon::create(['code' => 'MINIMUM', 'type' => 'fixed', 'value' => 5, 'min_amount' => 100, 'is_active' => true]);
        $this->post('/order/create', $this->data($product, ['coupon_code' => 'MINIMUM']))->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
    }

    public function test_valid_payment_fulfils_once_and_preserves_stock(): void
    {
        $order = $this->order($this->product(), ['quantity' => 2]);
        $service = app(OrderFulfilmentService::class);
        $this->assertTrue($service->fulfilFromGateway($order->order_no, 'trade-1', '20.00', 'epay')->wasFulfilled());
        $this->assertFalse($service->fulfilFromGateway($order->order_no, 'trade-1', '20.00', 'epay')->wasFulfilled());
        $this->assertSame(2, $order->cards()->where('status', 'sold')->count());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_one_cent_underpayment_is_rejected(): void
    {
        $order = $this->order($this->product());
        $this->assertFalse(app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'trade-1', '9.99', 'epay')->wasFulfilled());
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_zero_payment_cannot_buy_a_one_cent_order(): void
    {
        $product = $this->product();
        $product->update(['price' => '0.01']);
        $order = $this->order($product);
        $this->assertFalse(app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'trade-1', '0.00', 'epay')->wasFulfilled());
    }

    public function test_wrong_payment_channel_is_rejected(): void
    {
        $order = $this->order($this->product());
        $this->assertFalse(app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'trade-1', '10.00', 'epusdt')->wasFulfilled());
    }

    public function test_unsigned_payment_callback_does_not_deliver(): void
    {
        $order = $this->order($this->product());
        $this->epay();
        $this->get('/payment/epay/notify?'.http_build_query(['out_trade_no' => $order->order_no, 'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS', 'sign' => 'invalid']))->assertOk();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_expiry_releases_cards_and_coupon_once(): void
    {
        $product = $this->product();
        $coupon = Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 1, 'max_uses' => 1, 'is_active' => true]);
        $order = $this->order($product, ['coupon_code' => 'ONCE']);
        $order->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(OrderService::class)->expireOrders());
        $this->assertSame(0, app(OrderService::class)->expireOrders());
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(5, $product->stockCount());
    }

    public function test_closing_expired_order_does_not_release_another_orders_coupon(): void
    {
        $product = $this->product();
        $coupon = Coupon::create(['code' => 'SHARED', 'type' => 'fixed', 'value' => 1, 'max_uses' => 1, 'is_active' => true]);
        $first = $this->order($product, ['coupon_code' => 'SHARED']);
        $first->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireOrders();
        $this->order($product, ['coupon_code' => 'SHARED']);
        app(OrderService::class)->closeOrder($first->fresh());
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_paid_card_secrets_require_buyer_verification(): void
    {
        $order = $this->order($this->product());
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $this->get('/order/detail/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertRedirect('/order/query');
        $this->postJson('/order/verify', ['email' => $order->email, 'query_password' => 'buyer-password'])->assertJsonPath('success', true);
        $this->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('secret-0');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertOk()->assertSee('secret-0');
    }

    public function test_failed_api_payment_does_not_leave_locked_stock(): void
    {
        $product = $this->product();
        ApiToken::create(['name' => 'test', 'token' => hash('sha256', 'test-api-token'), 'is_active' => true]);
        $this->withToken('test-api-token')->postJson('/api/v1/orders', $this->data($product, ['payment_method' => 'usdt_trc20']))->assertStatus(422);
        $this->assertSame(0, Card::where('status', 'locked')->count());
        $this->assertSame(0, Order::where('status', 'pending')->count());
    }

    public function test_usdt_checkout_link_is_reused_from_another_session(): void
    {
        $product = $this->product();
        Setting::set('epusdt_api_url', 'https://usdt.example.test', 'payment');
        Setting::set('epusdt_api_token', 'test-token', 'payment');
        Http::fake(['usdt.example.test/*' => Http::response(['status_code' => 200, 'data' => ['payment_url' => 'https://usdt.example.test/pay/1', 'trade_id' => '1']])]);
        $this->post('/order/create', $this->data($product, ['payment_method' => 'usdt_trc20']))->assertRedirect('https://usdt.example.test/pay/1');
        $order = Order::firstOrFail();
        $this->app['session']->flush();
        $this->get('/order/pay/'.$order->order_no)->assertOk()->assertSee('https://usdt.example.test/pay/1');
        Http::assertSentCount(1);
    }

    public function test_admin_card_import_preserves_zero_and_validates_content_type(): void
    {
        $product = $this->product(0);
        $this->admin();
        $this->postJson('/api/admin/products/'.$product->id.'/cards/import', ['content' => "0\r\nvalid\r\n"])->assertOk()->assertJsonPath('count', 2);
        $this->assertDatabaseHas('cards', ['product_id' => $product->id,
            'content_fingerprint' => app(\App\Security\SecretCipher::class)->fingerprint('0')]);
        $this->assertSame('0', $product->cards()->where('content', '0')->firstOrFail()->content);
        $this->postJson('/api/admin/products/'.$product->id.'/cards/import', ['content' => ['invalid']])->assertUnprocessable();
    }

    public function test_blacklist_add_and_delete_take_effect_immediately(): void
    {
        $this->admin();
        $this->get('/')->assertOk();
        $response = $this->postJson('/api/admin/blacklists', ['type' => 'ip', 'value' => '127.0.0.1'])->assertCreated();
        $this->get('/')->assertForbidden();
        $this->deleteJson('/api/admin/blacklists/'.$response->json('id'))->assertOk();
        $this->get('/')->assertOk();
    }

    public function test_coupon_minimum_and_start_time_can_be_saved(): void
    {
        $this->admin();
        $response = $this->postJson('/api/admin/coupons', ['code' => 'SCHEDULED', 'type' => 'fixed', 'value' => 5, 'min_amount' => 100, 'starts_at' => '2030-01-01 00:00:00', 'expires_at' => '2030-02-01 00:00:00', 'is_active' => true])->assertCreated();
        $this->assertSame('100.00', $response->json('min_amount'));
        $this->assertNotNull($response->json('starts_at'));
    }

    public function test_coupon_cannot_expire_before_start(): void
    {
        $this->admin();
        $this->postJson('/api/admin/coupons', ['code' => 'INVALID-DATE', 'type' => 'fixed', 'value' => 1, 'starts_at' => '2030-02-01', 'expires_at' => '2030-01-01'])->assertUnprocessable();
    }

    public function test_product_quantity_limits_validate_against_existing_values(): void
    {
        $product = $this->product();
        $product->update(['min_quantity' => 5, 'max_quantity' => 10]);
        $this->admin();
        $this->putJson('/api/admin/products/'.$product->id, ['name' => $product->name, 'category_id' => $product->category_id, 'price' => 10, 'max_quantity' => 2])->assertUnprocessable();
    }

    public function test_null_optional_product_limits_use_defaults(): void
    {
        $product = $this->product();
        $this->admin();
        $this->postJson('/api/admin/products', ['name' => '默认限购', 'category_id' => $product->category_id, 'price' => 10, 'min_quantity' => null, 'max_quantity' => null])->assertCreated()->assertJsonPath('min_quantity', 1)->assertJsonPath('max_quantity', 10);
    }

    public function test_sold_cards_cannot_be_relisted_or_deleted(): void
    {
        $order = $this->order($this->product());
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $card = $order->cards()->firstOrFail();
        $this->admin();
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'unsold'])->assertUnprocessable();
        $this->deleteJson('/api/admin/cards/'.$card->id)->assertUnprocessable();
    }

    public function test_admin_manual_payment_and_close_protect_completed_orders(): void
    {
        $order = $this->order($this->product());
        $this->admin();
        $this->postJson('/api/admin/orders/'.$order->id.'/paid')->assertOk();
        $this->postJson('/api/admin/orders/'.$order->id.'/close')->assertUnprocessable();
        $this->postJson('/api/admin/orders/'.$order->id.'/paid')->assertUnprocessable();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_admin_article_and_category_lifecycle(): void
    {
        $this->admin();
        $category = $this->postJson('/api/admin/article-categories', ['name' => '测试文章分类'])->assertCreated()->json();
        $article = $this->postJson('/api/admin/articles', ['title' => '测试文章', 'article_category_id' => $category['id'], 'content' => '<p>hello</p>', 'is_published' => true])->assertCreated()->json();
        $this->get('/articles/'.$article['slug'])->assertOk()->assertSee('hello');
        $this->deleteJson('/api/admin/article-categories/'.$category['id'])->assertUnprocessable();
        $this->deleteJson('/api/admin/articles/'.$article['id'])->assertOk();
        $this->deleteJson('/api/admin/article-categories/'.$category['id'])->assertOk();
    }

    public function test_settings_masked_secret_is_preserved_on_update(): void
    {
        Setting::set('epay_merchant_key', 'saved-secret', 'payment');
        $this->admin();
        $this->getJson('/api/admin/settings')->assertJsonPath('epay_merchant_key', '********');
        $this->postJson('/api/admin/settings', ['epay_merchant_key' => '********', 'site_name' => '新店铺'])->assertOk();
        $this->assertSame('saved-secret', setting('epay_merchant_key'));
        $this->assertSame('新店铺', setting('site_name'));
    }

    public function test_admin_lists_and_order_export_work(): void
    {
        $order = $this->order($this->product());
        $this->admin();
        foreach (['dashboard', 'categories', 'products', 'orders', 'articles', 'article-categories', 'coupons', 'blacklists', 'logs', 'settings'] as $endpoint) {
            $this->getJson('/api/admin/'.$endpoint)->assertOk();
        }
        $response = $this->get('/api/admin/orders/export?status=pending')->assertOk();
        $this->assertStringContainsString($order->order_no, $response->streamedContent());
    }

    public function test_signed_epay_callback_delivers_and_is_idempotent(): void
    {
        $order = $this->order($this->product());
        $this->epay();
        $params = ['pid' => '1', 'out_trade_no' => $order->order_no, 'trade_no' => 'signed-trade', 'trade_status' => 'TRADE_SUCCESS', 'money' => '10.00'];
        ksort($params);
        $params['sign'] = md5(urldecode(http_build_query($params)).'test-key');
        foreach ([1, 2] as $attempt) {
            $this->get('/payment/epay/notify?'.http_build_query($params))->assertOk()->assertSee('success');
        }
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->cards()->where('status', 'sold')->count());
    }

    public function test_signed_usdt_callback_delivers_only_successful_payments(): void
    {
        $order = $this->order($this->product(), ['payment_method' => 'usdt_trc20']);
        Setting::set('epusdt_api_token', 'test-token', 'payment');
        $params = ['order_id' => $order->order_no, 'trade_id' => 'usdt-trade', 'status' => 1, 'amount' => '10.00', 'actual_amount' => '1.45'];
        foreach ([1, 2] as $status) {
            $params['status'] = $status;
            unset($params['signature']);
            ksort($params);
            $params['signature'] = md5(urldecode(http_build_query($params)).'test-token');
            $this->postJson('/payment/epusdt/notify', $params)->assertOk();
            $this->assertSame($status === 1 ? 'pending' : 'paid', $order->fresh()->status);
        }
    }

    public function test_late_payment_reallocates_available_stock(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $order->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireOrders();
        $this->assertTrue(app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'late-trade', '10.00', 'epay')->wasFulfilled());
        $this->assertSame(4, $product->stockCount());
        $this->assertSame(1, $order->cards()->where('status', 'sold')->count());
    }

    public function test_overdue_pending_order_password_still_works_before_scheduler(): void
    {
        $order = $this->order($this->product());
        $order->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/order/verify', ['email' => $order->email, 'query_password' => 'buyer-password'])->assertJsonPath('success', true);
        $this->get('/order/detail/'.$order->order_no)->assertOk();
        $this->assertSame('expired', $order->fresh()->status);
    }

    public function test_usdt_gateway_failure_releases_api_stock_and_coupon(): void
    {
        $product = $this->product();
        $coupon = Coupon::create(['code' => 'API-COUPON', 'type' => 'fixed', 'value' => 1, 'max_uses' => 1, 'is_active' => true]);
        ApiToken::create(['name' => 'test', 'token' => hash('sha256', 'test-api-token'), 'is_active' => true]);
        Setting::set('epusdt_api_url', 'https://usdt.example.test', 'payment');
        Setting::set('epusdt_api_token', 'test-token', 'payment');
        Http::fake(['usdt.example.test/*' => Http::response(['status_code' => 500, 'message' => '网关无法创建交易'])]);
        $this->withToken('test-api-token')->postJson('/api/v1/orders', $this->data($product, ['payment_method' => 'usdt_trc20', 'coupon_code' => 'API-COUPON']))->assertUnprocessable();
        $this->assertSame(5, $product->stockCount());
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_locked_stock_cannot_be_deleted_or_changed(): void
    {
        $order = $this->order($this->product());
        $card = $order->cards()->firstOrFail();
        $this->admin();
        $this->deleteJson('/api/admin/cards/'.$card->id)->assertUnprocessable();
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'sold'])->assertUnprocessable();
        $this->deleteJson('/api/admin/cards/batch-destroy', ['ids' => [$card->id]])->assertJsonPath('count', 0);
    }

    public function test_product_wholesale_prices_can_be_changed_and_cleared(): void
    {
        $product = $this->product();
        $this->admin();
        $data = ['name' => $product->name, 'category_id' => $product->category_id, 'price' => 10, 'wholesale_prices' => [['min_quantity' => 2, 'price' => 8]]];
        $this->putJson('/api/admin/products/'.$product->id, $data)->assertOk();
        $this->getJson('/api/admin/products/'.$product->id)->assertJsonPath('wholesale_prices.0.price', '8.00');
        $data['wholesale_prices'] = [];
        $this->putJson('/api/admin/products/'.$product->id, $data)->assertOk();
        $this->assertSame(0, $product->wholesalePrices()->count());
    }

    public function test_duplicate_wholesale_quantities_are_rejected_atomically(): void
    {
        $product = $this->product();
        $this->admin();
        $data = ['name' => '重复阶梯', 'category_id' => $product->category_id, 'price' => 10, 'wholesale_prices' => [['min_quantity' => 2, 'price' => 8], ['min_quantity' => 2, 'price' => 7]]];
        $this->postJson('/api/admin/products', $data)->assertUnprocessable();
        $this->assertSame(1, Product::count());
    }

    public function test_new_coupon_start_cannot_exceed_existing_expiry(): void
    {
        $coupon = Coupon::create(['code' => 'EXISTING', 'type' => 'fixed', 'value' => 1, 'expires_at' => '2030-01-01']);
        $this->admin();
        $this->putJson('/api/admin/coupons/'.$coupon->id, ['type' => 'fixed', 'value' => 1, 'starts_at' => '2030-02-01'])->assertUnprocessable();
    }

    public function test_admin_product_with_orders_cannot_be_deleted(): void
    {
        $product = $this->product();
        $this->order($product);
        $this->admin();
        $this->deleteJson('/api/admin/products/'.$product->id)->assertUnprocessable();
        $this->assertSame(1, Order::count());
        $this->assertSame(5, Card::count());
    }

    public function test_email_blacklist_cache_is_invalidated_after_removal(): void
    {
        $this->admin();
        $id = $this->postJson('/api/admin/blacklists', ['type' => 'email', 'value' => 'Buyer@Example.Test'])->assertCreated()->json('id');
        $this->post('/order/verify', ['email' => 'buyer@example.test', 'query_password' => 'test-password'])->assertForbidden();
        $this->post('/order/query', ['email' => 'buyer@example.test', 'query_password' => 'test-password'])->assertForbidden();
        $this->deleteJson('/api/admin/blacklists/'.$id)->assertOk();
        $this->post('/order/query', ['email' => 'buyer@example.test', 'query_password' => 'test-password'])->assertRedirect();
    }

    public function test_invalid_settings_payload_returns_validation_error(): void
    {
        $this->admin();
        $this->postJson('/api/admin/settings', ['site_name' => ['invalid']])->assertUnprocessable();
        $this->postJson('/api/admin/settings', ['mail_port' => 0])->assertUnprocessable();
    }
}
