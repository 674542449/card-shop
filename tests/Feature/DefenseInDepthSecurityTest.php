<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\Admin;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Product;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DefenseInDepthSecurityTest extends TestCase
{
    private function actingAsActiveAdmin(): static
    {
        $admin = Admin::first() ?? Admin::create([
            'username' => 'sec_owner',
            'password' => Hash::make('StrongAdminPass2026!'),
            'is_active' => true,
        ]);

        return $this->withSession([
            'admin_id' => $admin->id,
            'admin_username' => $admin->username,
            'admin_pw' => AdminAuth::passwordFingerprint($admin->password),
            'admin_factor' => $admin->two_factor_revision,
        ]);
    }

    public function test_admin_catalog_and_article_image_fields_reject_unsafe_urls_on_write(): void
    {
        $this->actingAsActiveAdmin();

        $category = Category::create(['name' => '亚太区', 'slug' => 'apac', 'is_active' => true]);
        $articleCategory = ArticleCategory::create(['name' => '使用指南', 'slug' => 'guides']);

        foreach (['javascript:alert(1)', 'data:text/html,<svg onload=alert(1)>', '//evil.example/x.png', 'https://user:pass@example.com/a.png'] as $unsafe) {
            $this->postJson('/api/' . admin_path() . '/categories', [
                'name' => 'Bad Cat',
                'image' => $unsafe,
                'is_active' => true,
            ])->assertStatus(422)->assertJsonValidationErrors(['image']);

            $this->postJson('/api/' . admin_path() . '/products', [
                'name' => 'Bad Product',
                'category_id' => $category->id,
                'price' => '19.90',
                'image' => $unsafe,
                'is_active' => true,
            ])->assertStatus(422)->assertJsonValidationErrors(['image']);

            $this->postJson('/api/' . admin_path() . '/articles', [
                'title' => 'Bad Article',
                'article_category_id' => $articleCategory->id,
                'content' => '<p>正文</p>',
                'cover_image' => $unsafe,
                'is_published' => true,
            ])->assertStatus(422)->assertJsonValidationErrors(['cover_image']);
        }

        $this->postJson('/api/' . admin_path() . '/products', [
            'name' => 'Safe Product',
            'category_id' => $category->id,
            'price' => '19.90',
            'image' => '/storage/uploads/2026/11/safe.webp',
            'is_active' => true,
        ])->assertCreated();
    }

    public function test_storefront_and_admin_searches_escape_sql_like_wildcards_literally(): void
    {
        $this->actingAsActiveAdmin();

        $category = Category::create(['name' => '亚太区', 'slug' => 'apac', 'is_active' => true]);
        Product::create([
            'name' => '悉尼升级 - 高质量谷歌',
            'slug' => 'oci-payg-sydney',
            'category_id' => $category->id,
            'price' => '218.00',
            'is_active' => true,
        ]);
        Product::create([
            'name' => '100% 原邮质保_专享版',
            'slug' => 'special-edition',
            'category_id' => $category->id,
            'price' => '99.00',
            'is_active' => true,
        ]);

        $percentSearch = $this->get('/?q=' . rawurlencode('%'))->assertOk();
        $percentSearch->assertSee('100% 原邮质保_专享版');
        $percentSearch->assertDontSee('悉尼升级 - 高质量谷歌');

        $underscoreSearch = $this->get('/?q=' . rawurlencode('_'))->assertOk();
        $underscoreSearch->assertSee('100% 原邮质保_专享版');
        $underscoreSearch->assertDontSee('悉尼升级 - 高质量谷歌');

        $catWildcard = $this->get('/category/apac?q=' . rawurlencode('%'))->assertOk();
        $catWildcard->assertSee('100% 原邮质保_专享版');
        $catWildcard->assertDontSee('悉尼升级 - 高质量谷歌');

        $adminSearch = $this->getJson('/api/' . admin_path() . '/products?keyword=' . rawurlencode('%'))
            ->assertOk();
        $this->assertSame(1, $adminSearch->json('total'));
        $this->assertSame('100% 原邮质保_专享版', $adminSearch->json('data.0.name'));
    }

    public function test_https_responses_emit_hsts_and_permissions_policy_while_http_omits_hsts(): void
    {
        $httpRes = $this->get('http://localhost/')->assertOk();
        $httpRes->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $this->assertFalse($httpRes->headers->has('Strict-Transport-Security'));

        $httpsRes = $this->get('https://shop.example.test/')->assertOk();
        $httpsRes->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $httpsRes->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }

    public function test_outbound_gateway_guards_reject_cloud_metadata_and_production_private_networks(): void
    {
        $this->actingAsActiveAdmin();

        foreach ([
            'https://169.254.169.254',
            'https://[::ffff:169.254.169.254]',
            'https://100.100.100.200',
            'https://[fd00:ec2::254]',
            'https://metadata.google.internal',
            'https://0.0.0.0',
            'https://2852039166',
            'https://0xa9fea9fe',
        ] as $blockedGateway) {
            $this->postJson('/api/' . admin_path() . '/settings', [
                'epay_api_url' => $blockedGateway,
            ])->assertStatus(422)->assertJsonValidationErrors(['epay_api_url']);

            $this->postJson('/api/' . admin_path() . '/settings', [
                'epusdt_api_url' => $blockedGateway,
            ])->assertStatus(422)->assertJsonValidationErrors(['epusdt_api_url']);
        }

        // Production mode rejects loopback, RFC1918 private IPs, and DNS rebinding to internal/metadata IPs.
        $this->assertNull(SafeUrl::outboundGateway('https://127.0.0.1:8443', false));
        $this->assertNull(SafeUrl::outboundGateway('https://localhost:8443', false));
        $this->assertNull(SafeUrl::outboundGateway('https://10.0.0.12', false));
        $this->assertNull(SafeUrl::outboundGateway('https://172.16.5.4', false));
        $this->assertNull(SafeUrl::outboundGateway('https://192.168.1.20', false));
        $this->assertNull(SafeUrl::outboundGateway('https://[::1]:8443', false));
        $this->assertSame('https://pay.example.com', SafeUrl::outboundGateway('https://pay.example.com', false));

        $pinned = SafeUrl::outboundHttpOptions(
            'https://pay.example.com',
            false,
            static fn (): array => ['203.0.113.25'],
        );
        $this->assertSame(['http', 'https'], $pinned['protocols']);
        $this->assertFalse($pinned['allow_redirects']);
        if (defined('CURLOPT_RESOLVE')) {
            $this->assertSame(['pay.example.com:443:203.0.113.25'], $pinned['curl'][CURLOPT_RESOLVE]);
        }

        // Dual-stack domains (e.g. Cloudflare) must prioritize verified IPv4 ahead of IPv6
        // and remain accepted by Guzzle's real CurlFactory without conflicting curl options.
        $dualStack = SafeUrl::outboundHttpOptions(
            'https://pay.example.com',
            false,
            static fn (): array => ['2606:4700:3030::1', '104.21.50.46', '172.67.1.2'],
        );
        if (defined('CURLOPT_RESOLVE')) {
            $this->assertSame(
                ['pay.example.com:443:104.21.50.46,172.67.1.2,[2606:4700:3030::1]'],
                $dualStack['curl'][CURLOPT_RESOLVE]
            );
        }

        $factory = new \GuzzleHttp\Handler\CurlFactory(1);
        $easy = $factory->create(
            new \GuzzleHttp\Psr7\Request('POST', 'https://pay.example.com/api/v1/order/create-transaction'),
            $dualStack
        );
        $this->assertNotNull($easy->handle);
        $factory->release($easy);

        $this->expectException(\InvalidArgumentException::class);
        SafeUrl::outboundHttpOptions(
            'https://rebind.example.com',
            false,
            static fn (): array => ['169.254.169.254'],
        );
    }
}
