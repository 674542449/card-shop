<?php

namespace Tests\Feature;

use App\Models\{Card, Category, Order, PaymentAttempt, Product, Setting};
use App\Services\{EpayService, EpusdtService, OrderFulfilmentService, OrderService};
use App\Support\{ApiErrorContract, OrderPayAccess};
use Illuminate\Support\Facades\{Hash, Http};
use Tests\TestCase;

class OrderPayAccessSecurityTest extends TestCase
{
    private function product(): Product
    {
        $category = Category::create(['name' => 'Pay Security', 'slug' => 'pay-security', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Security Item',
            'slug' => 'security-item',
            'price' => '15.00',
            'is_active' => true,
            'min_quantity' => 1,
            'max_quantity' => 5,
        ]);
        Card::create(['product_id' => $product->id, 'content' => 'SECRET-CARD-ALPHA', 'status' => 'unsold']);
        Card::create(['product_id' => $product->id, 'content' => 'SECRET-CARD-BETA', 'status' => 'unsold']);

        return $product;
    }

    private function createOrder(Product $product, array $overrides = []): Order
    {
        return app(OrderService::class)->createOrder(array_replace([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'owner@example.test',
            'query_password' => 'buyer-secret-pass',
            'payment_method' => 'alipay',
            'ip' => '192.0.2.55',
        ], $overrides));
    }

    public function test_bare_order_no_is_blocked_across_sessions_without_existence_oracle_or_side_effects(): void
    {
        Http::preventStrayRequests();
        $order = $this->createOrder($this->product());
        $order->update(['expires_at' => now()->subMinute()]);
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'method' => 'alipay',
            'status' => 'processing',
            'lease_token' => '11111111-2222-4333-8444-555555555555',
            'lease_expires_at' => now()->subSeconds(10),
        ]);

        $missingOrderNo = '202611240000009999999999';

        $existingJson = $this->getJson('/order/pay/' . $order->order_no)
            ->assertForbidden()
            ->assertJson([
                'message' => '请先验证订单身份后再查看支付状态',
                'code' => 'permission_denied',
            ]);
        $missingJson = $this->getJson('/order/pay/' . $missingOrderNo)
            ->assertForbidden()
            ->assertJson([
                'message' => '请先验证订单身份后再查看支付状态',
                'code' => 'permission_denied',
            ]);
        $this->assertSame($existingJson->getContent(), $missingJson->getContent());
        $this->assertStringContainsString('no-store', (string) $existingJson->headers->get('Cache-Control'));

        $this->get('/order/pay/' . $order->order_no)->assertRedirect('/order/query');
        $this->get('/order/pay/' . $missingOrderNo)->assertRedirect('/order/query');

        // Unauthenticated probes must never expire the order or mutate PaymentAttempt leases.
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('processing', $attempt->fresh()->status);
    }

    public function test_forged_malformed_and_cross_order_signatures_are_rejected(): void
    {
        $product = $this->product();
        $first = $this->createOrder($product, ['email' => 'first@example.test']);
        $second = $this->createOrder($product, ['email' => 'second@example.test']);

        $badSignatures = [
            '',
            'invalid',
            str_repeat('0', 32),
            strtoupper(OrderPayAccess::signature($first)),
            OrderPayAccess::signature($second),
        ];

        foreach ($badSignatures as $sig) {
            $this->getJson('/order/pay/' . $first->order_no . '?sig=' . urlencode($sig))
                ->assertForbidden()
                ->assertJsonPath('code', 'permission_denied');
            $this->get('/order/pay/' . $first->order_no . '?sig=' . urlencode($sig))
                ->assertRedirect('/order/query');
        }

        // Array query parameter (?sig[]=...) must also fail safely without TypeError.
        $this->getJson('/order/pay/' . $first->order_no . '?sig[]=abc')
            ->assertForbidden()
            ->assertJsonPath('code', 'permission_denied');
    }

    public function test_signed_url_supports_cross_device_payment_and_polling_but_never_exposes_paid_cards(): void
    {
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', 'sec-merchant', 'payment');
        Setting::set('epay_merchant_key', 'sec-key', 'payment');

        $order = $this->createOrder($this->product());
        $signedPath = OrderPayAccess::path($order);

        // Fresh cross-device session with signed URL can render the unpaid page and poll status.
        $this->app['session']->flush();
        $html = $this->get($signedPath)
            ->assertOk()
            ->assertSee('data-poll-url="' . $signedPath . '"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSee('SECRET-CARD-');
        $this->assertStringContainsString('https://gateway.example.test/submit.php?', $html->getContent());

        $this->getJson($signedPath)
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('verification_required', false);

        // Once paid, the signed URL alone MUST NOT expose delivered cards on HTML,
        // and JSON polling explicitly signals verification_required = true.
        app(OrderFulfilmentService::class)->fulfilManually($order);

        $this->app['session']->flush();
        $this->get($signedPath)
            ->assertRedirect('/order/query');
        $this->getJson($signedPath)
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('verification_required', true)
            ->assertDontSee('SECRET-CARD-');
    }

    public function test_credential_rotation_revokes_previous_signed_pay_links(): void
    {
        $order = $this->createOrder($this->product());
        $oldPath = OrderPayAccess::path($order);

        $this->getJson($oldPath)->assertOk()->assertJsonPath('status', 'pending');

        $order->update(['query_password' => Hash::make('rotated-secret-pass')]);
        $this->getJson($oldPath)->assertForbidden();
        $this->get($oldPath)->assertRedirect('/order/query');

        $newPath = OrderPayAccess::path($order->fresh());
        $this->assertNotSame($oldPath, $newPath);
        $this->getJson($newPath)->assertOk()->assertJsonPath('status', 'pending');

        $order->update(['email' => 'changed-owner@example.test']);
        $this->getJson($newPath)->assertForbidden();
        $this->getJson(OrderPayAccess::path($order->fresh()))->assertOk();
    }

    public function test_checkout_fallback_and_epusdt_redirect_carry_signed_pay_url_while_epay_return_stays_session_gated(): void
    {
        $product = $this->product();

        // 1. Checkout fallback redirect (when no payment gateway is configured) includes ?sig=...
        $response = $this->post('/order/create', [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'fallback@example.test',
            'query_password' => 'fallback-pass-1',
            'payment_method' => 'alipay',
        ])->assertRedirect();
        $order = Order::where('email', 'fallback@example.test')->firstOrFail();
        $response->assertRedirect(OrderPayAccess::path($order));

        // 2. EPUSDT server-to-server create-transaction sends signed redirect_url.
        Setting::set('epusdt_api_url', 'https://usdt.example.test', 'payment');
        Setting::set('epusdt_api_token', 'usdt-sec-token', 'payment');
        $capturedRedirect = null;
        Http::fake(function ($request) use (&$capturedRedirect) {
            $capturedRedirect = $request->data()['redirect_url'] ?? null;

            return Http::response([
                'status_code' => 200,
                'data' => ['payment_url' => 'https://usdt.example.test/pay/signed', 'trade_id' => 't-signed'],
            ]);
        });
        $usdtOrder = $this->createOrder($product, ['email' => 'usdt@example.test', 'payment_method' => 'usdt_trc20']);
        app(EpusdtService::class)->createPayment($usdtOrder, 'trc20');
        $this->assertSame(OrderPayAccess::url($usdtOrder), $capturedRedirect);

        // 3. Replaying EPay outbound params to /payment/epay/return in a fresh session never mints ?sig=...
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', 'epay-sec-pid', 'payment');
        Setting::set('epay_merchant_key', 'epay-sec-key', 'payment');
        $epayUrl = app(EpayService::class)->createPayment($order, 'alipay');
        parse_str((string) parse_url($epayUrl, PHP_URL_QUERY), $epayParams);

        $this->app['session']->flush();
        $this->get('/payment/epay/return?' . http_build_query($epayParams))
            ->assertRedirect('/order/pay/' . $order->order_no);
        $this->get('/order/pay/' . $order->order_no)->assertRedirect('/order/query');
        $this->getJson('/order/pay/' . $order->order_no)->assertForbidden();
    }

    public function test_json_404_responses_sanitize_internal_eloquent_model_class_names(): void
    {
        $response = $this->getJson('/product/nonexistent-security-item')
            ->assertNotFound()
            ->assertJson([
                'message' => '请求的资源不存在',
                'code' => 'not_found',
            ]);
        $this->assertStringNotContainsString('App\\Models\\Product', $response->getContent());
        $this->assertStringNotContainsString('No query results for model', $response->getContent());

        // Custom 404 messages must remain untouched.
        $custom = ApiErrorContract::apply(response()->json(['message' => '商品不存在'], 404));
        $this->assertSame('商品不存在', $custom->getData(true)['message']);
        $this->assertSame('not_found', $custom->getData(true)['code']);

        // ModelNotFoundException with trailing IDs is also sanitized.
        $eloquent = ApiErrorContract::apply(response()->json(['message' => 'No query results for model [App\\Models\\Order] 42'], 404));
        $this->assertSame('请求的资源不存在', $eloquent->getData(true)['message']);
        $this->assertSame('not_found', $eloquent->getData(true)['code']);
    }

    public function test_detail_download_cancel_and_refund_have_no_order_existence_oracle(): void
    {
        $order = $this->createOrder($this->product());
        $missing = '202611240000009999999999';

        $this->get('/order/detail/' . $order->order_no)->assertRedirect('/order/query');
        $this->get('/order/detail/' . $missing)->assertRedirect('/order/query');
        $existingDetailJson = $this->getJson('/order/detail/' . $order->order_no)->assertForbidden();
        $missingDetailJson = $this->getJson('/order/detail/' . $missing)->assertForbidden();
        $this->assertSame($existingDetailJson->getContent(), $missingDetailJson->getContent());

        $this->get('/order/cards/' . $order->order_no . '/download')->assertRedirect('/order/query');
        $this->get('/order/cards/' . $missing . '/download')->assertRedirect('/order/query');
        $this->getJson('/order/cards/' . $order->order_no . '/download')->assertForbidden();
        $this->getJson('/order/cards/' . $missing . '/download')->assertForbidden();

        $this->post('/order/cancel/' . $order->order_no)->assertForbidden();
        $this->post('/order/cancel/' . $missing)->assertForbidden();
        $this->post('/order/refund/' . $order->order_no, ['amount' => '10.00', 'reason' => 'probe'])->assertForbidden();
        $this->post('/order/refund/' . $missing, ['amount' => '10.00', 'reason' => 'probe'])->assertForbidden();
    }

    public function test_rapid_unauthorized_order_pay_probes_hit_429_without_blocking_signed_buyers(): void
    {
        $order = $this->createOrder($this->product());
        $signedPath = OrderPayAccess::path($order);

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/order/pay/' . $order->order_no)->assertForbidden();
        }

        $this->getJson('/order/pay/' . $order->order_no)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'rate_limited');
        $this->get('/order/pay/' . $order->order_no)
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // A legitimate buyer with a valid signature on the same IP is NOT blocked by the denied-probe bucket.
        $this->getJson($signedPath)
            ->assertOk()
            ->assertJsonPath('status', 'pending');
    }
}
