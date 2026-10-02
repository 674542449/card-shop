<?php

namespace Tests\Feature;

use App\Models\{ApiOrderRequest, ApiToken, Card, Category, Coupon, NotificationDelivery, Order, Product, Setting};
use App\Services\{NotificationQueue, NotificationService, OrderService, RefundService};
use Illuminate\Support\Facades\{DB, Http, Mail};
use Tests\TestCase;

class BuyerReliabilityTest extends TestCase
{
    private Product $product;
    private ApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1', 'payment');
        Setting::set('epay_merchant_key', 'test-only-key', 'payment');
        $category = Category::create(['name' => 'Reliability', 'slug' => 'reliability', 'is_active' => true]);
        $this->product = Product::create(['category_id' => $category->id, 'name' => 'Reliability', 'slug' => 'reliability', 'price' => '100.00', 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true]);
        for ($i = 0; $i < 10; $i++) Card::create(['product_id' => $this->product->id, 'content' => 'PUBLIC-DUMMY-'.$i, 'status' => 'unsold']);
        $this->token = ApiToken::create(['name' => 'Reliability', 'token' => hash('sha256', 'reliability-token'), 'is_active' => true]);
        $this->withHeaders(['Authorization' => 'Bearer reliability-token']);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['product_id' => $this->product->id, 'quantity' => 1, 'email' => 'buyer@example.test', 'query_password' => 'buyer-password', 'payment_method' => 'alipay'], $changes);
    }

    private function paidOrder(): Order
    {
        $order = app(OrderService::class)->createOrder($this->input() + ['ip' => '192.0.2.90', 'api_token_id' => $this->token->id]);
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        $order->cards()->update(['status' => 'sold']);
        return $order->fresh();
    }

    public function test_retry_reuses_order_inventory_and_coupon_without_persisting_credentials(): void
    {
        $coupon = Coupon::create(['code' => 'ONLY-ONCE', 'type' => 'fixed', 'value' => '10', 'max_uses' => 1, 'is_active' => true]);
        $data = $this->input(['coupon_code' => $coupon->code, 'quantity' => 2]);
        $first = $this->postJson('/api/v1/orders', $data, ['Idempotency-Key' => 'client-order-1'])->assertCreated();
        $this->product->update(['is_active' => false]); // Replay survives catalog changes.
        $second = $this->postJson('/api/v1/orders', $data, ['Idempotency-Key' => 'client-order-1'])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, Order::count());
        $this->assertSame(2, Card::where('status', 'locked')->count());
        $this->assertSame(1, $coupon->fresh()->used_count);
        $raw = (array) DB::table('api_order_requests')->first();
        $this->assertStringNotContainsString('buyer-password', json_encode($raw));
        $this->assertStringNotContainsString('gateway.example.test', $raw['response_payload']);
    }

    public function test_changed_parameters_conflict_and_keys_are_token_scoped(): void
    {
        $this->postJson('/api/v1/orders', $this->input(), ['Idempotency-Key' => 'same-key'])->assertCreated();
        $this->postJson('/api/v1/orders', $this->input(['query_password' => 'other-password']), ['Idempotency-Key' => 'same-key'])->assertStatus(409);
        $this->assertSame(1, Order::count());
        ApiToken::create(['name' => 'Other', 'token' => hash('sha256', 'other-token'), 'is_active' => true]);
        $this->withHeaders(['Authorization' => 'Bearer other-token'])->postJson('/api/v1/orders', $this->input(), ['Idempotency-Key' => 'same-key'])->assertCreated();
        $this->assertSame(2, ApiOrderRequest::count());
        $this->assertSame(2, Order::count());
    }

    public function test_failed_gateway_response_replays_without_new_reservation(): void
    {
        $data = $this->input(['payment_method' => 'usdt_trc20']);
        $first = $this->postJson('/api/v1/orders', $data, ['Idempotency-Key' => 'failed-gateway'])->assertStatus(422);
        $second = $this->postJson('/api/v1/orders', $data, ['Idempotency-Key' => 'failed-gateway'])->assertStatus(422)->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, Order::count());
        $this->assertSame('closed', Order::first()->status);
        $this->assertSame(0, Card::where('status', 'locked')->count());
    }

    public function test_refunds_default_closed_for_every_source_but_existing_requests_remain_processable(): void
    {
        $order = $this->paidOrder();
        $service = app(RefundService::class);
        foreach (['buyer', 'admin', 'api'] as $source) {
            try { $service->request($order, '20.00', 'Reason', null, $source); $this->fail('Disabled refund must be rejected.'); }
            catch (\App\Exceptions\CheckoutException $e) { $this->assertStringContainsString('未开放', $e->getMessage()); }
        }
        $this->assertSame(0, $order->refunds()->count());
        Setting::set('refund_enabled', '1');
        $refund = $service->request($order, '20.00', 'Reason');
        Setting::set('refund_enabled', '0');
        $service->transition($refund, 'approved', null, 'INTERNAL-SECRET', 1, '已受理');
        $service->transition($refund->fresh(), 'completed', 'PRIVATE-REFERENCE', 'INTERNAL-SECRET', 1, '退款已完成');
        $this->assertSame('completed', $refund->fresh()->status);
        $this->withBuyerSession($order)->post('/order/refund/'.$order->order_no, ['amount' => '10', 'reason' => 'Reason'])->assertSessionHasErrors('error');
    }

    public function test_refund_balance_projection_and_three_theme_forms_do_not_reveal_internal_notes(): void
    {
        Setting::set('refund_enabled', '1');
        $order = $this->paidOrder();
        $service = app(RefundService::class);
        $refund = $service->request($order, '20.00', 'Reason');
        $service->transition($refund, 'approved', null, 'INTERNAL-SECRET', 1, '买家说明');
        $this->assertSame('80.00', $service->balance($order)['available']);
        $this->withBuyerSession($order)->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('max="80.00"', false)->assertSee('value="80.00"', false)->assertSee('买家说明')->assertDontSee('INTERNAL-SECRET');
        $response = $this->postJson('/api/v1/orders/'.$order->order_no.'/query', ['email' => $order->email, 'query_password' => 'buyer-password'])->assertOk()->assertJsonPath('data.refund_balance.available', '80.00')->assertJsonPath('data.refunds.0.customer_note', '买家说明');
        $this->assertArrayNotHasKey('note', $response->json('data.refunds.0'));
        $this->assertArrayNotHasKey('reference', $response->json('data.refunds.0'));
        $service->transition($refund->fresh(), 'completed', 'PRIVATE-REFERENCE', 'INTERNAL-SECRET', 1, '退款已完成');
        $rest = $service->request($order, '80.00', 'Remaining');
        $this->assertSame('0.00', $service->balance($order)['available']);
        $this->withBuyerSession($order)->get('/order/detail/'.$order->order_no)->assertDontSee('/order/refund/'.$order->order_no, false)->assertSee('退款完成时间');
        $service->transition($rest, 'rejected', null, null, 1, '请补充说明');
        $this->assertSame('80.00', $service->balance($order)['available']);
    }

    public function test_refund_notifications_are_transactional_and_mail_is_safe(): void
    {
        Setting::set('refund_enabled', '1');
        $order = $this->paidOrder();
        $service = app(RefundService::class);
        DB::beginTransaction();
        $service->request($order, '10.00', 'Rollback');
        DB::rollBack();
        $this->assertSame(0, NotificationDelivery::where('type', 'refund_email')->count());
        $refund = $service->request($order, '20.00', 'Reason');
        $service->transition($refund, 'rejected', null, 'INTERNAL-SECRET', 1, '<script>buyer-facing</script>');
        $delivery = NotificationDelivery::where('type', 'refund_email')->orderByDesc('id')->first();
        $this->assertStringNotContainsString('INTERNAL-SECRET', json_encode($delivery->payload));
        config(['mail.default' => 'array', 'mail.from.address' => 'shop@example.test', 'mail.from.name' => 'Test shop']);
        Mail::purge('array');
        $this->assertTrue(app(NotificationService::class)->sendRefundEmail($order, $delivery->payload));
        $html = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('INTERNAL-SECRET', $html);
        $this->assertStringNotContainsString('PUBLIC-DUMMY', $html);
    }
}
