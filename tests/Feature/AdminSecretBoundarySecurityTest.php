<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, Order, Product, Setting};
use App\Services\{NotificationService, ShopBackupService};
use Illuminate\Support\Facades\{Cache, Hash, Http, Log, Mail, RateLimiter};
use Tests\TestCase;

class AdminSecretBoundarySecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(array $attributes = []): Admin
    {
        return Admin::create(array_replace(['username' => 'secret-owner', 'password' => Hash::make('secret-admin-password'),
            'role' => 'owner', 'permissions' => [], 'is_active' => true], $attributes));
    }

    private function authenticate(Admin $admin): void
    {
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Private catalog', 'slug' => 'private-catalog', 'is_active' => true]);

        return Product::create(['category_id' => $category->id, 'name' => 'Private stock', 'slug' => 'private-stock',
            'price' => '10.00', 'is_active' => true]);
    }

    private function order(Product $product, string $status = 'paid'): Order
    {
        return Order::create(['order_no' => generate_order_no(), 'product_id' => $product->id, 'email' => 'private-buyer@example.test',
            'query_password' => Hash::make('private-buyer-password'), 'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00',
            'payment_method' => 'alipay', 'status' => $status, 'ip' => '192.0.2.30', 'expires_at' => now()->addMinutes(30)]);
    }

    private function useClockAwareRateLimiter(): void
    {
        // Redis expiration uses real wall-clock time, so Carbon::travel does not
        // expire Redis keys. The framework ArrayStore observes the test clock and
        // exercises exactly the same limiter counter/expiry logic without sleeping.
        RateLimiter::swap(new \Illuminate\Cache\RateLimiter(
            new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore())
        ));
    }

    private function captureLogs(): \Psr\Log\AbstractLogger
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        Log::swap($logger);

        return $logger;
    }

    public function test_login_guessing_budget_follows_the_account_across_different_ips(): void
    {
        $this->useClockAwareRateLimiter();
        $admin = $this->admin();
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/api/admin/login',
                ['username' => $admin->username, 'password' => 'incorrect-password'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100'])->postJson('/api/admin/login',
            ['username' => $admin->username, 'password' => 'secret-admin-password'])->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/admin/me')->assertUnauthorized();

        $this->travel(901)->seconds();
        $this->postJson('/api/admin/login', ['username' => $admin->username, 'password' => 'secret-admin-password'])->assertOk();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('id', $admin->id);
    }

    public function test_login_limits_use_real_redis_keys_with_a_bounded_expiration(): void
    {
        $admin = $this->admin();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.31'])->postJson('/api/admin/login',
            ['username' => $admin->username, 'password' => 'incorrect-password'])->assertUnprocessable();
        $store = Cache::store('redis')->getStore();
        $keys = ['admin-login-account|'.hash('sha256', mb_strtolower($admin->username)), 'admin-login|192.0.2.31'];
        foreach ($keys as $key) {
            $this->assertSame(1, (int) RateLimiter::attempts($key));
            foreach ([$key, $key.':timer'] as $cacheKey) {
                $ttl = $store->connection()->ttl($store->getPrefix().$cacheKey);
                $this->assertGreaterThan(0, $ttl);
                $this->assertLessThanOrEqual($key === $keys[0] ? 900 : 60, $ttl);
            }
        }
    }

    public function test_password_change_guessing_is_limited_even_when_the_ip_changes(): void
    {
        $this->useClockAwareRateLimiter();
        $admin = $this->admin();
        $hash = $admin->password;
        $this->authenticate($admin);
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/api/admin/password', [
                'current_password' => 'incorrect-password', 'new_password' => 'new-secret-admin-password',
                'new_password_confirmation' => 'new-secret-admin-password',
            ])->assertUnprocessable();
        }
        $payload = ['current_password' => 'secret-admin-password', 'new_password' => 'new-secret-admin-password',
            'new_password_confirmation' => 'new-secret-admin-password'];
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100'])->postJson('/api/admin/password', $payload)
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame($hash, $admin->fresh()->password);
        $this->travel(901)->seconds();
        $this->postJson('/api/admin/password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-secret-admin-password', $admin->fresh()->password));
        $this->getJson('/api/admin/me')->assertOk();
    }

    public function test_successful_login_and_logout_rotate_the_session_id(): void
    {
        $this->admin();
        $this->withSession(['unrelated' => 'value']);
        $beforeLogin = $this->app['session.store']->getId();
        $this->postJson('/api/admin/login', ['username' => 'secret-owner', 'password' => 'secret-admin-password'])->assertOk();
        $afterLogin = $this->app['session.store']->getId();
        $this->assertNotSame($beforeLogin, $afterLogin);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->assertNotSame($afterLogin, $this->app['session.store']->getId());
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_smtp_exception_text_is_not_returned_or_logged(): void
    {
        $mailer = $this->createMock(\Illuminate\Mail\Mailer::class);
        $mailer->expects($this->once())->method('html')->willThrowException(new \RuntimeException(
            '535 Authentication failed AUTH test-smtp-password private-stock-card-secret'
        ));
        Mail::swap($mailer);
        $logger = $this->captureLogs();
        $result = app(NotificationService::class)->sendTestEmail('operator@example.test');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SMTP 认证失败', $result['message']);
        $this->assertStringNotContainsString('test-smtp-password', $result['message']);
        $this->assertStringNotContainsString('private-stock-card-secret', $result['message']);
        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame('warning', $record['level']);
        $this->assertSame('SMTP test failed', $record['message']);
        $this->assertArrayHasKey('error_type', $record['context']);
        $this->assertStringNotContainsString('test-smtp-password', json_encode($record['context']));
        $this->assertStringNotContainsString('private-stock-card-secret', json_encode($record['context']));
    }

    public function test_failed_card_email_does_not_write_transport_body_or_credentials_to_logs(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        Card::create(['product_id' => $product->id, 'order_id' => $order->id, 'content' => 'private-stock-card-secret', 'status' => 'sold']);
        $mailer = $this->createMock(\Illuminate\Mail\Mailer::class);
        $mailer->expects($this->once())->method('html')->willThrowException(new \RuntimeException('SMTP DATA private-stock-card-secret test-smtp-password'));
        Mail::swap($mailer);
        $logger = $this->captureLogs();
        $this->assertFalse(app(NotificationService::class)->sendOrderEmail($order));
        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame('error', $record['level']);
        $this->assertSame('Failed to send order email', $record['message']);
        $this->assertArrayHasKey('error_type', $record['context']);
        $this->assertStringNotContainsString('test-smtp-password', json_encode($record['context']));
        $this->assertStringNotContainsString('private-stock-card-secret', json_encode($record['context']));
    }

    public function test_telegram_rejection_does_not_log_an_untrusted_response_body(): void
    {
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_bot_token', 'test-private-telegram-token');
        Setting::set('telegram_chat_id', 'test-chat');
        Http::fake(['api.telegram.org/*' => Http::response('echo test-private-telegram-token private-stock-card-secret', 400)]);
        $logger = $this->captureLogs();
        $this->assertFalse(app(NotificationService::class)->sendTelegramNotification('Test notification'));
        $this->assertSame([['level' => 'warning', 'message' => 'Telegram notification failed', 'context' => ['status' => 400]]], $logger->records);
    }

    public function test_catalog_read_and_write_do_not_grant_access_to_unsold_card_secrets(): void
    {
        $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'private-stock-card-secret', 'status' => 'unsold']);
        foreach (['catalog:read', 'catalog:write'] as $permission) {
            $staff = $this->admin(['username' => str_replace(':', '-', $permission), 'role' => 'staff', 'permissions' => [$permission]]);
            $this->authenticate($staff);
            $this->getJson('/api/admin/products/'.$product->id)->assertOk()->assertDontSee('private-stock-card-secret');
            $this->getJson('/api/admin/products/'.$product->id.'/cards')->assertForbidden()->assertDontSee('private-stock-card-secret');
            $this->getJson('/api/admin/%70roducts/'.$product->id.'/cards')->assertForbidden()->assertDontSee('private-stock-card-secret');
            $this->postJson('/api/admin/products/'.$product->id.'/cards/import', ['content' => 'forged-stock'])->assertForbidden();
            $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'sold'])->assertForbidden();
            $this->deleteJson('/api/admin/cards/'.$card->id)->assertForbidden();
        }
        $this->assertSame('unsold', $card->fresh()->status);
        $this->assertSame(1, Card::count());
    }

    public function test_order_readers_cannot_extract_either_paid_or_pending_order_cards(): void
    {
        $product = $this->product();
        $staff = $this->admin(['role' => 'staff', 'permissions' => ['orders:read']]);
        $this->authenticate($staff);
        foreach (['paid', 'pending'] as $status) {
            $order = $this->order($product, $status);
            Card::create(['product_id' => $product->id, 'order_id' => $order->id,
                'content' => 'private-'.$status.'-card-secret', 'status' => $status === 'paid' ? 'sold' : 'locked']);
            $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('cards_accessible', false)
                ->assertJsonPath('cards.0.status', $status === 'paid' ? 'sold' : 'locked')
                ->assertJsonMissingPath('cards.0.content')->assertDontSee('private-'.$status.'-card-secret');
        }
        $this->getJson('/api/admin/orders')->assertOk()->assertDontSee('card-secret');
        $export = $this->get('/api/admin/orders/export')->assertOk();
        $this->assertStringContainsString('private-buyer@example.test', $export->streamedContent());
        $this->assertStringNotContainsString('card-secret', $export->streamedContent());
    }

    public function test_explicit_card_read_permission_reveals_cards_without_buyer_credentials_or_writes(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $card = Card::create(['product_id' => $product->id, 'order_id' => $order->id, 'content' => 'private-stock-card-secret', 'status' => 'sold']);
        $staff = $this->admin(['role' => 'staff', 'permissions' => ['cards:read']]);
        $this->authenticate($staff);
        $this->getJson('/api/admin/products/'.$product->id.'/cards')->assertOk()->assertJsonPath('data.0.content', 'private-stock-card-secret')
            ->assertJsonPath('data.0.order.order_no', $order->order_no)->assertJsonMissingPath('data.0.order.email')
            ->assertJsonMissingPath('data.0.order.ip')->assertJsonMissingPath('data.0.order.query_password');
        $this->postJson('/api/admin/products/'.$product->id.'/cards/import', ['content' => 'forged-stock'])->assertForbidden();
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'unsold'])->assertForbidden();
        $this->getJson('/api/admin/orders/'.$order->id)->assertForbidden();

        $staff->update(['permissions' => ['cards:read', 'orders:read']]);
        $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('cards_accessible', true)
            ->assertJsonPath('cards.0.content', 'private-stock-card-secret')->assertJsonMissingPath('query_password');
    }

    public function test_card_content_is_hidden_by_default_even_when_relations_are_serialized(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $card = Card::create(['product_id' => $product->id, 'order_id' => $order->id, 'content' => 'private-stock-card-secret', 'status' => 'sold']);
        $this->assertArrayNotHasKey('content', $card->toArray());
        $this->assertStringNotContainsString('private-stock-card-secret', $order->load('cards')->toJson());
        // Direct delivery access is preserved: only serialization defaults change.
        $this->assertSame('private-stock-card-secret', $card->content);
        $this->assertSame(['private-stock-card-secret'], $order->cards->pluck('content')->all());
    }

    public function test_card_write_permission_implies_read_and_preserves_stock_operations(): void
    {
        $product = $this->product();
        $staff = $this->admin(['role' => 'staff', 'permissions' => ['cards:write']]);
        $this->authenticate($staff);
        $this->postJson('/api/admin/products/'.$product->id.'/cards/import', ['content' => 'private-stock-card-secret'])->assertOk();
        $card = $product->cards()->firstOrFail();
        $this->getJson('/api/admin/products/'.$product->id.'/cards')->assertOk()->assertJsonPath('data.0.content', 'private-stock-card-secret');
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'sold'])->assertOk()
            ->assertJsonPath('card.content', 'private-stock-card-secret');
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'unsold'])->assertOk();
        $this->deleteJson('/api/admin/cards/'.$card->id)->assertOk();
        $this->assertSame(0, Card::count());
    }

    public function test_maintenance_staff_cannot_download_or_validate_complete_backups(): void
    {
        $staff = $this->admin(['role' => 'staff', 'permissions' => ['maintenance:write']]);
        $this->authenticate($staff);
        $this->getJson('/api/admin/maintenance/backups')->assertForbidden();
        $this->postJson('/api/admin/maintenance/backups')->assertForbidden();
        $this->getJson('/api/admin/maintenance/backups/shop-private.tar.gz/download')->assertForbidden();
        $this->postJson('/api/admin/maintenance/backups/shop-private.tar.gz/validate')->assertForbidden();
    }

    public function test_backup_downloads_pass_the_honeypot_but_still_require_the_owner(): void
    {
        // Redirect backup paths to a temporary test fixture; never create or open
        // an actual shop backup containing the database or environment secrets.
        $directory = storage_path('framework/testing/secret-boundary-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $name = 'shop-security-fixture.tar.gz';
        $file = $directory.'/'.$name;
        file_put_contents($file, 'virtual-backup-fixture');
        $backups = $this->createMock(ShopBackupService::class);
        $backups->method('directory')->willReturn($directory);
        $this->instance(ShopBackupService::class, $backups);
        $adminSegment = admin_path();
        $encodedAdmin = '%'.bin2hex($adminSegment[0]).substr($adminSegment, 1);
        try {
            foreach ([$adminSegment, $encodedAdmin] as $segment) {
                $this->getJson('/api/'.$segment.'/maintenance/backups/'.$name.'/download')->assertUnauthorized();
            }
            $staff = $this->admin(['role' => 'staff', 'permissions' => ['maintenance:write']]);
            $this->authenticate($staff);
            foreach ([$adminSegment, $encodedAdmin] as $segment) {
                $this->getJson('/api/'.$segment.'/maintenance/backups/'.$name.'/download')->assertForbidden();
            }
            $staff->update(['role' => 'owner']);
            foreach ([$adminSegment, $encodedAdmin] as $segment) {
                $download = $this->getJson('/api/'.$segment.'/maintenance/backups/'.$name.'/download')->assertOk()->assertDownload($name);
                $this->assertSame('virtual-backup-fixture', file_get_contents($download->baseResponse->getFile()->getRealPath()));
            }
            $this->getJson('/'.$name)->assertNotFound();
            $this->getJson('/api/not-the-admin/'.$name.'/download')->assertNotFound();
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function test_order_management_does_not_grant_manual_free_fulfilment(): void
    {
        $product = $this->product();
        $order = $this->order($product, 'pending');
        $card = Card::create(['product_id' => $product->id, 'order_id' => $order->id,
            'content' => 'private-unpaid-card-secret', 'status' => 'locked']);
        $staff = $this->admin(['role' => 'staff', 'permissions' => ['orders:write']]);
        $this->authenticate($staff);
        $this->postJson('/api/admin/orders/'.$order->id.'/paid')->assertForbidden()->assertDontSee('private-unpaid-card-secret');
        $this->postJson('/api/admin/%6frders/'.$order->id.'/paid')->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('locked', $card->fresh()->status);
        $this->assertSame(0, $order->notifications()->count());

        $staff->update(['permissions' => ['orders:write', 'payments:write']]);
        $this->postJson('/api/admin/orders/'.$order->id.'/paid')->assertOk()->assertDontSee('private-unpaid-card-secret');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('sold', $card->fresh()->status);
        $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('cards_accessible', false)->assertJsonMissingPath('cards.0.content');
        $this->postJson('/api/admin/orders/'.$order->id.'/resend')->assertStatus(202)->assertDontSee('private-unpaid-card-secret');
    }

    public function test_staff_settings_updates_cannot_redirect_card_delivery_or_forge_gateway_credentials(): void
    {
        $sensitive = [
            'epay_api_url' => ['https://gateway.example.test', 'https://attacker.example.test'],
            'epay_merchant_id' => ['trusted-merchant', 'attacker-merchant'],
            'epay_merchant_key' => ['private-payment-signing-key', 'attacker-known-key'],
            'epusdt_api_url' => ['https://usdt.example.test', 'https://attacker.example.test'],
            'epusdt_api_token' => ['private-usdt-token', 'attacker-known-token'],
            'usdt_gateway' => ['epusdt', 'bepusdt'],
            'payment_reconciliation_enabled' => ['1', '0'],
            'email_template_subject' => ['Order {{order_no}}', 'Attacker {{cards}}'],
            'email_template_body' => ['Your cards: {{cards}}', '<img src="https://attacker.example.test/?cards={{cards}}">'],
            'mail_host' => ['smtp.example.test', 'attacker.example.test'],
            'mail_port' => ['465', 2525],
            'mail_username' => ['trusted-mail-user', 'attacker-mail-user'],
            'mail_password' => ['private-smtp-password', 'attacker-smtp-password'],
            'mail_encryption' => ['ssl', 'none'],
            'mail_from_address' => ['shop@example.test', 'attacker@example.test'],
            'mail_from_name' => ['Shop', 'Attacker'],
            'telegram_bot_token' => ['private-bot-token', 'attacker-bot-token'],
            'telegram_chat_id' => ['trusted-chat', 'attacker-chat'],
            'telegram_enabled' => ['1', '0'],
            'turnstile_site_key' => ['trusted-site-key', 'attacker-site-key'],
            'turnstile_secret_key' => ['private-turnstile-key', 'attacker-turnstile-key'],
            'order_expire_minutes' => ['30', 5],
            'honeypot_enabled' => ['1', '0'],
            'honeypot_ban_minutes' => ['10080', 0],
            'honeypot_whitelist' => ['192.0.2.1', '192.0.2.99'],
            'honeypot_skip_reserved_ips' => ['1', '0'],
        ];
        Setting::set('site_name', 'Original shop');
        foreach ($sensitive as $key => [$current]) { Setting::set($key, $current); }
        $staff = $this->admin(['role' => 'staff', 'permissions' => ['settings:write']]);
        $this->authenticate($staff);
        foreach ($sensitive as $key => [$current, $forged]) {
            $this->postJson('/api/admin/settings', [$key => $forged, 'site_name' => 'Forged shop'])->assertForbidden();
            $this->assertSame($current, Setting::where('key', $key)->value('value'));
            $this->assertSame('Original shop', Setting::where('key', 'site_name')->value('value'));
        }

        // Masked secrets must not offer staff a 200-versus-403 password oracle.
        foreach (['epay_merchant_key', 'epusdt_api_token', 'mail_password', 'telegram_bot_token', 'turnstile_secret_key'] as $key) {
            $this->postJson('/api/admin/settings', [$key => $sensitive[$key][0]])->assertForbidden();
        }

        $unchanged = array_map(fn ($pair) => $pair[0], $sensitive);
        foreach (['epay_merchant_key', 'epusdt_api_token', 'mail_password', 'telegram_bot_token', 'turnstile_secret_key'] as $key) {
            $unchanged[$key] = '********';
        }
        $unchanged['mail_port'] = 465;
        $unchanged['telegram_enabled'] = true;
        $unchanged['site_name'] = 'Updated shop';
        $this->postJson('/api/admin/settings', $unchanged)->assertOk();
        $this->assertSame('Updated shop', Setting::where('key', 'site_name')->value('value'));
        foreach ($sensitive as $key => [$current]) { $this->assertSame($current, Setting::where('key', $key)->value('value')); }

        $staff->update(['role' => 'owner']);
        $this->postJson('/api/admin/settings', ['mail_host' => 'new-trusted.example.test', 'epay_merchant_key' => 'new-private-payment-key'])->assertOk();
        $this->assertSame('new-trusted.example.test', Setting::where('key', 'mail_host')->value('value'));
        $this->assertSame('new-private-payment-key', Setting::where('key', 'epay_merchant_key')->value('value'));
    }

    public function test_staff_cannot_use_smtp_testing_as_an_owner_only_operation(): void
    {
        $staff = $this->admin(['role' => 'staff', 'permissions' => ['settings:write']]);
        $this->authenticate($staff);
        $mailer = $this->createMock(\Illuminate\Mail\Mailer::class);
        $mailer->expects($this->never())->method('html');
        Mail::swap($mailer);
        $this->postJson('/api/admin/settings/test-email', ['email' => 'attacker@example.test'])->assertForbidden();
    }
}
