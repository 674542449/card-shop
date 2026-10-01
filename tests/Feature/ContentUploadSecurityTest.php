<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Article, ArticleCategory, Card, Category, Order, Product, Setting};
use App\Services\{AssetMaintenanceService, EpayService, EpusdtService, OrderService};
use App\Support\ContentRenderer;
use DOMDocument;
use DOMXPath;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Cache, Http, Storage};
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentUploadSecurityTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aQmcAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(string $role = 'owner', array $permissions = []): void
    {
        $admin = Admin::create(['username' => 'content-security-admin', 'password' => 'local-test-password', 'role' => $role,
            'permissions' => $permissions, 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
    }

    /** Real bytes and a real finfo result; Laravel's fake File overrides getMimeType. */
    private function upload(string $name, string $bytes, callable $check): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shop-upload-');
        file_put_contents($path, $bytes);
        try {
            $check(new UploadedFile($path, $name, 'image/png', UPLOAD_ERR_OK, true));
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    public function test_anonymous_and_read_only_staff_cannot_upload_or_change_configuration(): void
    {
        Storage::fake('public');
        $this->postJson('/api/admin/upload')->assertUnauthorized();
        $this->postJson('/api/admin/settings', ['contact_url' => 'javascript:alert(1)'])->assertUnauthorized();
        $this->getJson('/api/admin/maintenance/backups')->assertUnauthorized();
        $this->admin('staff', ['catalog:read']);
        $this->upload('image.png', base64_decode(self::PNG), function ($file) {
            $this->postJson('/api/admin/upload', ['file' => $file])->assertForbidden();
        });
        $this->postJson('/api/admin/settings', ['site_name' => 'forged'])->assertForbidden();
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseMissing('settings', ['value' => 'forged']);
    }

    public function test_real_image_uses_random_filename_and_cannot_follow_client_path(): void
    {
        Storage::fake('public');
        $this->admin('staff', ['content:write']);
        $paths = [];
        for ($i = 0; $i < 2; $i++) {
            $this->upload('../../image.png', base64_decode(self::PNG), function ($file) use (&$paths) {
                $response = $this->postJson('/api/admin/upload', ['file' => $file])->assertOk();
                $path = $response->json('path');
                $this->assertMatchesRegularExpression('~^uploads/\d{4}/\d{2}/[A-Za-z0-9]{32}\.png$~D', $path);
                $this->assertSame('/storage/'.$path, $response->json('url'));
                $this->assertSame(base64_decode(self::PNG), Storage::disk('public')->get($path));
                $paths[] = $path;
            });
        }
        $this->assertNotSame($paths[0], $paths[1]);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_real_image_with_php_client_extension_is_rejected(): void
    {
        Storage::fake('public');
        $this->admin('staff', ['content:write']);
        $this->upload('../../shell.php', base64_decode(self::PNG), function ($file) {
            $this->postJson('/api/admin/upload', ['file' => $file])->assertUnprocessable();
        });
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public static function activeUploads(): array
    {
        return [
            'SVG with script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'PHP disguised as PNG' => ['<?php echo "executed"; ?>'],
            'HTML disguised as PNG' => ['<!doctype html><html><script>alert(1)</script></html>'],
            'ZIP disguised as PNG' => ["PK\x03\x04".str_repeat('x', 100)],
        ];
    }

    #[DataProvider('activeUploads')]
    public function test_mime_spoofing_cannot_store_active_content(string $bytes): void
    {
        Storage::fake('public');
        $this->admin();
        $this->upload('trusted.png', $bytes, function ($file) {
            $this->postJson('/api/admin/upload', ['file' => $file])->assertUnprocessable();
        });
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_oversized_image_is_rejected_before_storage(): void
    {
        Storage::fake('public');
        $this->admin();
        $this->upload('huge.png', base64_decode(self::PNG).str_repeat('x', 2 * 1024 * 1024), function ($file) {
            $this->postJson('/api/admin/upload', ['file' => $file])->assertUnprocessable();
        });
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_settings_reject_executable_url_schemes_atomically_and_accept_legitimate_links(): void
    {
        $this->admin();
        Setting::set('site_name', 'original');
        foreach (['javascript:alert(1)', "java\tscript:alert(1)", 'data:text/html,<script>alert(1)</script>', '//untrusted.example.test', '/\\untrusted.example.test'] as $value) {
            $this->postJson('/api/admin/settings', ['site_name' => 'changed', 'contact_url' => $value])
                ->assertUnprocessable()->assertJsonValidationErrors('contact_url');
            $this->assertSame('original', Setting::where('key', 'site_name')->value('value'));
        }
        foreach (['https://support.example.test/help', '/articles/help', 'mailto:help@example.test', 'tel:+8613800000000'] as $value) {
            $this->postJson('/api/admin/settings', ['contact_url' => $value])->assertOk();
            $this->assertSame($value, Setting::where('key', 'contact_url')->value('value'));
        }
        $this->postJson('/api/admin/settings', ['epay_api_url' => 'file:///etc/passwd', 'epusdt_api_url' => 'https://user:secret@gateway.example.test', 'site_logo' => 'data:image/svg+xml,<svg/>'])
            ->assertUnprocessable()->assertJsonValidationErrors(['epay_api_url', 'epusdt_api_url', 'site_logo']);
        // Authorized private/self-hosted gateways remain configurable.
        $this->postJson('/api/admin/settings', ['epay_api_url' => 'http://127.0.0.1:8001/pay', 'epusdt_api_url' => 'http://192.168.1.10/pay', 'site_logo' => '/storage/logo.png', 'unknown_setting' => 'forged'])->assertOk();
        $this->assertDatabaseMissing('settings', ['key' => 'unknown_setting']);
    }

    public function test_old_executable_contact_and_icon_values_are_not_rendered_by_any_layout(): void
    {
        Setting::set('contact_url', 'javascript:alert(1)');
        Setting::set('site_logo', 'data:image/svg+xml,<svg onload=alert(1)>');
        Setting::set('site_favicon', 'javascript:alert(1)');
        foreach (['default', 'modern', 'minimal'] as $theme) {
            $html = view('templates.'.$theme.'.layout', ['errors' => new ViewErrorBag()])->render();
            $this->assertStringNotContainsString('javascript:alert(1)', $html);
            $this->assertStringNotContainsString('data:image/svg+xml', $html);
        }
    }

    public static function executableContent(): array
    {
        return [
            'HTML events' => ['<p>safe<img src="/storage/example.png" onerror="alert(1)"></p><script>alert(1)</script>'],
            'HTML SVG and embed' => ['<div>safe<svg onload="alert(1)"></svg><iframe srcdoc="<script>alert(1)</script>"></iframe><object data="javascript:alert(1)"></object></div>'],
            'HTML javascript link' => ['<p>safe<a href="jav&#x61;script:alert(1)">click</a></p>'],
            'HTML CSS expression' => ['<p style="color:red;background-image:url(javascript:alert(1));width:expression(alert(1))">safe</p>'],
            'Markdown active HTML' => ["# safe\n\n<svg onload=alert(1)>\n\n<script>alert(1)</script>"],
            'Markdown unsafe link' => ['# safe'."\n\n[x](javascript:alert%281%29)\n\n![x](data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=)"],
            'Unterminated raw HTML advisory regression' => ["<div>safe\n<script\n\n<span src=\"/evil.js\">"],
            'Unterminated Markdown raw HTML' => ["# safe\n\n<script\n\n<span src=\"/evil.js\">"],
        ];
    }

    #[DataProvider('executableContent')]
    public function test_rich_text_and_markdown_remove_executable_markup(string $raw): void
    {
        $html = ContentRenderer::toHtml($raw);
        $this->assertStringContainsString('safe', $html);
        $this->assertSafeContentHtml($html);
    }

    private function assertSafeContentHtml(string $html): void
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<!doctype html><html><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//script|//svg|//iframe|//object|//embed|//form|//style')->length);
        foreach ($xpath->query('//@*') as $attribute) {
            $this->assertFalse(str_starts_with(strtolower($attribute->nodeName), 'on'), 'Event handler survived HTML rendering.');
            if (in_array(strtolower($attribute->nodeName), ['href', 'src'], true)) {
                $value = strtolower(preg_replace('/[\x00-\x20]/', '', html_entity_decode($attribute->nodeValue)));
                $this->assertFalse(str_starts_with($value, 'javascript:') || str_starts_with($value, 'vbscript:') || str_starts_with($value, 'data:'), 'Active URL survived HTML rendering.');
            }
            if ($attribute->nodeName === 'style') {
                $this->assertDoesNotMatchRegularExpression('/expression\s*\(|url\s*\(|behavior\s*:/i', $attribute->nodeValue);
            }
        }
    }

    public function test_public_pages_use_sanitizer_for_stored_announcements_product_and_article(): void
    {
        $raw = '<p>safe<img src="/storage/example.png" onerror="alert(1)"></p><script id="stored-xss">alert(1)</script>';
        Setting::set('site_announcement', $raw);
        Setting::set('popup_announcement', $raw);
        $category = Category::create(['name' => 'Content security', 'slug' => 'content-security', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Security product', 'slug' => 'security-product', 'price' => 10, 'description' => $raw, 'is_active' => true]);
        $articleCategory = ArticleCategory::create(['name' => 'Security guides', 'slug' => 'security-guides']);
        $article = Article::create(['article_category_id' => $articleCategory->id, 'title' => 'Security article', 'slug' => 'security-article', 'content' => $raw, 'is_published' => true]);
        foreach (['/', '/product/'.$product->slug, '/articles/'.$article->slug] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('safe');
            $response->assertDontSee('id="stored-xss"', false)->assertDontSee('onerror=', false);
        }
    }

    public function test_asset_paths_cannot_escape_upload_directory(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('local')->put('private-secret.txt', 'private-secret');
        $this->admin();
        foreach (['../../.env', 'uploads/2026/01/../../private-secret.txt', 'uploads\\2026\\01\\'.str_repeat('a', 32).'.png', 'C:/Windows/system.ini', 'php://filter/resource=.env'] as $path) {
            foreach (['quarantine', 'restore'] as $action) {
                $this->postJson('/api/admin/maintenance/assets', ['path' => $path, 'action' => $action])->assertUnprocessable();
            }
        }
        $this->assertSame('private-secret', Storage::disk('local')->get('private-secret.txt'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_quarantine_write_preserves_original_upload(): void
    {
        $public = Storage::fake('public');
        $path = 'uploads/2026/01/'.str_repeat('a', 32).'.png';
        $public->put($path, 'original-image');
        touch($public->path($path), time() - 8 * 86400);
        $local = $this->createMock(FilesystemAdapter::class);
        $local->expects($this->once())->method('put')->with('asset-quarantine/'.$path, 'original-image')->willReturn(false);
        $manager = $this->createMock(FilesystemManager::class);
        $manager->method('disk')->willReturnMap([['public', $public], ['local', $local]]);
        Storage::swap($manager);
        try {
            app(AssetMaintenanceService::class)->quarantine($path);
            $this->fail('A failed destination write must fail the operation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('原文件已保留', $e->getMessage());
        }
        $this->assertSame('original-image', $public->get($path));
    }

    public function test_failed_restore_write_preserves_quarantined_upload(): void
    {
        $local = Storage::fake('local');
        $path = 'uploads/2026/01/'.str_repeat('a', 32).'.png';
        $local->put('asset-quarantine/'.$path, 'original-image');
        $public = $this->createMock(FilesystemAdapter::class);
        $public->expects($this->once())->method('put')->with($path, 'original-image')->willReturn(false);
        $manager = $this->createMock(FilesystemManager::class);
        $manager->method('disk')->willReturnMap([['public', $public], ['local', $local]]);
        Storage::swap($manager);
        try {
            app(AssetMaintenanceService::class)->restore($path);
            $this->fail('A failed destination write must fail the operation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('归档文件已保留', $e->getMessage());
        }
        $this->assertSame('original-image', $local->get('asset-quarantine/'.$path));
    }

    private function usdtOrder(): Order
    {
        Setting::set('epusdt_api_url', 'https://gateway.example.test');
        Setting::set('epusdt_api_token', 'local-secret-token');
        $category = Category::create(['name' => 'Gateway security', 'slug' => 'gateway-security', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Gateway product', 'slug' => 'gateway-product', 'price' => 10, 'is_active' => true]);
        Card::create(['product_id' => $product->id, 'content' => 'private-test-card', 'status' => 'unsold']);

        return app(OrderService::class)->createOrder(['product_id' => $product->id, 'email' => 'buyer@example.test', 'query_password' => 'buyer-password',
            'quantity' => 1, 'payment_method' => 'usdt_trc20', 'ip' => '192.0.2.45']);
    }

    public function test_usdt_gateway_cannot_redirect_signed_post_or_publish_executable_payment_url(): void
    {
        $order = $this->usdtOrder();
        Http::fake(function (OutgoingRequest $request, array $options) {
            $this->assertSame(false, $options['allow_redirects']);
            $this->assertSame('POST', $request->method());
            $this->assertNotEmpty($request['signature']);

            return Http::response('', 307, ['Location' => 'https://untrusted.example.test/collect']);
        });
        try {
            app(EpusdtService::class)->createPayment($order, 'trc20');
            $this->fail('A redirect is not a successful gateway response.');
        } catch (\RuntimeException $e) {
            $this->assertSame('USDT支付接口请求失败', $e->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull(Cache::get('payment_url:'.$order->order_no));
    }

    public static function unsafePaymentUrls(): array
    {
        return ['javascript' => ['javascript:alert(1)'], 'data' => ['data:text/html,<script>alert(1)</script>'],
            'protocol relative' => ['//untrusted.example.test/pay'], 'nested value' => [['javascript:alert(1)']]];
    }

    #[DataProvider('unsafePaymentUrls')]
    public function test_usdt_gateway_invalid_payment_url_never_reaches_payment_cache(mixed $url): void
    {
        $order = $this->usdtOrder();
        Http::fake(['gateway.example.test/*' => Http::response(['status_code' => 200, 'data' => ['payment_url' => $url, 'trade_id' => 'external-trade']])]);
        try {
            app(EpusdtService::class)->createPayment($order, 'trc20');
            $this->fail('An active or invalid payment URL must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertSame('USDT支付接口返回的支付链接无效', $e->getMessage());
        }
        $this->assertNull(Cache::get('payment_url:'.$order->order_no));
        $this->assertNull($order->fresh()->gateway_trade_no);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }

    public function test_old_invalid_epay_configuration_cannot_produce_executable_payment_link(): void
    {
        Setting::set('epay_api_url', 'javascript:alert(1)');
        Setting::set('epay_merchant_id', '1');
        Setting::set('epay_merchant_key', 'local-test-key');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('网关地址无效');
        app(EpayService::class)->createPayment(new Order(), 'alipay');
    }

    public function test_legacy_unsafe_usdt_cached_url_is_replaced_by_valid_gateway_response(): void
    {
        $order = $this->usdtOrder();
        Cache::put('epusdt_payment:'.$order->order_no, ['payment_url' => 'javascript:alert(1)', 'trade_id' => 'old-trade'], 60);
        Cache::put('payment_url:'.$order->order_no, 'javascript:alert(1)', 60);
        Http::fake(['gateway.example.test/*' => Http::response(['status_code' => 200, 'data' => ['payment_url' => 'https://gateway.example.test/pay/1', 'trade_id' => 'valid-trade']])]);
        $result = app(EpusdtService::class)->createPayment($order, 'trc20');
        $this->assertSame('https://gateway.example.test/pay/1', $result['payment_url']);
        $this->assertSame($result['payment_url'], Cache::get('payment_url:'.$order->order_no));
        $this->assertSame('valid-trade', $order->fresh()->gateway_trade_no);
        Http::assertSentCount(1);
    }
}
