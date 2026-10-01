<?php

namespace Tests\Feature;

use App\Models\{Card, Category, NotificationDelivery, Order, Product, Setting};
use App\Services\{EpusdtService, OrderFulfilmentService, OrderService, PaymentReconciliationService};
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\{Cache, Http, Log};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CardTheftPaymentSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::set('epay_api_url', 'https://gateway.example.test');
        Setting::set('epay_merchant_id', '1');
        Setting::set('epay_merchant_key', 'isolated-card-theft-epay-key');
        Setting::set('epusdt_api_url', 'https://gateway.example.test');
        Setting::set('epusdt_api_token', 'isolated-card-theft-usdt-key');
    }

    private function order(string $method = 'usdt_trc20'): Order
    {
        $category = Category::create(['name' => 'Isolated payment security', 'slug' => uniqid('theft-cat-'), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Isolated payment security', 'slug' => uniqid('theft-product-'),
            'price' => '10.00', 'is_active' => true]);
        for ($i = 0; $i < 3; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'dummy-payment-security-card-'.$i, 'status' => 'unsold']);
        }
        return app(OrderService::class)->createOrder(['product_id' => $product->id, 'quantity' => 1, 'email' => 'isolated@example.test',
            'query_password' => 'isolated-query-password', 'payment_method' => $method, 'ip' => '192.0.2.75']);
    }

    private function sign(array $params, string $key): string
    {
        unset($params['signature'], $params['sign'], $params['sign_type']);
        ksort($params);
        $parts = [];
        foreach ($params as $name => $value) {
            if (is_scalar($value) && $value !== '' && $value !== null) {
                $parts[] = $name.'='.$value;
            }
        }
        return md5(implode('&', $parts).$key);
    }

    private function usdtNotify(Order $order, string $trade, array $extra = []): void
    {
        $params = $extra + ['order_id' => $order->order_no, 'trade_id' => $trade, 'status' => 2, 'amount' => '10.00'];
        $params['signature'] = $this->sign($params, 'isolated-card-theft-usdt-key');
        $this->postJson('/payment/epusdt/notify', $params)->assertOk();
    }

    public static function inconsistentTransactions(): array
    {
        return [
            'foreign order' => [['order_id' => 'ANOTHER-MERCHANT-ORDER']],
            'null order' => [['order_id' => null]],
            'nested order' => [['order_id' => ['foreign']]],
            'discounted amount' => [['amount' => '0.01']],
            'different fraction' => [['amount' => '10.00000001']],
            'nested amount' => [['amount' => ['10.00']]],
            'null amount' => [['amount' => null]],
            'foreign fiat' => [['fiat' => 'JPY']],
            'null fiat' => [['fiat' => null]],
            'expired transaction' => [['status' => 3]],
            'nested trade' => [['trade_id' => ['another-trade']]],
            'empty trade' => [['trade_id' => '']],
            'numeric trade' => [['trade_id' => 123]],
            'control character trade' => [['trade_id' => "trade\nnext"]],
        ];
    }

    #[DataProvider('inconsistentTransactions')]
    public function test_gateway_mixed_transaction_cannot_be_bound_or_cached(array $changes): void
    {
        $order = $this->order();
        Http::fake(['gateway.example.test/*' => Http::response(['status_code' => 200, 'data' => array_replace([
            'trade_id' => 'expected-trade', 'order_id' => $order->order_no, 'amount' => '10.00', 'fiat' => 'CNY',
            'status' => 1, 'payment_url' => 'https://gateway.example.test/pay/expected-trade',
        ], $changes)])]);
        try {
            app(EpusdtService::class)->createPayment($order, 'trc20');
            $this->fail('An inconsistent gateway transaction must be refused before binding.');
        } catch (\RuntimeException) {
        }
        $this->assertNull($order->fresh()->gateway_trade_no);
        $this->assertNull(Cache::get('payment_url:'.$order->order_no));
        $this->assertNull(Cache::get('epusdt_payment:'.$order->order_no));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }

    public function test_original_gateway_optional_fields_and_expected_payment_still_work(): void
    {
        $order = $this->order();
        Http::fake(['gateway.example.test/*' => Http::response(['status_code' => 200, 'data' => [
            'trade_id' => 'legacy-bound-trade', 'payment_url' => 'https://gateway.example.test/pay/legacy-bound-trade',
        ]])]);
        $result = app(EpusdtService::class)->createPayment($order, 'trc20');
        $this->assertSame('legacy-bound-trade', $result['trade_id']);
        $this->usdtNotify($order, 'legacy-bound-trade');
        $this->usdtNotify($order, 'legacy-bound-trade');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(1, $order->paymentReceipts()->count());
        $this->assertSame(1, NotificationDelivery::where('type', 'order_email')->count());
    }

    public function test_bepusdt_creation_explicitly_signs_the_cny_currency(): void
    {
        Setting::set('usdt_gateway', 'bepusdt');
        $order = $this->order();
        Http::fake(function (GatewayRequest $request) use ($order) {
            $this->assertSame('CNY', $request['fiat']);
            $this->assertSame('usdt.trc20', $request['trade_type']);
            $this->assertSame($this->sign($request->data(), 'isolated-card-theft-usdt-key'), $request['signature']);
            return Http::response(['status_code' => 200, 'data' => ['trade_id' => 'bepusdt-cny-trade', 'order_id' => $order->order_no,
                'amount' => '10.0000', 'fiat' => 'CNY', 'payment_url' => 'https://gateway.example.test/pay/bepusdt-cny-trade']]);
        });
        $this->assertSame('bepusdt-cny-trade', app(EpusdtService::class)->createPayment($order, 'trc20')['trade_id']);
        Http::assertSentCount(1);
    }

    public function test_unsigned_create_response_is_not_requested_over_public_plaintext_http(): void
    {
        $order = $this->order();
        Setting::set('epusdt_api_url', 'http://gateway.example.test');
        try {
            app(EpusdtService::class)->createPayment($order, 'trc20');
            $this->fail('The unsigned response needs an authenticated TLS transport.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTPS', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->gateway_trade_no);
    }

    public function test_signed_foreign_currency_and_unexpected_epay_type_do_not_deliver(): void
    {
        $usdt = $this->order();
        $this->usdtNotify($usdt, 'foreign-currency', ['fiat' => 'JPY']);
        $this->assertSame('pending', $usdt->fresh()->status);
        $this->assertSame(0, $usdt->paymentReceipts()->count());
        $epay = $this->order('alipay');
        $params = ['pid' => '1', 'out_trade_no' => $epay->order_no, 'trade_no' => 'wrong-method', 'trade_status' => 'TRADE_SUCCESS',
            'money' => '10.00', 'type' => 'wxpay'];
        $params['sign'] = $this->sign($params, 'isolated-card-theft-epay-key');
        $this->post('/payment/epay/notify', $params)->assertContent('success');
        $this->assertSame('pending', $epay->fresh()->status);
        $this->assertSame(1, $epay->paymentReceipts()->count());
        $this->assertStringContainsString('收款方式与订单不符', $epay->fresh()->payment_review_reason);
        $this->assertSame(0, Card::where('status', 'sold')->count());
    }

    public function test_a_different_signed_usdt_trade_is_retained_for_review_without_cards(): void
    {
        $order = $this->order();
        $order->update(['gateway_trade_no' => 'checkout-bound-trade']);
        $this->usdtNotify($order, 'different-signed-trade');
        $this->usdtNotify($order, 'different-signed-trade');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('different-signed-trade', $order->fresh()->payment_no);
        $this->assertStringContainsString('交易号不一致', $order->fresh()->payment_review_reason);
        $this->assertSame(1, $order->paymentReceipts()->count());
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(1, NotificationDelivery::where('type', 'payment_review')->count());
        $this->assertSame(0, NotificationDelivery::where('type', 'order_email')->count());
        $this->assertTrue(app(OrderFulfilmentService::class)->fulfilManually($order->fresh())->wasFulfilled());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_a_bound_paid_order_keeps_duplicate_collection_for_review(): void
    {
        $order = $this->order();
        $order->update(['gateway_trade_no' => 'checkout-bound-trade']);
        $this->usdtNotify($order, 'checkout-bound-trade');
        $this->usdtNotify($order, 'extra-signed-collection');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('checkout-bound-trade', $order->fresh()->payment_no);
        $this->assertSame(2, $order->paymentReceipts()->count());
        $this->assertNotNull($order->paymentReceipts()->where('trade_no', 'extra-signed-collection')->firstOrFail()->review_reason);
        $this->assertSame(1, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(1, NotificationDelivery::where('type', 'order_email')->count());
    }

    public function test_gateway_cannot_auto_fulfil_an_unknown_or_manual_payment_method(): void
    {
        $order = $this->order('alipay');
        $order->update(['payment_method' => 'manual']);
        $result = app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'valid-looking-trade', '10.00', 'epay');
        $this->assertSame('refused', $result->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->paymentReceipts()->count());
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }

    public function test_reconciliation_rejects_explicit_null_original_gateway_binding(): void
    {
        $order = $this->order();
        $order->update(['gateway_trade_no' => 'reconcile-bound-trade']);
        Http::fake([
            'gateway.example.test/pay/check-status/*' => Http::response(['status_code' => 200, 'data' => ['trade_id' => 'reconcile-bound-trade', 'status' => 2]]),
            'gateway.example.test/pay/checkout-counter-resp/*' => Http::response(['status_code' => 200, 'data' => [
                'trade_id' => 'reconcile-bound-trade', 'order_id' => null, 'amount' => '10.00',
            ]]),
        ]);
        try {
            app(PaymentReconciliationService::class)->sync($order);
            $this->fail('An explicitly returned null order identity cannot waive the binding check.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->paymentReceipts()->count());
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }

    public function test_reconciliation_keeps_original_gateway_missing_optional_fields_compatible(): void
    {
        $order = $this->order();
        $order->update(['gateway_trade_no' => 'legacy-reconcile-trade']);
        Http::fake([
            'gateway.example.test/pay/check-status/*' => Http::response(['status_code' => 200, 'data' => ['status' => 2]]),
            'gateway.example.test/pay/checkout-counter-resp/*' => Http::response(['status_code' => 200, 'data' => [
                'trade_id' => 'legacy-reconcile-trade', 'amount' => '10.00',
            ]]),
        ]);
        $this->assertTrue(app(PaymentReconciliationService::class)->sync($order)['paid']);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->cards()->where('status', 'sold')->count());
    }

    public static function resolvedBindingTypes(): array
    {
        return ['USDT transaction mismatch' => ['usdt_trc20', 'epusdt', []],
            'EPay method mismatch' => ['alipay', 'epay', ['epay_type' => 'wxpay']]];
    }

    #[DataProvider('resolvedBindingTypes')]
    public function test_resolved_binding_receipt_replay_preserves_manual_fulfilment(string $method, string $channel, array $details): void
    {
        $order = $this->order($method);
        if ($channel === 'epusdt') {
            $order->update(['gateway_trade_no' => 'original-checkout-trade']);
        }
        $service = app(OrderFulfilmentService::class);
        $first = $service->fulfilFromGateway($order->order_no, 'reviewed-collection-trade', '10.00', $channel, $details);
        $this->assertTrue($first->needsOperatorAttention);
        $this->assertTrue($service->fulfilManually($order->fresh())->wasFulfilled());
        $receipt = $order->paymentReceipts()->firstOrFail();
        $this->assertNotNull($receipt->review_resolved_at);
        $resolvedAt = $receipt->review_resolved_at->toIso8601String();
        $resolution = $receipt->resolution_note;
        $deliveryCount = NotificationDelivery::count();
        $replay = $service->fulfilFromGateway($order->order_no, 'reviewed-collection-trade', '10.00', $channel, $details);
        $this->assertSame('skipped', $replay->status);
        $this->assertFalse($replay->needsOperatorAttention);
        $this->assertNull($order->fresh()->payment_review_reason);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame($resolvedAt, $receipt->fresh()->review_resolved_at->toIso8601String());
        $this->assertSame($resolution, $receipt->fresh()->resolution_note);
        $this->assertSame(1, $order->cards()->where('status', 'sold')->count());
        $this->assertSame($deliveryCount, NotificationDelivery::count());
    }

    public static function unsafeGatewayErrors(): array
    {
        return ['HTTP error' => [503, 500], 'business error' => [200, 500],
            'unsafe status code' => [200, 'isolated-card-theft-usdt-key']];
    }

    #[DataProvider('unsafeGatewayErrors')]
    public function test_gateway_error_body_never_reaches_buyer_or_logs(int $httpStatus, mixed $statusCode): void
    {
        $order = $this->order();
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        Log::swap($logger);
        $secret = 'isolated-card-theft-usdt-key';
        Http::fake(['gateway.example.test/*' => Http::response(['status_code' => $statusCode,
            'message' => 'Gateway debug token='.$secret, 'debug' => ['signature' => $secret, 'internal' => 'private-internal-debug']], $httpStatus)]);
        try {
            app(EpusdtService::class)->createPayment($order, 'trc20');
            $this->fail('An unsuccessful gateway must fail payment creation.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
            $this->assertStringNotContainsString('Gateway debug', $e->getMessage());
        }
        $serializedLogs = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $serializedLogs);
        $this->assertStringNotContainsString('private-internal-debug', $serializedLogs);
        $this->assertNotEmpty($logger->records);
        $this->assertNull($order->fresh()->gateway_trade_no);
        $this->assertNull(Cache::get('payment_url:'.$order->order_no));
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }
}
