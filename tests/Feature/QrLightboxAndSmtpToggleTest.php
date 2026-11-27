<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, NotificationDelivery, Order, Product, Setting};
use App\Services\{NotificationQueue, NotificationService, OrderCardReplacementService, OrderFulfilmentService, OrderService, RefundService};
use Illuminate\Support\Facades\{Hash, Http, Mail};
use Tests\TestCase;

class QrLightboxAndSmtpToggleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(): Admin
    {
        $admin = Admin::create(['username' => 'ops-admin', 'password' => Hash::make('ops-admin-password-123'), 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);

        return $admin;
    }

    private function productWithStock(int $stock = 3): Product
    {
        $category = Category::create(['name' => '测试分类', 'slug' => uniqid('cat-'), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => '测试商品',
            'slug' => uniqid('prod-'),
            'price' => '25.00',
            'min_quantity' => 1,
            'max_quantity' => 10,
            'is_active' => true,
        ]);
        for ($i = 0; $i < $stock; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'TEST-CARD-' . $i, 'status' => 'unsold']);
        }

        return $product;
    }

    private function createOrder(Product $product): Order
    {
        return app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'email' => 'buyer@example.test',
            'query_password' => 'query-pass-123',
            'quantity' => 1,
            'payment_method' => 'alipay',
            'ip' => '192.0.2.55',
        ]);
    }

    public function test_customer_service_qr_code_renders_click_to_zoom_dialog_across_all_four_themes(): void
    {
        Setting::set('contact_qr_image', '/storage/uploads/support-qr.png', 'site');
        Setting::set('contact_text', '工作时间 09:00-22:00 扫码添加客服', 'site');
        Setting::set('contact_url', 'https://t.me/cardshop_help', 'site');

        foreach (['default', 'mall', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme, 'site');

            $html = $this->get('/')->assertOk()->getContent();
            $this->assertStringContainsString('css/qr-lightbox.css', $html, "Missing lightbox CSS on theme {$theme}");
            $this->assertStringContainsString('command="show-modal"', $html, "Missing declarative command trigger on theme {$theme}");
            $this->assertStringContainsString('commandfor="contact-qr-dialog"', $html, "Missing commandfor target on theme {$theme}");
            $this->assertStringContainsString('<dialog id="contact-qr-dialog" class="cs-qr-dialog" closedby="any" aria-labelledby="contact-qr-dialog-title">', $html, "Missing native light-dismiss dialog on theme {$theme}");
            $this->assertStringContainsString('/storage/uploads/support-qr.png', $html, "Missing QR image src on theme {$theme}");

            // Single <h1> invariant & native <dialog> attributes without redundant ARIA overrides.
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//h1')->length, "Expected exactly 1 <h1> on theme {$theme}");
            $dialogNode = $xpath->query('//dialog[@id="contact-qr-dialog"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $dialogNode, "Missing <dialog id=contact-qr-dialog> on theme {$theme}");
            $this->assertFalse($dialogNode->hasAttribute('role'), "Native dialog must not add redundant role on theme {$theme}");
            $this->assertFalse($dialogNode->hasAttribute('aria-modal'), "Native dialog must not add redundant aria-modal on theme {$theme}");

            // Footer trigger & modal also render on subpages such as /order/query.
            $queryHtml = $this->get('/order/query')->assertOk()->getContent();
            $this->assertStringContainsString('id="contact-qr-dialog"', $queryHtml, "Missing dialog on /order/query for theme {$theme}");
            $this->assertStringContainsString('class="cs-qr-link-btn"', $queryHtml, "Missing footer QR trigger button on /order/query for theme {$theme}");
        }
    }

    public function test_qr_lightbox_markup_and_stylesheet_are_omitted_when_no_qr_is_configured(): void
    {
        Setting::set('contact_qr_image', '', 'site');
        Setting::set('site_theme', 'mall', 'site');

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('css/qr-lightbox.css', $html);
        $this->assertStringNotContainsString('id="contact-qr-dialog"', $html);
    }

    public function test_admin_can_toggle_smtp_mail_delivery_on_and_off_while_preserving_credentials(): void
    {
        $this->admin();

        $this->postJson('/api/admin/settings', [
            'mail_enabled' => false,
            'mail_host' => 'smtp.example.test',
            'mail_port' => 465,
            'mail_encryption' => 'ssl',
            'mail_username' => 'mailer@example.test',
            'mail_password' => 'smtp-secret-token',
            'mail_from_address' => 'mailer@example.test',
        ])->assertOk();

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('mail_enabled', '0')
            ->assertJsonPath('mail_host', 'smtp.example.test')
            ->assertJsonPath('mail_username', 'mailer@example.test')
            ->assertJsonPath('mail_password', '********')
            ->assertJsonPath('mail_from_address', 'mailer@example.test');

        $this->assertFalse(app(NotificationService::class)->emailEnabled());

        // Turn back on without touching credentials
        $this->postJson('/api/admin/settings', ['mail_enabled' => true])->assertOk();

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('mail_enabled', '1')
            ->assertJsonPath('mail_host', 'smtp.example.test')
            ->assertJsonPath('mail_password', '********');

        $this->assertTrue(app(NotificationService::class)->emailEnabled());
        $this->assertSame('smtp-secret-token', setting('mail_password'));
    }

    public function test_disabling_smtp_skips_enqueuing_processing_resend_and_test_email_without_breaking_fulfilment(): void
    {
        $admin = $this->admin();
        $transport = new class {
            public int $sent = 0;
            public function html($body, $callback): void { $this->sent++; }
            public function purge(string $name): void {}
        };
        Mail::swap($transport);

        Setting::set('mail_enabled', '0', 'email');
        Setting::set('mail_host', 'smtp.example.test', 'email');
        Setting::set('refund_enabled', '1', 'after_sales');

        // 1. Test email endpoint refuses cleanly without hitting transport
        $this->postJson('/api/admin/settings/test-email', ['email' => 'ops@example.test'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'SMTP 邮件投递服务已关闭，请先开启并保存后再发送测试邮件。']);
        $this->assertSame(0, $transport->sent);

        // 2. Manual mark-paid fulfils cards cleanly, does NOT enqueue order_email, and explains SMTP is closed
        $product = $this->productWithStock(5);
        $order = $this->createOrder($product);
        $this->postJson('/api/admin/orders/' . $order->id . '/paid')
            ->assertOk()
            ->assertJsonPath('message', '订单已确认支付，卡密可在查单页获取（SMTP 邮件投递已关闭，未发送邮件）。');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->deliveryCards()->count());
        $this->assertSame(0, NotificationDelivery::where('type', 'order_email')->count());

        // 3. Admin resend refuses cleanly when SMTP is disabled
        $this->postJson('/api/admin/orders/' . $order->id . '/resend')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'SMTP 邮件投递服务当前已关闭，请先在「系统设置 → 邮件设置」中开启后再补发卡密邮件。']);
        $this->assertSame(0, NotificationDelivery::where('type', 'order_email')->count());

        // 4. Card replacement does NOT enqueue order_email when SMTP is disabled
        $oldCardId = $order->deliveryCards()->first()->id;
        app(OrderCardReplacementService::class)->replace($order, [$oldCardId], '卡密失效换新', (string) \Illuminate\Support\Str::uuid(), $admin);
        $this->assertSame(0, NotificationDelivery::where('type', 'order_email')->count());

        // 5. Refund request & approval do NOT enqueue refund_email when SMTP is disabled
        $refund = app(RefundService::class)->request($order, '10.00', '部分退款测试');
        app(RefundService::class)->transition($refund, 'approved', null, '同意退款', $admin->id);
        $this->assertSame(0, NotificationDelivery::where('type', 'refund_email')->count());

        // 6. Pre-existing pending order_email & refund_email rows are marked skipped (never failed, zero SMTP sends)
        $queuedOrderMail = app(NotificationQueue::class)->enqueue('pre-existing-order:' . $order->id, 'order_email', $order);
        $queuedRefundMail = app(NotificationQueue::class)->enqueue('pre-existing-refund:' . $refund->id, 'refund_email', $order, $refund->customerProjection());
        app(NotificationQueue::class)->process(10);

        $this->assertSame('skipped', $queuedOrderMail->fresh()->status);
        $this->assertNull($queuedOrderMail->fresh()->last_error);
        $this->assertSame('skipped', $queuedRefundMail->fresh()->status);
        $this->assertNull($queuedRefundMail->fresh()->last_error);
        $this->assertSame(0, $transport->sent);

        // 7. Re-enabling SMTP immediately restores resend & delivery
        Setting::set('mail_enabled', '1', 'email');
        Setting::set('mail_host', '', 'email');
        $this->postJson('/api/admin/orders/' . $order->id . '/resend')->assertStatus(202);
        app(NotificationQueue::class)->process(10);
        $this->assertSame(1, $transport->sent);
        $this->assertSame('sent', NotificationDelivery::where('type', 'order_email')->orderByDesc('id')->first()->status);
    }
}
