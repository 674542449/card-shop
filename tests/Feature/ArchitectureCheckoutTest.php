<?php

namespace Tests\Feature;

use App\Models\{ApiOrderRequest, ApiToken, Card, Category, Coupon, Order, PaymentAttempt, Product, Setting};
use App\Services\{ApiOrderCredentialProof, ApiOrderIdempotency, OrderService};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Hash, Http};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArchitectureCheckoutTest extends TestCase
{
    private Product $product;
    private ApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::set('epay_api_url', 'https://architecture-gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1', 'payment');
        Setting::set('epay_merchant_key', 'test-only-architecture-epay-key', 'payment');
        Setting::set('epusdt_api_url', 'https://architecture-gateway.example.test', 'payment');
        Setting::set('epusdt_api_token', 'test-only-architecture-usdt-key', 'payment');
        $category = Category::create(['name' => 'Checkout architecture', 'slug' => 'checkout-architecture', 'is_active' => true]);
        $this->product = Product::create(['category_id' => $category->id, 'name' => 'Checkout architecture', 'slug' => 'checkout-architecture',
            'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true]);
        for ($i = 0; $i < 8; $i++) { Card::create(['product_id' => $this->product->id, 'content' => 'ARCHITECTURE-TEST-CARD-'.$i, 'status' => 'unsold']); }
        $this->token = ApiToken::create(['name' => 'Architecture test', 'token' => hash('sha256', 'architecture-api-token'), 'is_active' => true]);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['product_id' => $this->product->id, 'quantity' => 1, 'email' => 'checkout-architecture@example.test',
            'query_password' => 'architecture-buyer-password', 'payment_method' => 'usdt_trc20'], $changes);
    }

    private function apiCheckout(array $input, string $key = 'architecture-request')
    {
        return $this->withToken('architecture-api-token')->postJson('/api/v1/orders', $input, ['Idempotency-Key' => $key]);
    }

    public function test_gateway_http_runs_without_adding_a_database_transaction_and_replays_after_cache_loss(): void
    {
        $baseline = DB::transactionLevel(); // RefreshDatabase's fixture transaction is allowed.
        $calls = 0;
        Http::fake(function ($request) use ($baseline, &$calls) {
            $calls++;
            $this->assertSame($baseline, DB::transactionLevel());
            $this->assertSame('processing', PaymentAttempt::firstOrFail()->status);
            $this->assertNotNull(ApiOrderRequest::firstOrFail()->order_id);
            return Http::response(['status_code' => 200, 'data' => ['trade_id' => 'architecture-trade', 'order_id' => $request['order_id'],
                'amount' => '10.00', 'payment_url' => 'https://architecture-gateway.example.test/pay/architecture-trade']]);
        });
        $first = $this->apiCheckout($this->input())->assertCreated();
        \Illuminate\Support\Facades\Cache::flush();
        $second = $this->apiCheckout($this->input())->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $calls);
        $this->assertSame(1, Order::count());
        $this->assertSame('succeeded', PaymentAttempt::firstOrFail()->status);
    }

    public static function uncertainResponses(): array
    {
        return [
            'transport timeout' => ['timeout', null, null],
            'HTTP 503 after submission' => ['reply', ['status_code' => 500], 503],
            'HTTP 408 after submission' => ['reply', 'request timeout', 408],
            'truncated success response' => ['reply', 'incomplete JSON', 200],
            'missing status response' => ['reply', ['message' => 'no status'], 200],
            'executable returned URL' => ['reply', ['status_code' => 200, 'data' => ['trade_id' => 'unsafe-trade', 'payment_url' => 'javascript:alert(1)']], 200],
            'missing trade identity' => ['reply', ['status_code' => 200, 'data' => ['payment_url' => 'https://architecture-gateway.example.test/pay/missing-trade']], 200],
        ];
    }

    #[DataProvider('uncertainResponses')]
    public function test_uncertain_remote_creation_preserves_the_order_and_never_sends_a_second_request(string $kind, mixed $body, ?int $status): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls, $kind, $body, $status) {
            $calls++;
            if ($kind === 'timeout') { throw new ConnectionException('Isolated test connection timeout.'); }
            return Http::response($body, $status);
        });
        $first = $this->apiCheckout($this->input())->assertStatus(202)->assertJsonPath('data.payment_initialization', 'uncertain');
        $second = $this->apiCheckout($this->input())->assertStatus(202)->assertJsonPath('data.payment_initialization', 'uncertain');
        $this->assertSame($first->json('data.order_no'), $second->json('data.order_no'));
        $this->assertSame(1, $calls);
        $this->assertSame(1, Order::count());
        $this->assertSame('pending', Order::firstOrFail()->status);
        $this->assertSame('uncertain', PaymentAttempt::firstOrFail()->status);
        $this->assertSame(1, Card::where('status', 'locked')->count());
        $first->assertJsonMissingPath('data.payment_url')->assertDontSee('javascript:')->assertDontSee('test-only-architecture-usdt-key');
        $second->assertJsonMissingPath('data.payment_url')->assertDontSee('javascript:');
    }

    public function test_expiry_releases_uncertain_inventory_without_creating_a_new_gateway_transaction(): void
    {
        Http::fake(fn () => Http::response('truncated', 200));
        $this->apiCheckout($this->input())->assertStatus(202);
        $order = Order::firstOrFail(); $order->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(OrderService::class)->expireOrders());
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame(8, $this->product->stockCount());
        Http::assertSentCount(1);
    }

    public function test_unknown_payment_cannot_be_cancelled_via_api_or_verified_browser(): void
    {
        Http::fake(fn () => Http::response('incomplete response', 200));
        $input = $this->input();
        $this->apiCheckout($input)->assertStatus(202);
        $order = Order::firstOrFail();
        $this->withToken('architecture-api-token')->postJson('/api/v1/orders/'.$order->order_no.'/cancel',
            ['email' => $input['email'], 'query_password' => $input['query_password']])->assertUnprocessable();
        $this->withBuyerSession($order)->post('/order/cancel/'.$order->order_no)->assertRedirect()->assertSessionHasErrors('error');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, Card::where('status', 'locked')->count());
        Http::assertSentCount(1);
    }

    public static function waitingPaymentViews(): array
    {
        $cases = [];
        foreach (['default', 'modern', 'minimal'] as $theme) {
            foreach (['processing', 'uncertain'] as $state) { $cases[$theme.' '.$state] = [$theme, $state]; }
        }
        return $cases;
    }

    #[DataProvider('waitingPaymentViews')]
    public function test_waiting_payment_views_ignore_cached_links_and_expire_normally(string $theme, string $state): void
    {
        Setting::set('site_theme', $theme, 'site');
        Http::fake(fn () => Http::response('incomplete response', 200));
        $this->apiCheckout($this->input())->assertStatus(202);
        $order = Order::firstOrFail();
        PaymentAttempt::where('order_id', $order->id)->update(['status' => $state]);
        $oldUrl = 'https://architecture-gateway.example.test/pay/old-cached-payment';
        \Illuminate\Support\Facades\Cache::put('payment_url:'.$order->order_no, $oldUrl, 300);
        \Illuminate\Support\Facades\Cache::put('epusdt_payment:'.$order->order_no, ['payment_url' => $oldUrl, 'trade_id' => 'old-cached-trade'], 300);
        $this->withSession(['payment_url_'.$order->order_no => $oldUrl])->get('/order/pay/'.$order->order_no)
            ->assertOk()->assertSee('请勿重复下单或付款')->assertDontSee($oldUrl)->assertDontSee('前往支付');
        $this->getJson('/order/pay/'.$order->order_no)->assertOk()->assertJsonPath('payment_initialization', $state)->assertJsonPath('status', 'pending');
        $this->assertSame(1, Card::where('status', 'locked')->count());
        $order->update(['expires_at' => now()->subMinute()]);
        $this->get('/order/pay/'.$order->order_no)->assertOk()->assertDontSee($oldUrl)->assertDontSee('前往支付');
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame(8, $this->product->stockCount());
        Http::assertSentCount(1);
    }

    public static function themes(): array { return ['default' => ['default'], 'modern' => ['modern'], 'minimal' => ['minimal']]; }

    #[DataProvider('themes')]
    public function test_web_retry_reuses_inventory_coupon_and_order_and_conflicting_parameters_are_rejected(string $theme): void
    {
        Setting::set('site_theme', $theme, 'site');
        $coupon = Coupon::create(['code' => 'ARCHITECTURE-ONCE', 'type' => 'fixed', 'value' => '2.00', 'max_uses' => 1, 'is_active' => true]);
        $html = $this->get('/product/'.$this->product->slug)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="checkout_key"\s+value="([^"]+)"/', $html, $match));
        $input = $this->input(['payment_method' => 'alipay', 'checkout_key' => html_entity_decode($match[1]), 'coupon_code' => $coupon->code]);
        $this->post('/order/create', $input)->assertRedirect()->assertSessionHasNoErrors();
        $original = Order::firstOrFail();
        $this->product->update(['is_active' => false]);
        $this->post('/order/create', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Card::where('status', 'locked')->count());
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame('8.00', $original->fresh()->total_amount);
        $this->post('/order/create', array_replace($input, ['quantity' => 2]))->assertRedirect()->assertSessionHasErrors('error');
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_resuming_api_checkout_cannot_authenticate_an_old_password_after_hash_change(): void
    {
        $input = $this->input(['payment_method' => 'alipay']);
        $record = app(ApiOrderIdempotency::class)->reserve($this->token->id, 'interrupted-credentials', $input,
            fn () => app(OrderService::class)->createOrder($input + ['api_token_id' => $this->token->id, 'ip' => '192.0.2.17']));
        $order = Order::findOrFail($record->order_id);
        // Leave the historical lookup key unchanged deliberately. The bcrypt hash
        // remains authoritative after a password repair or legacy restore.
        $order->update(['query_password' => Hash::make('new-architecture-password')]);
        $this->apiCheckout($input, 'interrupted-credentials')->assertStatus(409);
        $this->assertFalse(app(ApiOrderCredentialProof::class)->has($order->fresh(), $this->token->id, $input['email'], $input['query_password']));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, Card::where('status', 'locked')->count());
        $this->withToken('architecture-api-token')->postJson('/api/v1/orders/'.$order->order_no.'/query',
            ['email' => $input['email'], 'query_password' => $input['query_password']])->assertNotFound();
    }
}
