<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, ApiToken, Article, ArticleCategory, Blacklist, Card, Category, Coupon, OperationLog, Product, Setting};
use Illuminate\Support\Facades\{Cache, Hash, Http};
use Tests\TestCase;

class AdminCompletenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function signIn(): Admin
    {
        $admin = Admin::create(['username' => 'complete-admin', 'password' => Hash::make('original-admin-password')]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        return $admin;
    }

    private function product(?Category $category = null, array $attributes = []): Product
    {
        $category ??= Category::create(['name' => 'Category', 'slug' => uniqid('category-'), 'is_active' => true]);
        return Product::create(array_replace([
            'category_id' => $category->id, 'name' => 'Product', 'slug' => uniqid('product-'),
            'price' => '10.00', 'is_active' => true,
        ], $attributes));
    }

    public function test_api_token_management_requires_an_admin_session(): void
    {
        $this->getJson('/api/admin/api-tokens')->assertUnauthorized();
        $this->postJson('/api/admin/api-tokens', ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->assertSame(0, ApiToken::count());
    }

    public function test_api_token_secret_is_returned_once_and_only_a_hash_is_saved(): void
    {
        $this->signIn();
        $response = $this->postJson('/api/admin/api-tokens', ['name' => 'ERP', 'token' => 'attacker-selected-secret'])->assertCreated();
        $secret = $response->json('plain_token');
        $this->assertSame(64, strlen($secret));
        $token = ApiToken::findOrFail($response->json('data.id'));
        $this->assertSame(hash('sha256', $secret), $token->getRawOriginal('token'));
        $this->assertNotSame('attacker-selected-secret', $secret);
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertArrayNotHasKey('token', $token->toArray());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $list = $this->getJson('/api/admin/api-tokens')->assertOk();
        $this->assertArrayNotHasKey('token', $list->json('data.0'));
        $this->assertArrayNotHasKey('plain_token', $list->json('data.0'));
        $this->assertStringNotContainsString($secret, OperationLog::all()->toJson());
        $this->assertStringNotContainsString($token->getRawOriginal('token'), OperationLog::all()->toJson());
    }

    public function test_api_token_can_be_renamed_disabled_reenabled_and_revoked(): void
    {
        $this->signIn();
        $response = $this->postJson('/api/admin/api-tokens', ['name' => 'Integration'])->assertCreated();
        $secret = $response->json('plain_token');
        $id = $response->json('data.id');
        $this->withToken($secret)->getJson('/api/v1/products')->assertOk();
        $this->assertNotNull(ApiToken::findOrFail($id)->last_used_at);
        $this->putJson('/api/admin/api-tokens/'.$id, ['name' => 'Renamed', 'is_active' => false])->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('is_active', false);
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $this->putJson('/api/admin/api-tokens/'.$id, ['is_active' => true, 'token' => 'replacement'])->assertOk();
        $this->assertSame(hash('sha256', $secret), ApiToken::findOrFail($id)->getRawOriginal('token'));
        $this->getJson('/api/v1/products')->assertOk();
        $this->deleteJson('/api/admin/api-tokens/'.$id)->assertOk();
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $this->assertDatabaseMissing('api_tokens', ['id' => $id]);
    }

    public function test_category_tables_paginate_without_truncating_selector_options(): void
    {
        $this->signIn();
        for ($i = 1; $i <= 5; $i++) {
            Category::create(['name' => 'Category '.$i, 'slug' => 'category-'.$i, 'is_active' => $i % 2 === 1]);
            ArticleCategory::create(['name' => 'Guides '.$i, 'slug' => 'guides-'.$i]);
        }

        foreach (['categories', 'article-categories'] as $endpoint) {
            $first = $this->getJson('/api/admin/'.$endpoint.'?page=1&per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 5);
            $second = $this->getJson('/api/admin/'.$endpoint.'?page=2&per_page=2')->assertOk()->assertJsonCount(2, 'data');
            $this->assertEmpty(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
            $this->getJson('/api/admin/'.$endpoint.'?page=3&per_page=2')->assertOk()->assertJsonCount(1, 'data');
            $this->getJson('/api/admin/'.$endpoint.'?per_page=2')->assertOk()->assertJsonCount(5, 'data');
        }
        $this->getJson('/api/admin/categories?page=1&is_active=0')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_admin_state_filters_match_products_articles_and_coupons(): void
    {
        $this->signIn();
        $active = $this->product();
        $inactive = $this->product(null, ['is_active' => false]);
        $this->getJson('/api/admin/products?is_active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inactive->id);
        $category = ArticleCategory::create(['name' => 'Guides', 'slug' => 'guides']);
        foreach ([true, false] as $published) {
            Article::create(['article_category_id' => $category->id, 'title' => $published ? 'Published' : 'Draft', 'slug' => $published ? 'published' : 'draft', 'content' => '<p>Guide</p>', 'is_published' => $published]);
        }
        $this->getJson('/api/admin/articles?is_published=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Draft');
        Coupon::create(['code' => 'ACTIVE', 'type' => 'fixed', 'value' => '1.00', 'product_id' => $active->id, 'is_active' => true]);
        $coupon = Coupon::create(['code' => 'INACTIVE', 'type' => 'fixed', 'value' => '1.00', 'product_id' => $inactive->id, 'is_active' => false]);
        Coupon::create(['code' => 'GLOBAL', 'type' => 'fixed', 'value' => '1.00', 'is_active' => true]);
        $this->getJson('/api/admin/coupons?is_active=0&product_id='.$inactive->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $coupon->id);
    }

    public function test_malformed_list_inputs_return_validation_errors_instead_of_database_errors(): void
    {
        $this->signIn();
        foreach (['categories', 'products', 'orders', 'articles', 'article-categories', 'coupons', 'blacklists', 'logs', 'api-tokens'] as $endpoint) {
            $this->getJson('/api/admin/'.$endpoint.'?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
            $this->getJson('/api/admin/'.$endpoint.'?pageSize=100000')->assertUnprocessable()->assertJsonValidationErrors('pageSize');
        }
        $this->getJson('/api/admin/products?name[]=array')->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->getJson('/api/admin/orders?start_date=invalid-date')->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->getJson('/api/admin/orders?sort[]=array')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/admin/orders?dir[]=array')->assertUnprocessable()->assertJsonValidationErrors('dir');
        $this->getJson('/api/admin/logs?admin[]=array')->assertUnprocessable()->assertJsonValidationErrors('admin');
    }

    public function test_card_content_search_and_file_import_preserve_zero_and_full_values(): void
    {
        $this->signIn();
        $product = $this->product();
        Card::create(['product_id' => $product->id, 'content' => '0', 'status' => 'unsold']);
        $longContent = str_repeat('secret-value-', 12);
        Card::create(['product_id' => $product->id, 'content' => $longContent, 'status' => 'sold']);
        $this->getJson('/api/admin/products/'.$product->id.'/cards?content=secret-value&status=sold')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.content', $longContent);
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('cards.txt', "0\r\nsecond-card\r\n");
        $this->post('/api/admin/products/'.$product->id.'/cards/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('count', 1)->assertJsonPath('skipped', 1);
        $this->assertSame(1, $product->cards()->where('content', '0')->count());
    }

    public function test_card_import_skips_batch_and_existing_secrets_even_when_already_sold(): void
    {
        $this->signIn();
        $product = $this->product();
        Card::create(['product_id' => $product->id, 'content' => 'delivered-secret', 'status' => 'sold']);
        $response = $this->postJson('/api/admin/products/'.$product->id.'/cards/import', ['content' => "delivered-secret\r\nnew-secret\r\nnew-secret\r\n0\r\n0\r\n\r\n"])->assertOk()->assertJsonPath('count', 2)->assertJsonPath('skipped', 3)->assertJsonPath('total', 5);
        $this->assertSame(3, $product->cards()->count());
        $this->assertSame(1, $product->cards()->where('content', 'delivered-secret')->count());
        $this->assertStringContainsString('跳过 3', $response->json('message'));
        $this->assertSame(0, app(\App\Services\CardService::class)->importCards($product->id, "new-secret\n0\ndelivered-secret"));
        $otherProduct = $this->product();
        $this->assertSame(1, app(\App\Services\CardService::class)->importCards($otherProduct->id, 'new-secret'));
    }

    public function test_blacklist_validation_normalization_duplicates_and_expiry_are_manageable(): void
    {
        $this->signIn();
        $this->postJson('/api/admin/blacklists', ['type' => 'ip', 'value' => 'not-an-ip'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson('/api/admin/blacklists', ['type' => 'email', 'value' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $response = $this->postJson('/api/admin/blacklists', ['type' => 'email', 'value' => 'Blocked@Example.Test', 'expires_at' => now()->addHour()->format('Y-m-d H:i:s')])->assertCreated()->assertJsonPath('value', 'blocked@example.test')->assertJsonPath('source', 'manual');
        $this->postJson('/api/admin/blacklists', ['type' => 'email', 'value' => 'BLOCKED@example.test'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->putJson('/api/admin/blacklists/'.$response->json('id'), ['type' => 'email', 'value' => 'blocked@example.test', 'expires_at' => null, 'reason' => 'Permanent'])->assertOk()->assertJsonPath('expires_at', null);
        $this->assertTrue(Blacklist::isBlocked('192.0.2.10', 'BLOCKED@EXAMPLE.TEST'));
        $this->getJson('/api/admin/blacklists?active=1&source=manual')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_blacklist_edits_clear_old_and_new_cache_keys_and_preserve_manual_corrections(): void
    {
        $this->signIn();
        $ban = Blacklist::create(['type' => 'ip', 'value' => '203.0.113.50', 'source' => 'honeypot', 'expires_at' => now()->addHour()]);
        $oldKey = 'blacklist:ip:'.md5($ban->value);
        $newKey = 'blacklist:email:'.md5('changed@example.test');
        Cache::store('redis')->put($oldKey, true, 300);
        Cache::store('redis')->put($newKey, false, 300);
        $this->putJson('/api/admin/blacklists/'.$ban->id, ['type' => 'email', 'value' => 'Changed@Example.Test', 'reason' => 'Corrected', 'expires_at' => now()->subMinute()->format('Y-m-d H:i:s')])->assertOk()->assertJsonPath('source', 'manual');
        $this->assertFalse(Cache::store('redis')->has($oldKey));
        $this->assertFalse(Cache::store('redis')->has($newKey));
        $this->assertFalse(Blacklist::isBlocked('203.0.113.50', 'changed@example.test'));
        $this->getJson('/api/admin/blacklists?active=0')->assertOk()->assertJsonPath('total', 1);

        $ipBan = Blacklist::create(['type' => 'ip', 'value' => '198.51.100.50', 'source' => 'honeypot', 'expires_at' => now()->addHour()]);
        $this->putJson('/api/admin/blacklists/'.$ipBan->id, ['type' => 'ip', 'value' => $ipBan->value, 'reason' => 'Manual correction', 'expires_at' => null])->assertOk();
        Blacklist::banIp($ipBan->value, 'Automated reason', 60);
        $this->assertSame('Manual correction', $ipBan->fresh()->reason);
        $this->assertNull($ipBan->fresh()->expires_at);
    }

    public function test_security_settings_validate_as_one_update_and_serialize_switches(): void
    {
        $this->signIn();
        Setting::set('site_name', 'Original', 'site');
        $this->postJson('/api/admin/settings', ['site_name' => 'Changed', 'honeypot_whitelist' => 'invalid-ip'])->assertUnprocessable()->assertJsonValidationErrors('honeypot_whitelist');
        $this->assertSame('Original', Setting::where('key', 'site_name')->value('value'));
        $this->postJson('/api/admin/settings', ['honeypot_ban_minutes' => -1, 'popup_interval_hours' => -1])->assertUnprocessable()->assertJsonValidationErrors(['honeypot_ban_minutes', 'popup_interval_hours']);
        $this->postJson('/api/admin/settings', ['honeypot_enabled' => false, 'honeypot_skip_reserved_ips' => true, 'honeypot_whitelist' => '203.0.113.10, 2001:db8::1', 'honeypot_ban_minutes' => null])->assertOk();
        $this->assertSame('0', Setting::where('key', 'honeypot_enabled')->value('value'));
        $this->assertSame('1', Setting::where('key', 'honeypot_skip_reserved_ips')->value('value'));
        $this->assertSame('10080', Setting::where('key', 'honeypot_ban_minutes')->value('value'));
    }

    public function test_password_change_keeps_current_session_and_invalidates_other_old_sessions(): void
    {
        $admin = $this->signIn();
        $oldFingerprint = AdminAuth::passwordFingerprint($admin->password);
        $this->postJson('/api/admin/password', ['current_password' => 'incorrect', 'new_password' => 'replacement-admin-password', 'new_password_confirmation' => 'replacement-admin-password'])->assertUnprocessable();
        $this->assertTrue(Hash::check('original-admin-password', $admin->fresh()->password));
        $this->postJson('/api/admin/password', ['current_password' => 'original-admin-password', 'new_password' => 'replacement-admin-password', 'new_password_confirmation' => 'replacement-admin-password'])->assertOk()->assertJsonStructure(['csrf_token']);
        $this->assertTrue(Hash::check('replacement-admin-password', $admin->fresh()->password));
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('username', 'complete-admin');
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => $oldFingerprint])->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_automatic_slugs_are_available_and_invalid_route_segments_are_rejected(): void
    {
        $this->signIn();
        $first = $this->postJson('/api/admin/categories', ['name' => 'Digital Goods', 'is_active' => true, 'sort_order' => null])->assertCreated()->assertJsonPath('sort_order', 0);
        $second = $this->postJson('/api/admin/categories', ['name' => 'Digital Goods', 'is_active' => true])->assertCreated();
        $this->assertNotSame($first->json('slug'), $second->json('slug'));
        $this->putJson('/api/admin/categories/'.$first->json('id'), ['name' => 'Digital Goods', 'sort_order' => null])->assertOk()->assertJsonPath('sort_order', 0);
        $this->postJson('/api/admin/article-categories', ['name' => 'Guides', 'sort_order' => null])->assertCreated()->assertJsonPath('sort_order', 0);
        $this->postJson('/api/admin/categories', ['name' => 'Invalid', 'slug' => 'invalid/path'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson('/api/admin/products', ['name' => 'Invalid', 'slug' => 'invalid/path', 'category_id' => $first->json('id'), 'price' => '10.00'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson('/api/admin/coupons', ['type' => 'fixed', 'value' => '1.00'])->assertCreated()->assertJsonStructure(['code']);
    }
}
