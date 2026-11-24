<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, Order, Product, Setting};
use Illuminate\Support\Facades\{Hash, Http};
use Tests\TestCase;

class AntiAbuseHardeningTest extends TestCase
{
    private function createSeededProduct(int $stock = 20): Product
    {
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1', 'payment');
        Setting::set('epay_merchant_key', 'anti-abuse-test-key', 'payment');

        $category = Category::create(['name' => '亚太区', 'slug' => 'oci-apac-' . uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => '悉尼升级 - 高质量谷歌',
            'slug' => 'oci-payg-sydney-' . uniqid(),
            'price' => '218.00',
            'min_quantity' => 1,
            'max_quantity' => 10,
            'is_active' => true,
        ]);
        for ($i = 1; $i <= $stock; $i++) {
            Card::create([
                'product_id' => $product->id,
                'content' => "Tenancy: sydney-{$i}",
                'status' => 'unsold',
            ]);
        }

        return $product;
    }

    public function test_per_email_pending_order_cap_blocks_proxy_rotation_hoarding(): void
    {
        Setting::set('order_max_pending_per_email', '3', 'security');
        $product = $this->createSeededProduct(15);

        // Attacker rotates 3 distinct IPv4 addresses using mixed-case variants of the same email
        foreach (['198.51.100.11', '198.51.100.22', '203.0.113.33'] as $idx => $proxyIp) {
            $email = $idx === 1 ? 'Hoarder@Example.TEST' : 'hoarder@example.test';
            $this->withServerVariables(['REMOTE_ADDR' => $proxyIp])
                ->post('/order/create', [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'email' => $email,
                    'query_password' => 'secret-pass-123',
                    'payment_method' => 'alipay',
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, Order::where('status', 'pending')->count());

        // 4th attempt from a brand-new IP with the same email is rejected without locking extra stock
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->from('/product/' . $product->slug)
            ->post('/order/create', [
                'product_id' => $product->id,
                'quantity' => 1,
                'email' => 'HOARDER@example.test',
                'query_password' => 'secret-pass-123',
                'payment_method' => 'alipay',
            ])
            ->assertRedirect('/product/' . $product->slug)
            ->assertSessionHasErrors('error');

        $this->assertSame(3, Order::where('status', 'pending')->count());
        $this->assertSame(12, $product->stockCount());
    }

    public function test_product_unpaid_stock_lock_ratio_protects_inventory_without_blocking_small_stock(): void
    {
        Setting::set('order_max_locked_percent', '80', 'security');

        // 1. High-stock product (20 cards): botnet locks 16 cards (80%) across distinct IPs and emails;
        //    attempting to lock a 17th unpaid card is blocked, preserving 4 cards for real buyers.
        $stocked = $this->createSeededProduct(20);
        for ($i = 1; $i <= 4; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
                ->post('/order/create', [
                    'product_id' => $stocked->id,
                    'quantity' => 4,
                    'email' => "bot{$i}@example.test",
                    'query_password' => 'secret-pass-123',
                    'payment_method' => 'alipay',
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(4, $stocked->stockCount());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.88'])
            ->from('/product/' . $stocked->slug)
            ->post('/order/create', [
                'product_id' => $stocked->id,
                'quantity' => 1,
                'email' => 'bot5@example.test',
                'query_password' => 'secret-pass-123',
                'payment_method' => 'alipay',
            ])
            ->assertRedirect('/product/' . $stocked->slug)
            ->assertSessionHasErrors('error');
        $this->assertSame(4, $stocked->stockCount());

        // 2. Low-stock product (4 cards < 10 threshold): real buyer can still buy all 4 remaining cards.
        $lowStock = $this->createSeededProduct(4);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.120'])
            ->post('/order/create', [
                'product_id' => $lowStock->id,
                'quantity' => 4,
                'email' => 'realbuyer@example.test',
                'query_password' => 'secret-pass-123',
                'payment_method' => 'alipay',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $lowStock->stockCount());
    }

    public function test_mandatory_checkout_key_blocks_stateless_posts_and_refresh_intent_prevents_stale_key_trap(): void
    {
        Setting::set('checkout_require_key', '1', 'security');
        $product = $this->createSeededProduct(10);

        // 1. Stateless bare POST without checkout_key is rejected at validation
        $this->from('/product/' . $product->slug)
            ->post('/order/create', [
                'product_id' => $product->id,
                'quantity' => 1,
                'email' => 'stateless@example.test',
                'query_password' => 'secret-pass-123',
                'payment_method' => 'alipay',
            ])
            ->assertRedirect('/product/' . $product->slug)
            ->assertSessionHasErrors('checkout_key');
        $this->assertSame(0, Order::count());

        // 2. Page-issued checkout_key succeeds, and failed coupon attempt never flashes stale checkout_key into _old_input
        $html = $this->get('/product/' . $product->slug)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="checkout_key"\s+value="([^"]+)"/', $html, $match));
        $pageKey = html_entity_decode($match[1]);

        $this->from('/product/' . $product->slug)
            ->post('/order/create', [
                'product_id' => $product->id,
                'quantity' => 1,
                'email' => 'buyer@example.test',
                'query_password' => 'secret-pass-123',
                'coupon_code' => 'NON-EXISTENT-COUPON',
                'payment_method' => 'alipay',
                'checkout_key' => $pageKey,
            ])
            ->assertRedirect('/product/' . $product->slug)
            ->assertSessionHasErrors('error')
            ->assertSessionMissing('_old_input.checkout_key');

        // 3. Refreshing checkout_key via POST /order/intent issues a valid key bound to the active session
        $intentRes = $this->postJson('/order/intent', ['product_ids' => [$product->id]])
            ->assertOk();
        $freshKey = $intentRes->json('keys.' . $product->id);
        $this->assertIsString($freshKey);
        $this->assertNotSame('', $freshKey);

        $this->post('/order/create', [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'buyer@example.test',
            'query_password' => 'secret-pass-123',
            'payment_method' => 'alipay',
            'checkout_key' => $freshKey,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Order::count());
    }

    public function test_turnstile_honeypot_and_sec_fetch_site_protect_order_creation_query_and_verify(): void
    {
        $product = $this->createSeededProduct(6);

        // 1. Honeypot trap ('website' filled by auto-fill bot) blocks /order/create, /order/query, and /order/verify
        $this->postJson('/order/create', [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'bot@example.test',
            'query_password' => 'secret-pass-123',
            'payment_method' => 'alipay',
            'website' => 'https://spam.example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('website');

        $this->postJson('/order/query', [
            'email' => 'bot@example.test',
            'query_password' => 'secret-pass-123',
            'website' => 'https://spam.example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('website');

        $this->postJson('/order/verify', [
            'email' => 'bot@example.test',
            'query_password' => 'secret-pass-123',
            'website' => 'https://spam.example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('website');

        // 2. Cross-site Fetch Metadata (Sec-Fetch-Site: cross-site) is rejected with 403 before controller execution
        foreach (['/order/create', '/order/query', '/order/verify'] as $endpoint) {
            $res = $this->withHeader('Sec-Fetch-Site', 'cross-site')->postJson($endpoint, [
                'email' => 'victim@example.test',
                'query_password' => 'secret-pass-123',
            ]);
            $res->assertStatus(403);
            $this->assertStringContainsString('Sec-Fetch-Site', (string) $res->headers->get('Vary'));
        }

        // 3. Same-origin Sec-Fetch-Site passes GuardBuyerMutation cleanly, and Turnstile now guards /order/verify too
        Setting::set('turnstile_site_key', 'site-key-test', 'security');
        Setting::set('turnstile_secret_key', 'secret-key-test', 'security');
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->withHeader('Sec-Fetch-Site', 'same-origin')
            ->postJson('/order/verify', [
                'email' => 'buyer@example.test',
                'query_password' => 'secret-pass-123',
                'cf-turnstile-response' => 'invalid-token',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::count());
    }

    public function test_admin_settings_validates_and_persists_new_anti_abuse_controls(): void
    {
        $admin = Admin::create(['username' => 'sec-admin', 'password' => Hash::make('strong-admin-pass-123')]);
        $this->withSession([
            'admin_id' => $admin->id,
            'admin_pw' => AdminAuth::passwordFingerprint($admin->password),
        ]);

        $this->postJson('/api/' . admin_path() . '/settings', [
            'order_max_pending_per_ip' => 0,
            'order_max_pending_per_email' => 99,
            'order_max_locked_percent' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'order_max_pending_per_ip',
            'order_max_pending_per_email',
            'order_max_locked_percent',
        ]);

        $this->postJson('/api/' . admin_path() . '/settings', [
            'checkout_require_key' => true,
            'order_max_pending_per_ip' => 4,
            'order_max_pending_per_email' => 2,
            'order_max_locked_percent' => 75,
        ])->assertOk();

        $settings = $this->getJson('/api/' . admin_path() . '/settings')->assertOk()->json();
        $this->assertSame('1', $settings['checkout_require_key']);
        $this->assertSame('4', $settings['order_max_pending_per_ip']);
        $this->assertSame('2', $settings['order_max_pending_per_email']);
        $this->assertSame('75', $settings['order_max_locked_percent']);
    }

    public function test_individual_website_security_protections_can_be_toggled_off_and_back_on(): void
    {
        $product = $this->createSeededProduct(20);

        // 1. Blacklist toggle: disabling skips CheckBlacklist and Blacklist::isBlocked without deleting rules.
        \App\Models\Blacklist::create(['type' => 'ip', 'value' => '203.0.113.55', 'source' => 'manual', 'reason' => 'Test block']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])->get('/')->assertForbidden();
        Setting::set('blacklist_enabled', '0', 'security');
        settings_forget();
        $this->assertFalse(\App\Models\Blacklist::isBlocked('203.0.113.55'));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])->get('/')->assertOk();
        Setting::set('blacklist_enabled', '1', 'security');
        settings_forget();
        $this->assertTrue(\App\Models\Blacklist::isBlocked('203.0.113.55'));

        // 2. Turnstile toggle: disabling hides widget and skips middleware while keeping configured keys intact.
        Setting::set('turnstile_site_key', 'site-key-keep', 'turnstile');
        Setting::set('turnstile_secret_key', 'secret-key-keep', 'turnstile');
        Setting::set('turnstile_enabled', '0', 'turnstile');
        settings_forget();
        $this->assertSame('', turnstile_site_key());
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.90'])
            ->get('/product/' . $product->slug)->assertOk()->assertDontSee('cf-turnstile');

        // 3. Cross-site Sec-Fetch-Site guard + Form honeypot + Order limits can each be toggled off.
        Setting::set('cross_site_guard_enabled', '0', 'security');
        Setting::set('form_honeypot_enabled', '0', 'security');
        Setting::set('checkout_require_key', '0', 'security');
        Setting::set('order_limit_enabled', '0', 'security');
        Setting::set('order_max_pending_per_ip', '1', 'security');
        Setting::set('order_max_pending_per_email', '1', 'security');
        settings_forget();

        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.90'])
                ->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Accept' => 'application/json'])
                ->post('/order/create', [
                    'product_id' => $product->id,
                    'email' => 'toggle-buyer@example.test',
                    'query_password' => 'Pass1234',
                    'quantity' => 1,
                    'payment_method' => 'alipay',
                    'website' => 'filled-while-form-honeypot-disabled',
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(2, Order::where('email', 'toggle-buyer@example.test')->count());

        // Re-enabling cross-site guard immediately blocks Sec-Fetch-Site: cross-site again.
        Setting::set('cross_site_guard_enabled', '1', 'security');
        settings_forget();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.90'])
            ->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Accept' => 'application/json'])
            ->post('/order/create', [
                'product_id' => $product->id,
                'email' => 'toggle-buyer@example.test',
                'query_password' => 'Pass1234',
                'quantity' => 1,
                'payment_method' => 'alipay',
            ])
            ->assertForbidden();
    }
}
