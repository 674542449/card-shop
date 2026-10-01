<?php

namespace Tests\Feature;

use App\Models\{ApiToken, Card, Category, Coupon, Order, Product, Setting};
use App\Services\{EpayService, NotificationService, OrderFulfilmentService, OrderService};
use Illuminate\Support\Facades\{Hash, Http};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('sendOrderEmail')->willReturn(true);
        $this->instance(NotificationService::class, $notifications);
        Http::preventStrayRequests();
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1', 'payment');
        Setting::set('epay_merchant_key', 'safety-key', 'payment');
        Setting::set('epusdt_api_token', 'safety-usdt-key', 'payment');
        ApiToken::create(['name' => 'Isolated checkout test', 'token' => hash('sha256', 'safety-api-token'), 'is_active' => true]);
    }

    private function product(string $price = '10.00'): Product
    {
        $category = Category::create(['name' => '支付测试', 'slug' => uniqid('safety-category-'), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => '支付测试商品', 'slug' => uniqid('safety-product-'), 'price' => $price, 'min_quantity' => 1, 'max_quantity' => 20, 'is_active' => true]);
        for ($i = 0; $i < 8; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'safety-secret-'.$i, 'status' => 'unsold']);
        }
        return $product;
    }

    private function data(Product $product, array $changes = []): array
    {
        return array_replace(['product_id' => $product->id, 'quantity' => 1, 'email' => 'safety@example.test', 'query_password' => 'safety-password', 'payment_method' => 'alipay', 'ip' => '192.0.2.40'], $changes);
    }

    private function checkout(string $channel, Product $product, array $changes = [], bool $success = true): ?Order
    {
        $before = Order::count();
        $data = $this->data($product, $changes);
        if ($channel === 'api') {
            $response = $this->withHeader('Authorization', 'Bearer safety-api-token')->postJson('/api/v1/orders', $data);
            $success ? $response->assertCreated() : $response->assertUnprocessable();
        } else {
            $response = $this->from('/product/'.$product->slug)->post('/order/create', $data);
            $response->assertRedirect();
            $success ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('error');
        }
        $this->assertSame($before + ($success ? 1 : 0), Order::count());
        return $success ? Order::latest('id')->firstOrFail() : null;
    }

    private function signature(array $params, string $key): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $name => $value) {
            if (is_scalar($value) && $value !== '') {
                $parts[] = $name.'='.$value;
            }
        }
        return md5(implode('&', $parts).$key);
    }

    private function epayNotify(Order $order, mixed $amount, array $changes = []): void
    {
        $params = array_replace(['pid' => '1', 'out_trade_no' => $order->order_no, 'trade_no' => 'safety-trade', 'trade_status' => 'TRADE_SUCCESS', 'money' => $amount], $changes);
        $params['sign'] = $this->signature($params, 'safety-key');
        $this->post('/payment/epay/notify', $params)->assertOk();
    }

    public static function channels(): array
    {
        return ['web' => ['web'], 'api' => ['api']];
    }

    #[DataProvider('channels')]
    public function test_buyer_prices_are_ignored_and_a_signed_underpayment_cannot_deliver(string $channel): void
    {
        $product = $this->product();
        $product->wholesalePrices()->create(['min_quantity' => 2, 'price' => '8.50']);
        $order = $this->checkout($channel, $product, ['quantity' => 2, 'unit_price' => '0.01', 'total_amount' => '0.01', 'discount_amount' => '99999.99']);
        $this->assertSame('8.50', $order->unit_price);
        $this->assertSame('17.00', $order->total_amount);
        $this->assertSame('0.00', $order->discount_amount);
        $this->epayNotify($order, '0.01');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
        $this->epayNotify($order, '17.00');
        $this->epayNotify($order, '17.00');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(6, $product->cards()->where('status', 'unsold')->count());
    }

    public static function fullCoupons(): array
    {
        return [
            'web fixed' => ['web', 'fixed', '100.00'], 'api fixed' => ['api', 'fixed', '100.00'],
            'web percent' => ['web', 'percent', '100.00'], 'api percent' => ['api', 'percent', '100.00'],
        ];
    }

    #[DataProvider('fullCoupons')]
    public function test_full_coupons_keep_exact_receipt_arithmetic_and_require_a_cent(string $channel, string $type, string $value): void
    {
        $product = $this->product();
        $coupon = Coupon::create(['code' => 'FULL', 'type' => $type, 'value' => $value, 'min_amount' => '0.00', 'max_uses' => 1, 'is_active' => true]);
        $order = $this->checkout($channel, $product, ['coupon_code' => ' FULL ']);
        $this->assertSame('0.01', $order->total_amount);
        $this->assertSame('9.99', $order->discount_amount);
        $this->assertSame($order->total_amount, bcsub(bcmul($order->unit_price, (string) $order->quantity, 2), $order->discount_amount, 2));
        $this->assertSame(1, $coupon->fresh()->used_count);
        foreach (['0', '0.009'] as $amount) {
            $this->epayNotify($order, $amount);
            $this->assertSame('pending', $order->fresh()->status);
        }
        $this->epayNotify($order, '0.01');
        $this->assertSame('paid', $order->fresh()->status);
        $this->checkout($channel === 'web' ? 'api' : 'web', $product, ['coupon_code' => 'FULL'], false);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    #[DataProvider('channels')]
    public function test_invalid_legacy_base_and_tier_prices_do_not_reserve_cards(string $channel): void
    {
        foreach (['0.00', '-1.00'] as $price) {
            $product = $this->product($price);
            $this->checkout($channel, $product, [], false);
            $this->assertSame(8, $product->cards()->where('status', 'unsold')->count());
            $product->update(['price' => '10.00']);
            $product->wholesalePrices()->create(['min_quantity' => 2, 'price' => $price]);
            $this->checkout($channel, $product, ['quantity' => 2], false);
            $this->assertSame(8, $product->cards()->where('status', 'unsold')->count());
        }
    }

    #[DataProvider('channels')]
    public function test_amount_overflow_and_invalid_coupon_configs_roll_back_checkout(string $channel): void
    {
        $product = $this->product('99999999.99');
        $this->checkout($channel, $product, ['quantity' => 2], false);
        $product->update(['price' => '10.00']);
        foreach ([['percent', '101.00'], ['fixed', '-1.00'], ['fixed', '0.00']] as $index => [$type, $value]) {
            Coupon::create(['code' => 'BAD-'.$index, 'type' => $type, 'value' => $value, 'min_amount' => '0.00', 'is_active' => true]);
            $this->checkout($channel, $product, ['coupon_code' => 'BAD-'.$index], false);
        }
        $this->assertSame(8, $product->cards()->where('status', 'unsold')->count());
    }

    public function test_signed_malformed_callback_fields_never_deliver_or_crash(): void
    {
        $order = app(OrderService::class)->createOrder($this->data($this->product()));
        foreach (['-1', '1e4', 'NaN', '', null, false, ['10.00']] as $amount) {
            $this->epayNotify($order, $amount);
            $this->assertSame('pending', $order->fresh()->status);
        }
        foreach (['out_trade_no', 'trade_no', 'pid'] as $field) {
            $this->epayNotify($order, '10.00', [$field => ['invalid']]);
            $this->assertSame('pending', $order->fresh()->status);
        }
        $usdtOrder = app(OrderService::class)->createOrder($this->data($this->product(), ['payment_method' => 'usdt_trc20']));
        foreach (['amount', 'order_id', 'trade_id', 'status'] as $field) {
            $params = ['order_id' => $usdtOrder->order_no, 'trade_id' => 'safety-usdt-trade', 'status' => 2, 'amount' => '10.00'];
            $params[$field] = ['invalid'];
            $params['signature'] = $this->signature($params, 'safety-usdt-key');
            $this->postJson('/payment/epusdt/notify', $params)->assertOk();
            $this->assertSame('pending', $usdtOrder->fresh()->status);
        }
    }

    public function test_a_legacy_zero_total_order_cannot_deliver_on_signed_zero_payment(): void
    {
        $order = app(OrderService::class)->createOrder($this->data($this->product()));
        $order->update(['total_amount' => '0.00']);
        $this->epayNotify($order, '0.00');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, $order->cards()->where('status', 'locked')->count());
    }

    public function test_late_payment_cannot_reuse_a_coupon_slot_claimed_by_another_order(): void
    {
        $product = $this->product();
        $coupon = Coupon::create(['code' => 'SINGLE', 'type' => 'fixed', 'value' => '100.00', 'min_amount' => '0.00', 'max_uses' => 1, 'is_active' => true]);
        $service = app(OrderService::class);
        $expired = $service->createOrder($this->data($product, ['coupon_code' => 'SINGLE']));
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $service->expireOrders());
        $current = $service->createOrder($this->data($product, ['coupon_code' => 'SINGLE']));
        $this->epayNotify($current, '0.01');
        $this->epayNotify($expired, '0.01', ['trade_no' => 'late-conflicting-payment']);
        $this->assertSame('paid', $current->fresh()->status);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('late-conflicting-payment', $expired->fresh()->payment_no);
        $this->assertSame(0, $expired->cards()->where('status', 'sold')->count());
        $this->assertSame(1, $coupon->fresh()->used_count);
        // An operator can still reconcile this genuine received payment explicitly.
        $this->get('/order/pay/'.$expired->order_no)->assertOk()->assertSee('收到付款回执')->assertDontSee('countdown-timer');
        $this->assertTrue(app(OrderFulfilmentService::class)->fulfilManually($expired->fresh())->wasFulfilled());
        $this->assertSame('paid', $expired->fresh()->status);
    }

    public function test_replaying_the_public_payment_link_never_grants_card_access(): void
    {
        $order = app(OrderService::class)->createOrder($this->data($this->product()));
        $url = app(EpayService::class)->createPayment($order, 'alipay');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'safety-real-trade', '10.00', 'epay');
        $this->get('/payment/epay/return?'.http_build_query($params))->assertRedirect('/order/pay/'.$order->order_no)->assertSessionMissing('order_verified_ids');
        $this->get('/order/pay/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/detail/'.$order->order_no)->assertRedirect('/order/query');
    }
}
