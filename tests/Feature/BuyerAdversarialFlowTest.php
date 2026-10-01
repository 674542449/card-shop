<?php

namespace Tests\Feature;

use App\Models\{ApiToken, Card, Category, Coupon, NotificationDelivery, Order, Product, Setting};
use App\Services\{EpayService, OrderFulfilmentService, OrderService};
use Illuminate\Support\Facades\{Http, RateLimiter};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerAdversarialFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['epay_api_url' => 'https://gateway.example.test', 'epay_merchant_id' => 'buyer-test', 'epay_merchant_key' => 'buyer-test-key', 'epusdt_api_token' => 'buyer-usdt-key'] as $key => $value) {
            Setting::set($key, $value);
        }
        ApiToken::create(['name' => 'Buyer test', 'token' => hash('sha256', 'buyer-test-token'), 'is_active' => true]);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Buyer flow', 'slug' => 'buyer-flow', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Buyer flow', 'slug' => 'buyer-flow', 'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 8, 'is_active' => true]);
        for ($i = 0; $i < 8; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'buyer-secret-'.$i, 'status' => 'unsold']);
        }
        return $product;
    }

    private function data(Product $product, array $changes = []): array
    {
        return array_replace(['product_id' => $product->id, 'quantity' => 1, 'email' => 'buyer@example.test', 'query_password' => 'public-buyer-password', 'payment_method' => 'alipay', 'ip' => '192.0.2.40'], $changes);
    }

    private function signature(array $params, string $key = 'buyer-test-key'): string
    {
        unset($params['sign'], $params['sign_type'], $params['signature']);
        ksort($params);
        return md5(implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($params), array_values($params))).$key);
    }

    private function notify(Order $order, string $amount, array $changes = [])
    {
        $params = array_replace(['pid' => 'buyer-test', 'out_trade_no' => $order->order_no, 'trade_no' => 'buyer-trade-'.$order->id, 'type' => 'alipay', 'trade_status' => 'TRADE_SUCCESS', 'money' => $amount], $changes);
        $params['sign'] = $this->signature($params);
        return $this->post('/payment/epay/notify', $params);
    }

    public static function malformedInputs(): array
    {
        $cases = [
            'zero quantity' => ['quantity' => 0], 'negative quantity' => ['quantity' => -1],
            'fraction quantity' => ['quantity' => '1.5'], 'scientific quantity' => ['quantity' => '1e2'],
            'huge quantity' => ['quantity' => PHP_INT_MAX], 'array quantity' => ['quantity' => ['1']],
            'SQL product id' => ['product_id' => '1 OR 1=1'], 'array product id' => ['product_id' => ['1']],
            'array email' => ['email' => ['buyer@example.test']], 'array password' => ['query_password' => ['password']],
            'array coupon' => ['coupon_code' => ['FREE']], 'array method' => ['payment_method' => ['alipay']],
            'SQL email' => ['email' => "buyer@example.test' OR '1'='1"],
        ];
        $out = [];
        foreach (['web', 'api'] as $channel) {
            foreach ($cases as $name => $changes) { $out[$channel.' '.$name] = [$channel, $changes]; }
        }
        return $out;
    }

    #[DataProvider('malformedInputs')]
    public function test_malformed_inputs_never_create_orders_or_reserve_inventory(string $channel, array $changes): void
    {
        $p = $this->product();
        $this->withToken('buyer-test-token');
        $response = $this->postJson($channel === 'api' ? '/api/v1/orders' : '/order/create', $this->data($p, $changes));
        $this->assertContains($response->status(), [302, 422]);
        $this->assertSame(0, Order::count());
        $this->assertSame(8, $p->stockCount());
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_client_order_state_price_and_ownership_fields_are_ignored(): void
    {
        $p = $this->product();
        $changes = ['total_amount' => '0', 'unit_price' => '-1', 'discount_amount' => '999999', 'status' => 'paid', 'payment_no' => 'FORGED', 'paid_at' => now()->toIso8601String(), 'api_token_id' => 9999, 'order_no' => 'FORGED', 'cards' => ['forged']];
        $this->post('/order/create', $this->data($p, $changes))->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('10.00', $order->total_amount);
        $this->assertSame('10.00', $order->unit_price);
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->payment_no);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->api_token_id);
        $this->assertNotSame('FORGED', $order->order_no);
        $this->get('/order/detail/'.$order->order_no)->assertOk()->assertDontSee('buyer-secret-');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertRedirect('/order/detail/'.$order->order_no)->assertSessionHasErrors('error');
        $this->postJson('/api/admin/orders/'.$order->id.'/paid')->assertUnauthorized();
        $this->notify($order, '0.00')->assertOk();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(0, $order->paymentReceipts()->count());
    }

    public function test_sql_and_xss_coupon_inputs_do_not_match_real_discount_codes(): void
    {
        $p = $this->product();
        $coupon = Coupon::create(['code' => 'REALFREE', 'type' => 'percent', 'value' => '100.00', 'is_active' => true]);
        foreach (["' OR 1=1 --", "REALFREE'--", '"><svg onload=this.id=\'xss-fired\'>'] as $input) {
            $this->post('/order/create', $this->data($p, ['coupon_code' => $input]))->assertSessionHasErrors('error');
            $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 1, 'coupon_code' => $input])->assertUnprocessable();
        }
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(0, Order::count());
        $this->assertSame(8, $p->stockCount());
    }

    public function test_query_password_is_not_flashed_after_validation_or_business_errors(): void
    {
        $p = $this->product();
        $secret = 'public-test-password-do-not-flash';
        $this->from('/product/'.$p->slug)->post('/order/create', $this->data($p, ['quantity' => 0, 'query_password' => $secret]))
            ->assertRedirect()->assertSessionHasErrors('quantity')->assertSessionMissing('_old_input.query_password');
        $this->post('/order/create', $this->data($p, ['coupon_code' => 'INVALID', 'query_password' => $secret]))
            ->assertRedirect()->assertSessionHasErrors('error')->assertSessionMissing('_old_input.query_password');
        $this->post('/order/query', ['email' => 'invalid', 'query_password' => $secret])
            ->assertRedirect()->assertSessionHasErrors('email')->assertSessionMissing('_old_input.query_password');
        $this->post('/order/query', ['email' => 'buyer@example.test', 'query_password' => $secret, 'order_no' => 'PUBLIC-EXACT-ORDER'])
            ->assertRedirect()->assertSessionHasErrors('error')->assertSessionMissing('_old_input.query_password')->assertSessionHas('_old_input.order_no', 'PUBLIC-EXACT-ORDER');
        for ($i = 0; $i < 5; $i++) { $this->postJson('/order/verify', ['email' => 'buyer@example.test', 'query_password' => 'wrong-password']); }
        $this->post('/order/query', ['email' => 'buyer@example.test', 'query_password' => $secret])
            ->assertRedirect()->assertSessionHasErrors('error')->assertSessionMissing('_old_input.query_password');
    }

    public function test_three_themes_escape_reflected_search_and_checkout_input(): void
    {
        $p = $this->product();
        $payload = '"><svg onload=this.id=\'xss-fired\'>';
        foreach (['default', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme);
            $this->get('/?'.http_build_query(['q' => $payload]))->assertOk()->assertDontSee($payload, false);
            $this->withSession(['_old_input' => ['coupon_code' => $payload]])->get('/product/'.$p->slug)
                ->assertOk()->assertSee(e($payload), false)->assertDontSee($payload, false);
        }
    }

    public function test_failed_bot_verification_does_not_flash_password_or_challenge_token(): void
    {
        $p = $this->product();
        Setting::set('turnstile_secret_key', 'public-dummy-secret');
        Setting::set('turnstile_site_key', 'public-dummy-site');
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
        $this->from('/product/'.$p->slug)->post('/order/create', $this->data($p, ['cf-turnstile-response' => 'public-dummy-challenge']))
            ->assertRedirect()->assertSessionHasErrors('turnstile')
            ->assertSessionMissing('_old_input.query_password')->assertSessionMissing('_old_input.cf-turnstile-response');
        $this->assertSame(0, Order::count());
        $this->assertSame(8, $p->stockCount());
    }

    public function test_customer_password_and_card_markup_never_become_executable_html(): void
    {
        $p = $this->product();
        $payload = '</textarea><svg onload=this.id=\'xss-fired\'>';
        $p->cards()->update(['content' => $payload]);
        $password = '<svg onload=alert(1)>password';
        $this->post('/order/create', $this->data($p, ['query_password' => $password]))->assertSessionHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $this->notify($order, '10.00')->assertContent('success');
        $this->assertSame('paid', $order->fresh()->status);
        foreach (['default', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme);
            $this->get('/order/detail/'.$order->order_no)->assertOk()->assertSee(e($payload), false)->assertDontSee($payload, false)->assertDontSee($password, false);
        }
    }

    public function test_pending_order_payment_return_and_callback_forgeries_do_not_fulfil(): void
    {
        $order = app(OrderService::class)->createOrder($this->data($this->product()));
        parse_str(parse_url(app(EpayService::class)->createPayment($order, 'alipay'), PHP_URL_QUERY), $outbound);
        $this->get('/payment/epay/return?'.http_build_query($outbound))->assertRedirect('/order/pay/'.$order->order_no);
        foreach ([[], ['money' => '0.00'], ['trade_status' => 'TRADE_SUCCESS', 'trade_no' => 'FORGED']] as $changes) {
            $this->post('/payment/epay/notify', array_replace($outbound, $changes))->assertContent('fail');
        }
        $this->postJson('/payment/epusdt/notify', ['order_id' => $order->order_no, 'trade_id' => 'FORGED', 'status' => 2, 'amount' => '10.00', 'signature' => str_repeat('0', 32)])->assertContent('invalid signature');
        $this->get('/order/pay/'.$order->order_no.'?status=paid&payment_review=0')->assertOk()->assertDontSee('buyer-secret-');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(0, $order->paymentReceipts()->count());
    }

    public function test_sql_order_lookup_and_password_changes_cannot_grant_other_orders(): void
    {
        $p = $this->product();
        $order = app(OrderService::class)->createOrder($this->data($p));
        $other = app(OrderService::class)->createOrder($this->data($p, ['query_password' => 'another-password']));
        $this->notify($order, '10.00')->assertContent('success');
        $this->notify($other, '10.00')->assertContent('success');
        $this->postJson('/order/verify', ['email' => $order->email, 'query_password' => 'public-buyer-password', 'order_no' => "' OR 1=1 --"])->assertJsonPath('success', false);
        $this->get('/order/detail/'.$order->order_no)->assertRedirect('/order/query');
        $this->postJson('/order/verify', ['email' => $order->email, 'query_password' => 'public-buyer-password'])->assertJsonPath('success', true);
        $this->get('/order/detail/'.$order->order_no)->assertOk();
        $this->get('/order/detail/'.$other->order_no)->assertRedirect('/order/query');
        $this->get('/order/cards/'.$other->order_no.'/download')->assertRedirect('/order/query')->assertSessionHasErrors('error');
        $this->post('/order/cancel/'.$other->order_no)->assertForbidden();
        $this->post('/order/refund/'.$other->order_no, ['amount' => '10', 'reason' => 'forged'])->assertForbidden();
    }

    public function test_quote_is_not_an_authorization_to_use_stale_prices_or_discounts(): void
    {
        $p = $this->product();
        $p->wholesalePrices()->create(['min_quantity' => 2, 'price' => '8.50']);
        $coupon = Coupon::create(['code' => 'CHANGING', 'type' => 'fixed', 'value' => '2.00', 'is_active' => true]);
        $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 2, 'coupon_code' => $coupon->code])->assertOk()->assertJsonPath('data.total_amount', '15.00');
        $coupon->update(['is_active' => false]);
        $this->post('/order/create', $this->data($p, ['quantity' => 2, 'coupon_code' => 'CHANGING', 'total_amount' => '15.00']))->assertSessionHasErrors('error');
        $this->assertSame(0, Order::count());
        $p->wholesalePrices()->delete();
        $p->update(['price' => '12.00']);
        $this->post('/order/create', $this->data($p, ['quantity' => 2, 'total_amount' => '15.00']))->assertSessionHasNoErrors();
        $this->assertSame('24.00', Order::firstOrFail()->total_amount);
    }

    public function test_cancelled_discount_cannot_be_reused_by_a_late_payment(): void
    {
        $p = $this->product();
        $coupon = Coupon::create(['code' => 'ONEONLY', 'type' => 'percent', 'value' => '100.00', 'max_uses' => 1, 'is_active' => true]);
        $service = app(OrderService::class);
        $cancelled = $service->createOrder($this->data($p, ['coupon_code' => 'ONEONLY']));
        $service->closeOrder($cancelled, true);
        $current = $service->createOrder($this->data($p, ['coupon_code' => 'ONEONLY']));
        $this->notify($cancelled, '0.01')->assertContent('success');
        $this->assertSame('closed', $cancelled->fresh()->status);
        $this->assertSame(0, $cancelled->cards()->where('status', 'sold')->count());
        $this->assertNotNull($cancelled->fresh()->payment_no);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->notify($current, '0.01')->assertContent('success');
        $this->assertSame('paid', $current->fresh()->status);
    }

    public function test_browser_csrf_is_required_for_creation_cancellation_and_refund(): void
    {
        $p = $this->product();
        $order = app(OrderService::class)->createOrder($this->data($p));
        $this->withSession(['order_verified_ids' => [$order->id]]);
        $this->app['env'] = 'local';
        try {
            $this->post('/order/create', $this->data($p))->assertStatus(419);
            $this->post('/order/cancel/'.$order->order_no)->assertStatus(419);
            $this->post('/order/refund/'.$order->order_no, ['amount' => '10', 'reason' => 'forged'])->assertStatus(419);
        } finally { $this->app['env'] = 'testing'; }
        $this->assertSame(1, Order::count());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(7, $p->stockCount());
    }

    public function test_unreleased_expired_browser_orders_still_consume_the_reservation_limit(): void
    {
        $p = $this->product();
        for ($i = 0; $i < 3; $i++) {
            $o = app(OrderService::class)->createOrder($this->data($p, ['ip' => '127.0.0.1']));
            $o->update(['expires_at' => now()->subMinute()]);
        }
        $this->post('/order/create', $this->data($p))->assertSessionHasErrors('error');
        $this->assertSame(3, Order::count());
        $this->assertSame(5, $p->stockCount());
        app(OrderService::class)->expireOrders();
        $this->post('/order/create', $this->data($p))->assertSessionHasNoErrors();
        $this->assertSame(4, Order::count());
        $this->assertSame(7, $p->stockCount());
    }

    public function test_expiry_copy_does_not_push_a_paid_buyer_to_pay_again_and_late_receipts_deliver(): void
    {
        $p = $this->product();
        $order = app(OrderService::class)->createOrder($this->data($p));
        $order->update(['expires_at' => now()->subMinute()]);
        $this->withSession(['order_verified_ids' => [$order->id]]);
        foreach (['default', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme);
            $this->get('/order/pay/'.$order->order_no)->assertOk()->assertSee('若已付款')->assertSee('请勿重复支付')->assertDontSee('id="payment-polling"', false);
            $this->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('若已付款')->assertSee('请勿重复支付');
        }
        $this->assertSame(8, $p->stockCount());
        $this->notify($order, '10.00')->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(7, $p->stockCount());
    }

    public function test_usdt_callback_checks_fiat_amount_and_never_uses_token_amount_as_price(): void
    {
        $order = app(OrderService::class)->createOrder($this->data($this->product(), ['payment_method' => 'usdt_trc20']));
        foreach (['0.00', '9.99', '1e2', '-10.00'] as $amount) {
            $params = ['order_id' => $order->order_no, 'trade_id' => 'buyer-usdt-receipt', 'status' => 2, 'amount' => $amount, 'actual_amount' => '100.00'];
            $params['signature'] = $this->signature($params, 'buyer-usdt-key');
            $this->postJson('/payment/epusdt/notify', $params)->assertOk();
            $this->assertSame('pending', $order->fresh()->status);
            $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
            $this->assertSame(0, $order->paymentReceipts()->count());
        }
        $params = ['order_id' => $order->order_no, 'trade_id' => 'buyer-usdt-receipt', 'status' => 2, 'amount' => '10.00', 'actual_amount' => '1.45'];
        $params['signature'] = $this->signature($params, 'buyer-usdt-key');
        $this->postJson('/payment/epusdt/notify', $params)->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('1.45000000', $order->paymentReceipts()->firstOrFail()->actual_amount);
    }

    public function test_payment_polling_quotes_and_api_lookup_do_not_spend_browser_checkout_quota(): void
    {
        $p = $this->product();
        $token = ApiToken::where('token', hash('sha256', 'buyer-test-token'))->firstOrFail();
        $order = app(OrderService::class)->createOrder($this->data($p, ['api_token_id' => $token->id]));
        for ($i = 0; $i < 6; $i++) { $this->getJson('/order/pay/'.$order->order_no)->assertOk()->assertJsonPath('status', 'pending'); }
        $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 1])->assertOk();
        $this->withToken('buyer-test-token')->postJson('/api/v1/orders/'.$order->order_no.'/query', ['email' => $order->email, 'query_password' => 'public-buyer-password'])->assertOk();
        $this->post('/order/create', $this->data($p))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, Order::count());
    }

    public function test_each_checkout_limit_remains_enforced_after_separating_other_actions(): void
    {
        $p = $this->product();
        $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 1])->assertOk();
        for ($i = 0; $i < 5; $i++) { $this->postJson('/order/create', $this->data($p, ['quantity' => 0]))->assertUnprocessable(); }
        $this->postJson('/order/create', $this->data($p, ['quantity' => 0]))->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/order/quote', ['product_id' => $p->id, 'quantity' => 1])->assertOk();
        $this->assertSame(0, Order::count());
        $this->assertSame(8, $p->stockCount());
    }
}
