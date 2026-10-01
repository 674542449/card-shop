<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, ApiToken, Card, Category, NotificationDelivery, Order, PaymentReceipt, Product, Setting};
use App\Services\{CardService, NotificationQueue, NotificationService, OrderFulfilmentService, OrderService, StockAlertService};
use Illuminate\Support\Facades\{DB, Hash, Http, Mail};
use Tests\TestCase;

class OperationalSafetyTest extends TestCase
{
    private NotificationService $sender;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->sender = $this->createMock(NotificationService::class);
        $this->sender->method('telegramConfigured')->willReturn(true);
        $this->instance(NotificationService::class, $this->sender);
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1', 'payment');
        Setting::set('epay_merchant_key', 'audit-only-key', 'payment');
    }

    private function product(int $stock = 8): Product
    {
        $category = Category::create(['name' => 'Audit', 'slug' => uniqid('audit-'), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Audit <product>', 'slug' => uniqid('audit-'), 'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 20, 'low_stock_threshold' => 2, 'is_active' => true]);
        for ($i = 0; $i < $stock; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'audit-dummy-'.$i, 'status' => 'unsold']);
        }
        return $product;
    }

    private function data(Product $product, array $changes = []): array
    {
        return array_replace(['product_id' => $product->id, 'email' => 'audit@example.test', 'query_password' => 'audit-password', 'quantity' => 1, 'payment_method' => 'alipay', 'ip' => '192.0.2.90'], $changes);
    }

    private function order(Product $product): Order
    {
        return app(OrderService::class)->createOrder($this->data($product));
    }

    private function token(array $changes = []): ApiToken
    {
        return ApiToken::create(array_replace(['name' => 'Audit', 'token' => hash('sha256', 'audit-only-token'), 'is_active' => true], $changes))->refresh();
    }

    private function admin(): void
    {
        $admin = Admin::create(['username' => 'audit', 'password' => Hash::make('audit-password-123')]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
    }

    public function test_closed_underpayment_and_wrong_channel_create_no_receipt_or_alert(): void
    {
        $order = $this->order($this->product());
        app(OrderService::class)->closeOrder($order);
        foreach ([['0.01', 'epay'], ['10.00', 'epusdt']] as [$amount, $channel]) {
            $result = app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'bad-trade', $amount, $channel);
            $this->assertSame('refused', $result->status);
            $this->assertFalse($result->needsOperatorAttention);
        }
        $this->assertSame(0, PaymentReceipt::count());
        $this->assertSame(0, NotificationDelivery::count());
        $this->assertNull($order->fresh()->payment_no);
    }

    public function test_verified_closed_payment_is_preserved_and_alert_deduplicated(): void
    {
        $order = $this->order($this->product());
        app(OrderService::class)->closeOrder($order);
        for ($i = 0; $i < 2; $i++) {
            $result = app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'closed-trade', '10.00', 'epay');
            $this->assertSame('orphaned', $result->status);
        }
        $this->assertSame('closed-trade', $order->fresh()->payment_no);
        $this->assertSame('10.00', $order->fresh()->payment_received_amount);
        $this->assertNotNull($order->fresh()->payment_received_at);
        $this->assertNotNull($order->fresh()->payment_review_reason);
        $this->assertSame(1, PaymentReceipt::count());
        $this->assertSame(1, NotificationDelivery::where('type', 'payment_review')->count());
        $this->admin();
        $this->getJson('/api/admin/orders?payment_review=1')->assertJsonPath('total', 1);
        $this->getJson('/api/admin/orders/'.$order->id)->assertJsonPath('payment_receipts.0.trade_no', 'closed-trade');
    }

    public function test_stock_shortage_preserves_payment_and_manual_repair_clears_review(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $order->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireOrders();
        $product->cards()->delete();
        $result = app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, 'stock-trade', '10.00', 'epay');
        $this->assertTrue($result->needsOperatorAttention);
        $this->assertSame('stock-trade', $order->fresh()->payment_no);
        $this->assertSame('expired', $order->fresh()->status);
        app(CardService::class)->importCards($product->id, 'replacement-card');
        $this->assertTrue(app(OrderFulfilmentService::class)->fulfilManually($order)->wasFulfilled());
        $this->assertNull($order->fresh()->payment_review_reason);
        $this->assertSame(1, NotificationDelivery::where('type', 'order_email')->count());
    }

    public function test_pending_review_disables_payment_in_all_themes_and_polling(): void
    {
        $order = $this->order($this->product());
        $order->update(['payment_no' => 'verified-pending', 'payment_review_reason' => '库存需核对']);
        foreach (['default', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme);
            $this->get('/order/pay/'.$order->order_no)->assertOk()->assertSee('收到付款回执')->assertDontSee('countdown-timer')->assertDontSee('gateway.example.test');
        }
        $this->getJson('/order/pay/'.$order->order_no)->assertJsonPath('payment_review', true)->assertJsonPath('status', 'pending');
    }

    public function test_gateway_trade_cannot_be_reused_for_a_second_order(): void
    {
        $product = $this->product();
        $first = $this->order($product);
        $second = $this->order($product);
        $fulfil = app(OrderFulfilmentService::class);
        $this->assertTrue($fulfil->fulfilFromGateway($first->order_no, 'unique-trade', '10.00', 'epay')->wasFulfilled());
        $this->assertSame('refused', $fulfil->fulfilFromGateway($second->order_no, 'unique-trade', '10.00', 'epay')->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(1, PaymentReceipt::count());
    }

    public function test_payment_commits_notification_queue_without_sending_and_replay_does_not_duplicate(): void
    {
        $this->sender->expects($this->never())->method('sendOrderEmail');
        $this->sender->expects($this->never())->method('sendTelegramNotification');
        $order = $this->order($this->product());
        $fulfil = app(OrderFulfilmentService::class);
        $this->assertTrue($fulfil->fulfilFromGateway($order->order_no, 'paid-trade', '10.00', 'epay')->wasFulfilled());
        $this->assertFalse($fulfil->fulfilFromGateway($order->order_no, 'paid-trade', '10.00', 'epay')->wasFulfilled());
        $this->assertSame(2, NotificationDelivery::count());
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertStringNotContainsString('audit-dummy', NotificationDelivery::all()->toJson());
    }

    public function test_rollback_cannot_leave_email_for_an_uncommitted_sale(): void
    {
        $order = $this->order($this->product());
        DB::beginTransaction();
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $this->assertSame(2, NotificationDelivery::count());
        DB::rollBack();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_email_backoff_exhaustion_and_admin_retry_are_persistent(): void
    {
        $this->sender->method('sendOrderEmail')->willReturn(false);
        $this->sender->method('notifyNewOrder')->willReturn(true);
        $order = $this->order($this->product());
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $queue = app(NotificationQueue::class);
        $queue->process();
        $email = NotificationDelivery::where('type', 'order_email')->firstOrFail();
        $this->assertSame('pending', $email->status);
        $this->assertSame(1, $email->attempts);
        $this->assertTrue($email->available_at->isFuture());
        $this->assertSame(0, $queue->process());
        for ($i = 0; $i < 4; $i++) {
            $this->travel(2)->hours();
            $queue->process();
        }
        $this->assertSame('failed', $email->fresh()->status);
        $this->assertSame(5, $email->fresh()->attempts);
        $this->postJson('/api/admin/notifications/'.$email->id.'/retry')->assertUnauthorized();
        $this->admin();
        $this->getJson('/api/admin/notifications?status=failed')->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.payload');
        $this->postJson('/api/admin/notifications/'.$email->id.'/retry')->assertStatus(202);
        $this->assertSame(0, $email->fresh()->attempts);
        $this->assertSame('pending', $email->fresh()->status);
    }

    public function test_worker_reclaims_crashed_attempt_and_sends_once(): void
    {
        $this->sender->expects($this->once())->method('sendOrderEmail')->willReturn(true);
        $this->sender->method('notifyNewOrder')->willReturn(true);
        $order = $this->order($this->product());
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $email = NotificationDelivery::where('type', 'order_email')->firstOrFail();
        $email->update(['status' => 'processing', 'attempts' => 1, 'reserved_at' => now()->subMinutes(6), 'lease_token' => '11111111-1111-1111-1111-111111111111']);
        app(NotificationQueue::class)->process();
        $this->assertSame('sent', $email->fresh()->status);
        $this->assertSame(2, $email->fresh()->attempts);
        $this->assertSame(0, app(NotificationQueue::class)->process());
    }

    public function test_resend_queues_once_and_never_claims_email_was_already_sent(): void
    {
        $order = $this->order($this->product());
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $this->admin();
        $this->postJson('/api/admin/orders/'.$order->id.'/resend')->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/admin/orders/'.$order->id.'/resend')->assertStatus(202);
        $this->assertSame(1, NotificationDelivery::where('type', 'order_email')->count());
    }

    public function test_api_query_uses_body_only_and_never_replays_legacy_url_credentials(): void
    {
        $token = $this->token();
        $order = app(OrderService::class)->createOrder($this->data($this->product()) + ['api_token_id' => $token->id]);
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $url = '/api/v1/orders/'.$order->order_no;
        $this->withToken('audit-only-token')->getJson($url.'?email=audit@example.test&query_password=audit-password')->assertStatus(405)->assertDontSee('audit-dummy');
        $this->postJson($url.'/query?query_password=audit-password', ['email' => $order->email])->assertUnprocessable();
        $this->postJson($url.'/query', ['email' => $order->email, 'query_password' => 'wrong'])->assertNotFound();
        $response = $this->postJson($url.'/query', ['email' => strtoupper($order->email), 'query_password' => 'audit-password'])->assertOk()->assertJsonPath('data.cards.0', 'audit-dummy-0');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('audit-password', $response->getContent());
    }

    public function test_api_pending_order_quota_applies_across_ips_and_releases_after_close(): void
    {
        $product = $this->product();
        $token = $this->token(['max_pending_orders' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(90 + $i)])->withToken('audit-only-token')->postJson('/api/v1/orders', $this->data($product))->assertCreated();
        }
        $this->postJson('/api/v1/orders', $this->data($product))->assertUnprocessable();
        $this->assertSame(3, Order::where('api_token_id', $token->id)->count());
        $this->assertSame(3, $product->cards()->where('status', 'locked')->count());
        app(OrderService::class)->closeOrder(Order::firstOrFail());
        $this->postJson('/api/v1/orders', $this->data($product))->assertCreated();
        $this->assertSame(3, Order::where('status', 'pending')->count());
    }

    public function test_api_quantity_quota_rejects_without_stock_or_coupon_side_effects(): void
    {
        $product = $this->product();
        $this->token(['max_pending_quantity' => 2]);
        $this->withToken('audit-only-token')->postJson('/api/v1/orders', $this->data($product, ['quantity' => 3]))->assertUnprocessable();
        $this->assertSame(0, Order::count());
        $this->assertSame(8, $product->stockCount());
    }

    public function test_expired_deadline_does_not_free_quota_before_reserved_cards_are_released(): void
    {
        $product = $this->product();
        $this->token(['max_pending_orders' => 1, 'max_pending_quantity' => 1]);
        $this->withToken('audit-only-token')->postJson('/api/v1/orders', $this->data($product))->assertCreated();
        Order::firstOrFail()->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/orders', $this->data($product))->assertUnprocessable();
        $this->assertSame(1, $product->cards()->where('status', 'locked')->count());
        $this->assertSame(1, app(OrderService::class)->expireOrders());
        $this->assertSame(0, $product->cards()->where('status', 'locked')->count());
        $this->postJson('/api/v1/orders', $this->data($product))->assertCreated();
        $this->assertSame(1, $product->cards()->where('status', 'locked')->count());
    }

    public function test_api_rate_is_token_scoped_and_admin_quota_validation_works(): void
    {
        $token = $this->token(['requests_per_minute' => 1]);
        $this->withToken('audit-only-token')->getJson('/api/v1/products')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.150'])->getJson('/api/v1/products')->assertStatus(429)->assertHeader('Retry-After');
        $this->admin();
        $this->putJson('/api/admin/api-tokens/'.$token->id, ['max_pending_orders' => 0])->assertUnprocessable();
        $this->putJson('/api/admin/api-tokens/'.$token->id, ['requests_per_minute' => 20, 'max_pending_orders' => 10])->assertOk()->assertJsonPath('max_pending_orders', 10);
        $this->getJson('/api/v1/products')->assertOk();
    }

    public function test_low_stock_alert_is_deduplicated_and_rearmed_after_refill(): void
    {
        $product = $this->product(2);
        $stock = app(StockAlertService::class);
        $this->assertTrue($stock->check($product->id));
        $this->assertFalse($stock->check($product->id));
        $this->assertSame(1, NotificationDelivery::where('type', 'low_stock')->count());
        $this->assertStringContainsString('&lt;product&gt;', NotificationDelivery::first()->payload['message']);
        app(CardService::class)->importCards($product->id, 'refill-one');
        $this->assertFalse($product->fresh()->low_stock_notified);
        $product->cards()->where('content', 'refill-one')->delete();
        $this->assertTrue($stock->check($product->id));
        $this->assertSame(2, NotificationDelivery::where('type', 'low_stock')->count());
    }

    public function test_dashboard_lists_low_stock_and_threshold_can_be_disabled(): void
    {
        $product = $this->product(2);
        $this->admin();
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('low_stock_count', 1)->assertJsonPath('low_stock_products.0.stock_count', 2);
        $this->getJson('/api/admin/products?low_stock=1')->assertJsonPath('total', 1);
        $this->putJson('/api/admin/products/'.$product->id, ['name' => $product->name, 'category_id' => $product->category_id, 'price' => '10.00', 'low_stock_threshold' => null])->assertOk();
        $this->assertNull($product->fresh()->low_stock_threshold);
        $this->getJson('/api/admin/dashboard')->assertJsonPath('low_stock_count', 0);
        $this->putJson('/api/admin/products/'.$product->id, ['name' => $product->name, 'category_id' => $product->category_id, 'price' => '10.00', 'low_stock_threshold' => -1])->assertUnprocessable();
    }

    public function test_telegram_http_200_with_rejected_body_is_retried(): void
    {
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_bot_token', 'dummy-test-bot');
        Setting::set('telegram_chat_id', 'dummy-test-chat');
        $this->instance(NotificationService::class, new NotificationService());
        Http::fake(['https://api.telegram.org/*' => Http::sequence()->push(['ok' => false], 200)->push(['ok' => true], 200)]);
        $queue = app(NotificationQueue::class);
        $delivery = $queue->enqueue('dummy-low-stock', 'low_stock', null, ['message' => 'test only']);
        $queue->process();
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->travel(2)->minutes();
        $queue->process();
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_email_worker_renders_sold_secrets_without_external_transport(): void
    {
        $product = $this->product(1);
        $product->cards()->first()->update(['content' => '0<&dummy>']);
        $order = $this->order($product);
        app(OrderFulfilmentService::class)->fulfilManually($order);
        Setting::set('mail_host', '');
        $transport = new class {
            public string $body = '';
            public function html($body, $callback): void { $this->body = $body; }
        };
        Mail::swap($transport);
        $this->instance(NotificationService::class, new NotificationService());
        app(NotificationQueue::class)->process();
        $this->assertStringContainsString('0&lt;&amp;dummy&gt;', $transport->body);
        $this->assertSame('sent', NotificationDelivery::where('type', 'order_email')->first()->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_long_lived_sender_restores_environment_after_smtp_settings_are_cleared(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'environment.example.test']);
        $service = new NotificationService();
        $configure = new \ReflectionMethod($service, 'configureMailer');
        Setting::set('mail_host', 'first.example.test');
        Setting::set('mail_username', 'dummy-username');
        Setting::set('mail_password', 'dummy-password');
        $configure->invoke($service);
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('first.example.test', config('mail.mailers.smtp.host'));
        Setting::set('mail_host', 'second.example.test');
        $configure->invoke($service);
        $this->assertSame('second.example.test', config('mail.mailers.smtp.host'));
        Setting::set('mail_host', '');
        $configure->invoke($service);
        $this->assertSame('log', config('mail.default'));
        $this->assertSame('environment.example.test', config('mail.mailers.smtp.host'));
        $this->assertNotSame('dummy-password', config('mail.mailers.smtp.password'));
    }
}
