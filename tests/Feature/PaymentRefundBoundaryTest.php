<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, Coupon, NotificationDelivery, Order, Product, Setting};
use App\Services\{OrderFulfilmentService, OrderService, PaymentReconciliationService, RefundService};
use Illuminate\Support\Facades\{Hash, Http};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentRefundBoundaryTest extends TestCase
{
    private function strandedPayment(string $method = 'alipay', bool $holdReservation = false): array
    {
        Http::preventStrayRequests();
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1', 'payment');
        Setting::set('epay_merchant_key', 'fixture-key', 'payment');
        Setting::set('epusdt_api_url', 'https://usdt.example.test', 'payment');
        Setting::set('epusdt_api_token', 'fixture-usdt-key', 'payment');
        Setting::set('refund_enabled', '1', 'order');
        $admin = Admin::create(['username' => uniqid('refund-audit-'), 'password' => Hash::make('fixture-password'), 'role' => 'owner', 'is_active' => true]);
        $category = Category::create(['name' => 'Fixture', 'slug' => uniqid('refund-category-'), 'is_active' => true]);
        $product = Product::create(['name' => 'Fixture', 'slug' => uniqid('refund-product-'), 'category_id' => $category->id,
            'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true]);
        $card = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-REFUND-CARD', 'status' => 'unsold']);
        if ($holdReservation) Coupon::create(['code' => 'DUMMY-RESERVED-COUPON', 'type' => 'fixed', 'value' => '1.00', 'max_uses' => 1, 'is_active' => true]);
        $order = app(OrderService::class)->createOrder(['product_id' => $product->id, 'quantity' => 1,
            'email' => 'audit@example.test', 'query_password' => 'fixture-password', 'payment_method' => $method, 'ip' => '192.0.2.77',
            'coupon_code' => $holdReservation ? 'DUMMY-RESERVED-COUPON' : null]);
        if (!$holdReservation) {
            $order->update(['expires_at' => now()->subMinute()]);
            app(OrderService::class)->expireOrder($order);
            $card->refresh()->update(['status' => 'disabled']);
        }
        $result = app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'fixture-trade', $order->total_amount,
            $method === 'alipay' ? 'epay' : 'epusdt', $holdReservation ? ['epay_type' => 'wxpay'] : []);
        $this->assertFalse($result->wasFulfilled());
        return [$order->fresh(), $card, $admin];
    }

    public static function blockedRefunds(): array
    {
        return [
            'callback / requested' => ['gateway', 'requested', '10.00'],
            'callback / approved' => ['gateway', 'approved', '10.00'],
            'callback / fully refunded' => ['gateway', 'completed', '10.00'],
            'callback / partially refunded' => ['gateway', 'completed', '1.00'],
            'manual / requested' => ['manual', 'requested', '10.00'],
            'manual / approved' => ['manual', 'approved', '10.00'],
            'manual / fully refunded' => ['manual', 'completed', '10.00'],
            'manual / partially refunded' => ['manual', 'completed', '1.00'],
            'USDT / requested' => ['usdt', 'requested', '10.00'],
            'USDT / approved' => ['usdt', 'approved', '10.00'],
            'USDT / fully refunded' => ['usdt', 'completed', '10.00'],
            'USDT / partially refunded' => ['usdt', 'completed', '1.00'],
        ];
    }

    #[DataProvider('blockedRefunds')]
    public function test_refund_blocks_undelivered_stock_even_after_replenishment(string $source, string $status, string $amount): void
    {
        [$order, $card, $admin] = $this->strandedPayment($source === 'usdt' ? 'usdt_trc20' : 'alipay');
        $refunds = app(RefundService::class);
        $refund = $refunds->request($order, $amount, 'Fixture refund');
        if ($status !== 'requested') $refund = $refunds->transition($refund, 'approved', null, null, $admin->id);
        if ($status === 'completed') $refunds->transition($refund, 'completed', 'DUMMY-REFUND-REFERENCE', null, $admin->id);
        $card->update(['status' => 'unsold']);
        if ($source === 'gateway') {
            $params = ['pid' => '1', 'out_trade_no' => $order->order_no, 'trade_no' => 'fixture-trade', 'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS'];
            ksort($params);
            $params['sign'] = md5(urldecode(http_build_query($params)).'fixture-key');
            $this->post('/payment/epay/notify', $params)->assertOk();
        } elseif ($source === 'usdt') {
            $params = ['order_id' => $order->order_no, 'trade_id' => 'fixture-trade', 'amount' => '10.00', 'status' => 2];
            ksort($params);
            $params['signature'] = md5(urldecode(http_build_query($params)).'fixture-usdt-key');
            $this->postJson('/payment/epusdt/notify', $params)->assertOk();
        } else {
            $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)])
                ->postJson('/api/admin/orders/'.$order->id.'/paid')->assertUnprocessable();
        }
        $this->assertSame($status === 'completed' && $amount === '10.00' ? 'closed' : 'expired', $order->fresh()->status);
        $this->assertSame('unsold', $card->fresh()->status);
        $this->assertNull($card->fresh()->order_id);
        $this->assertSame(0, NotificationDelivery::where('type', 'order_email')->count());
    }

    public function test_rejecting_refund_allows_original_valid_payment_to_deliver(): void
    {
        [$order, $card, $admin] = $this->strandedPayment();
        $refunds = app(RefundService::class);
        $refund = $refunds->request($order, '10.00', 'Fixture refund');
        $refunds->transition($refund, 'rejected', null, null, $admin->id);
        $card->update(['status' => 'unsold']);
        $this->assertTrue(app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'fixture-trade', '10.00', 'epay')->wasFulfilled());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_reconciliation_reports_collection_separately_from_delivery(): void
    {
        [$order] = $this->strandedPayment();
        Http::fake(['gateway.example.test/*' => Http::response(['code' => 1, 'pid' => '1', 'out_trade_no' => $order->order_no,
            'type' => 'alipay', 'status' => 1, 'money' => '10.00', 'trade_no' => 'fixture-trade'])]);
        $result = app(PaymentReconciliationService::class)->sync($order);
        $this->assertTrue($result['paid']);
        $this->assertFalse($result['delivered']);
        $this->assertTrue($result['needs_operator_attention']);
        $this->assertStringContainsString('未发货', $result['message']);
        $this->assertSame('expired', $order->fresh()->status);
    }

    public function test_a_distinct_valid_payment_can_deliver_after_the_original_receipt_was_refunded(): void
    {
        [$order, $card, $admin] = $this->strandedPayment();
        $refunds = app(RefundService::class);
        $refund = $refunds->request($order, '10.00', 'Fixture refund');
        $refund = $refunds->transition($refund, 'approved', null, null, $admin->id);
        $refunds->transition($refund, 'completed', 'DUMMY-REFUND', null, $admin->id);
        $card->update(['status' => 'unsold']);
        $this->assertFalse(app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'another-valid-trade', '10.00', 'epay')->wasFulfilled());
        $this->assertTrue(app(OrderFulfilmentService::class)->fulfilManually($order->fresh())->wasFulfilled());
        $this->assertSame('another-valid-trade', $order->fresh()->payment_no);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('completed', $refund->fresh()->status);
        $this->assertSame(1, $order->deliveryCards()->count());
    }

    public static function refundReviewStates(): array
    {
        return ['fully returned' => ['10.00', false], 'partial return' => ['1.00', true]];
    }

    #[DataProvider('refundReviewStates')]
    public function test_payment_review_agrees_with_remaining_collection_after_refund(string $amount, bool $requiresReview): void
    {
        [$order, , $admin] = $this->strandedPayment();
        $refunds = app(RefundService::class);
        $refund = $refunds->request($order, $amount, 'Fixture refund');
        $refund = $refunds->transition($refund, 'approved', null, null, $admin->id);
        $refunds->transition($refund, 'completed', 'DUMMY-REFUND', null, $admin->id);
        $this->assertSame($requiresReview, $order->fresh()->requiresPaymentReview());
        $this->assertSame($requiresReview, Order::whereKey($order->id)->withPaymentReviewFlag()->firstOrFail()->requiresPaymentReview());
        $this->assertSame($requiresReview, Order::whereKey($order->id)->paymentReview()->exists());
        app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'fixture-trade', '10.00', 'epay');
        $this->assertSame($requiresReview, $order->fresh()->requiresPaymentReview());
        $this->assertSame($requiresReview ? 'expired' : 'closed', $order->fresh()->status);
    }

    public function test_full_refund_of_an_unfulfilled_reservation_releases_stock_and_coupon_once(): void
    {
        [$order, $card, $admin] = $this->strandedPayment('alipay', true);
        $coupon = Coupon::firstOrFail();
        $this->assertSame('locked', $card->fresh()->status);
        $this->assertSame(1, $coupon->used_count);
        $service = app(RefundService::class);
        $refund = $service->request($order, '9.00', 'Fixture cancellation');
        $refund = $service->transition($refund, 'approved', null, null, $admin->id);
        $service->transition($refund, 'completed', 'DUMMY-REFUND', null, $admin->id);
        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame('unsold', $card->fresh()->status);
        $this->assertNull($card->fresh()->order_id);
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)])
            ->putJson('/api/admin/refunds/'.$refund->id, ['status' => 'completed', 'reference' => 'DUMMY-AGAIN'])->assertUnprocessable();
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->getJson('/order/pay/'.$order->order_no)->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('payment_review', false);
        $this->get('/order/pay/'.$order->order_no)->assertOk()->assertSee('订单已关闭')->assertDontSee('付款待核对');
    }

    public function test_reconciliation_of_a_fully_refunded_collection_does_not_create_false_operator_work(): void
    {
        [$order, , $admin] = $this->strandedPayment();
        $service = app(RefundService::class);
        $refund = $service->request($order, '10.00', 'Fixture cancellation');
        $refund = $service->transition($refund, 'approved', null, null, $admin->id);
        $service->transition($refund, 'completed', 'DUMMY-REFUND', null, $admin->id);
        Http::fake(['gateway.example.test/*' => Http::response(['code' => 1, 'pid' => '1', 'out_trade_no' => $order->order_no,
            'type' => 'alipay', 'status' => 1, 'money' => '10.00', 'trade_no' => 'fixture-trade'])]);
        $result = app(PaymentReconciliationService::class)->sync($order->fresh());
        $this->assertTrue($result['paid']);
        $this->assertFalse($result['delivered']);
        $this->assertFalse($result['needs_operator_attention']);
        $this->assertStringContainsString('已退款', $result['message']);
        $this->assertFalse($order->fresh()->requiresPaymentReview());
    }
}
