<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Article, ArticleCategory, Card, Category, Coupon, Order, Product};
use Illuminate\Support\Facades\{Hash, Http, Route};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRequestSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function signIn(array $attributes = []): Admin
    {
        $admin = Admin::create(array_replace(['username' => 'security-admin', 'password' => Hash::make('admin-security-password'),
            'role' => 'owner', 'permissions' => [], 'is_active' => true], $attributes));
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);

        return $admin;
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Security category', 'slug' => 'security-category', 'is_active' => true]);

        return Product::create(['category_id' => $category->id, 'name' => 'Security product', 'slug' => 'security-product',
            'price' => '10.00', 'is_active' => true]);
    }

    private function productPayload(Product $product, array $changes = []): array
    {
        return array_replace(['name' => 'Created product', 'category_id' => $product->category_id, 'price' => '10.00'], $changes);
    }

    private function pendingOrder(Product $product): Order
    {
        return Order::create(['order_no' => generate_order_no(), 'product_id' => $product->id,
            'email' => 'private-buyer@example.test', 'query_password' => Hash::make('private-buyer-password'),
            'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00', 'payment_method' => 'alipay',
            'status' => 'pending', 'ip' => '192.0.2.50', 'expires_at' => now()->addMinutes(30)]);
    }

    public function test_anonymous_admin_requests_cannot_enumerate_private_record_ids(): void
    {
        $product = $this->product();
        $order = $this->pendingOrder($product);
        $card = Card::create(['product_id' => $product->id, 'content' => 'private-enumeration-secret', 'status' => 'unsold']);
        foreach ([$product->id, PHP_INT_MAX] as $id) {
            $this->getJson('/api/admin/products/'.$id)->assertUnauthorized()->assertJsonMissingPath('id');
            $this->getJson('/api/admin/products/'.$id.'/cards')->assertUnauthorized()->assertDontSee('private-enumeration-secret');
        }
        foreach ([$order->id, PHP_INT_MAX] as $id) {
            $this->getJson('/api/admin/orders/'.$id)->assertUnauthorized()->assertDontSee('private-buyer@example.test');
            $this->postJson('/api/admin/orders/'.$id.'/paid')->assertUnauthorized();
        }
        foreach ([$card->id, PHP_INT_MAX] as $id) {
            $this->patchJson('/api/admin/cards/'.$id.'/status', ['status' => 'sold'])->assertUnauthorized();
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('unsold', $card->fresh()->status);
    }

    public function test_staff_without_permissions_cannot_enumerate_private_record_ids(): void
    {
        $product = $this->product();
        $order = $this->pendingOrder($product);
        $card = Card::create(['product_id' => $product->id, 'content' => 'private-enumeration-secret', 'status' => 'unsold']);
        $this->signIn(['role' => 'staff', 'permissions' => []]);
        foreach ([$product->id, PHP_INT_MAX] as $id) {
            $this->getJson('/api/admin/products/'.$id)->assertForbidden()->assertJsonMissingPath('id');
            $this->getJson('/api/admin/products/'.$id.'/cards')->assertForbidden()->assertDontSee('private-enumeration-secret');
        }
        foreach ([$order->id, PHP_INT_MAX] as $id) {
            $this->getJson('/api/admin/orders/'.$id)->assertForbidden()->assertDontSee('private-buyer@example.test');
            $this->postJson('/api/admin/orders/'.$id.'/paid')->assertForbidden();
        }
        foreach ([$card->id, PHP_INT_MAX] as $id) {
            $this->patchJson('/api/admin/cards/'.$id.'/status', ['status' => 'sold'])->assertForbidden();
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('unsold', $card->fresh()->status);
    }

    public function test_authorized_reads_keep_binding_semantics_without_leaking_through_forbidden_writes(): void
    {
        $product = $this->product();
        $order = $this->pendingOrder($product);
        $card = Card::create(['product_id' => $product->id, 'content' => 'private-enumeration-secret', 'status' => 'unsold']);
        $this->signIn(['role' => 'staff', 'permissions' => ['catalog:read', 'orders:read']]);
        $this->getJson('/api/admin/products/'.$product->id)->assertOk()->assertJsonPath('id', $product->id);
        $this->getJson('/api/admin/products/'.PHP_INT_MAX)->assertNotFound();
        $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('id', $order->id);
        $this->getJson('/api/admin/orders/'.PHP_INT_MAX)->assertNotFound();
        foreach ([$order->id, PHP_INT_MAX] as $id) {
            $this->postJson('/api/admin/orders/'.$id.'/paid')->assertForbidden();
        }
        foreach ([$card->id, PHP_INT_MAX] as $id) {
            $this->patchJson('/api/admin/cards/'.$id.'/status', ['status' => 'sold'])->assertForbidden();
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('unsold', $card->fresh()->status);
    }

    public function test_percent_encoded_paths_do_not_bypass_staff_catalog_permissions(): void
    {
        $product = $this->product();
        Card::create(['product_id' => $product->id, 'content' => 'private-unsold-secret', 'status' => 'unsold']);
        $this->signIn(['role' => 'staff', 'permissions' => []]);

        $this->getJson('/api/admin/products')->assertForbidden();
        $this->getJson('/api/admin/%70roducts')->assertForbidden()->assertDontSee('private-unsold-secret');
        $this->getJson('/api/admin/%70roducts/'.$product->id.'/cards')->assertForbidden()->assertDontSee('private-unsold-secret');
        $this->getJson('/api/%61dmin/products')->assertForbidden();
        $this->postJson('/api/admin/%70roducts', $this->productPayload($product))->assertForbidden();
        $this->assertSame(1, Product::count());
    }

    public function test_percent_encoded_admin_path_cannot_create_an_owner_as_staff(): void
    {
        $staff = $this->signIn(['role' => 'staff', 'permissions' => ['catalog:write']]);
        $payload = ['username' => 'forged-owner', 'password' => 'forged-owner-password', 'role' => 'owner', 'permissions' => [], 'is_active' => true];
        $this->postJson('/api/admin/admins', $payload)->assertForbidden();
        $this->postJson('/api/admin/%61dmins', $payload)->assertForbidden();
        $this->assertSame(1, Admin::count());
        $this->assertSame('staff', $staff->fresh()->role);
    }

    public function test_percent_encoded_paths_still_work_for_an_authorized_owner(): void
    {
        $this->signIn();
        $this->getJson('/api/admin/%64ashboard')->assertOk();
        $this->getJson('/api/%61dmin/products')->assertOk();
        $this->getJson('/api/admin/%6de')->assertOk()->assertJsonPath('role', 'owner');
    }

    public function test_unmapped_admin_routes_fail_closed_for_staff(): void
    {
        Route::middleware(['web', 'admin.auth'])->post('/api/admin/unmapped-security-action', fn () => response()->json(['executed' => true]));
        $this->signIn(['role' => 'staff', 'permissions' => []]);
        $this->postJson('/api/admin/unmapped-security-action')->assertForbidden()->assertJsonMissing(['executed' => true]);
    }

    public function test_read_only_staff_cannot_forge_writes_or_owner_fields(): void
    {
        $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'private-unsold-secret', 'status' => 'unsold']);
        $staff = $this->signIn(['role' => 'staff', 'permissions' => ['catalog:read', 'orders:read', 'coupons:read', 'tokens:read']]);
        $this->postJson('/api/admin/products', $this->productPayload($product))->assertForbidden();
        $this->putJson('/api/admin/products/'.$product->id, $this->productPayload($product, ['price' => '0.01']))->assertForbidden();
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'sold', 'order_id' => 1])->assertForbidden();
        $this->deleteJson('/api/admin/cards/'.$card->id)->assertForbidden();
        $this->postJson('/api/admin/products/'.$product->id.'/cards/import', ['content' => 'forged-card'])->assertForbidden();
        $this->postJson('/api/admin/coupons', ['type' => 'percent', 'value' => 100])->assertForbidden();
        $this->postJson('/api/admin/api-tokens', ['name' => 'forged-token'])->assertForbidden();
        $this->putJson('/api/admin/admins/'.$staff->id, ['username' => $staff->username, 'role' => 'owner', 'permissions' => [], 'is_active' => true])->assertForbidden();
        $this->assertSame('10.00', $product->fresh()->price);
        $this->assertSame('unsold', $card->fresh()->status);
        $this->assertSame(1, Card::count());
        $this->assertSame('staff', $staff->fresh()->role);
    }

    public function test_disabled_or_changed_password_sessions_cannot_write(): void
    {
        $product = $this->product();
        $admin = $this->signIn();
        $admin->update(['is_active' => false]);
        $this->postJson('/api/admin/products', $this->productPayload($product))->assertUnauthorized();
        $admin->update(['is_active' => true, 'password' => Hash::make('new-security-password')]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => 'forged-fingerprint'])
            ->postJson('/api/admin/products', $this->productPayload($product))->assertUnauthorized();
        $this->assertSame(1, Product::count());
    }

    public static function malformedProductInputs(): array
    {
        return [
            'array foreign key' => [['category_id' => 'array'], 'category_id'],
            'price database overflow' => [['price' => '100000000.00'], 'price'],
            'sub-cent price' => [['price' => '0.011'], 'price'],
            'minimum database overflow' => [['min_quantity' => 2147483648, 'max_quantity' => 2147483648], 'min_quantity'],
            'maximum database overflow' => [['max_quantity' => 2147483648], 'max_quantity'],
            'sort database overflow' => [['sort_order' => 2147483648], 'sort_order'],
            'tier price database overflow' => [['wholesale_prices' => [['min_quantity' => 2, 'price' => '100000000.00']]], 'wholesale_prices.0.price'],
            'sub-cent tier price' => [['wholesale_prices' => [['min_quantity' => 2, 'price' => '0.011']]], 'wholesale_prices.0.price'],
            'tier quantity database overflow' => [['wholesale_prices' => [['min_quantity' => 2147483648, 'price' => '8.00']]], 'wholesale_prices.0.min_quantity'],
            'sort negative database overflow' => [['sort_order' => -2147483649], 'sort_order'],
        ];
    }

    #[DataProvider('malformedProductInputs')]
    public function test_malformed_product_writes_are_rejected_before_database_changes(array $changes, string $field): void
    {
        $product = $this->product();
        $this->signIn();
        if (($changes['category_id'] ?? null) === 'array') {
            $changes['category_id'] = [$product->category_id];
        }
        $this->postJson('/api/admin/products', $this->productPayload($product, $changes))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson('/api/admin/products/'.$product->id, $this->productPayload($product, $changes))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(1, Product::count());
        $this->assertSame('10.00', $product->fresh()->price);
        $this->assertSame(0, $product->wholesalePrices()->count());
    }

    public function test_array_coupon_product_id_is_rejected_before_database_changes(): void
    {
        $product = $this->product();
        $this->signIn();
        $this->postJson('/api/admin/coupons', ['code' => 'FORGED-COUPON', 'type' => 'fixed', 'value' => '2.00', 'product_id' => [$product->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertSame(0, Coupon::count());
        $coupon = Coupon::create(['code' => 'EXISTING-COUPON', 'type' => 'fixed', 'value' => '1.00']);
        $this->putJson('/api/admin/coupons/'.$coupon->id, ['type' => 'fixed', 'value' => '2.00', 'product_id' => [$product->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertSame('1.00', $coupon->fresh()->value);
    }

    public function test_admin_usernames_cannot_exceed_the_database_column(): void
    {
        $admin = $this->signIn();
        $this->postJson('/api/admin/admins', ['username' => str_repeat('a', 51), 'password' => 'valid-admin-password',
            'role' => 'staff', 'permissions' => [], 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame(1, Admin::count());
        $this->putJson('/api/admin/admins/'.$admin->id, ['username' => str_repeat('b', 51), 'role' => 'owner', 'permissions' => [], 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame('security-admin', $admin->fresh()->username);
    }

    public static function malformedBoundIds(): array
    {
        return [
            'product SQL-like ID' => ['products', '1%20OR%201%3D1'],
            'order SQL-like ID' => ['orders', '1%20OR%201%3D1'],
            'product signed bigint overflow' => ['products', '9223372036854775808'],
            'order signed bigint overflow' => ['orders', '9223372036854775808'],
            'product array-looking ID' => ['products', '1%5B%5D'],
            'order array-looking ID' => ['orders', '1%5B%5D'],
        ];
    }

    #[DataProvider('malformedBoundIds')]
    public function test_malformed_bound_ids_are_rejected_before_database_binding_even_when_anonymous(string $area, string $id): void
    {
        $this->getJson('/api/admin/'.$area.'/'.$id)->assertNotFound();
    }

    public function test_category_sort_orders_are_validated_against_database_boundaries(): void
    {
        $this->signIn();
        foreach (['categories', 'article-categories'] as $area) {
            foreach ([-2147483649, 2147483648] as $sort) {
                $this->postJson('/api/admin/'.$area, ['name' => 'Invalid sort', 'sort_order' => $sort])->assertUnprocessable()->assertJsonValidationErrors('sort_order');
            }
        }
        $this->assertSame(0, Category::count());
        $this->assertSame(0, ArticleCategory::count());
    }

    public function test_article_category_arrays_and_oversized_content_are_rejected_before_writes(): void
    {
        $this->signIn();
        $category = ArticleCategory::create(['name' => 'Guides', 'slug' => 'guides']);
        $article = Article::create(['title' => 'Existing', 'slug' => 'existing', 'article_category_id' => $category->id, 'content' => 'Original']);
        $payload = ['title' => 'New article', 'article_category_id' => [$category->id], 'content' => 'Article text'];
        $this->postJson('/api/admin/articles', $payload)->assertUnprocessable()->assertJsonValidationErrors('article_category_id');
        $this->putJson('/api/admin/articles/'.$article->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('article_category_id');
        $payload = ['title' => 'New article', 'article_category_id' => $category->id, 'content' => str_repeat('x', 1000001)];
        $this->postJson('/api/admin/articles', $payload)->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->putJson('/api/admin/articles/'.$article->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->assertSame(1, Article::count());
        $this->assertSame('Original', $article->fresh()->content);
    }

    public function test_oversized_product_description_is_rejected_before_writes(): void
    {
        $product = $this->product();
        $this->signIn();
        $payload = $this->productPayload($product, ['description' => str_repeat('x', 1000001)]);
        $this->postJson('/api/admin/products', $payload)->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->putJson('/api/admin/products/'.$product->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertSame(1, Product::count());
        $this->assertNull($product->fresh()->description);
    }

    public function test_unknown_sensitive_fields_are_not_mass_assigned_to_products_or_coupons(): void
    {
        $product = $this->product();
        $this->signIn();
        $response = $this->postJson('/api/admin/products', $this->productPayload($product, ['id' => 999999,
            'low_stock_notified' => true, 'cards' => [['content' => 'forged-card', 'status' => 'sold']],
            'wholesale_prices' => [['min_quantity' => 2, 'price' => '8.50', 'product_id' => $product->id, 'id' => 999999]],
        ]))->assertCreated();
        $created = Product::findOrFail($response->json('id'));
        $this->assertNotSame(999999, $created->id);
        $this->assertFalse($created->low_stock_notified);
        $this->assertSame(0, Card::count());
        $this->assertSame(0, $product->wholesalePrices()->count());
        $this->assertSame($created->id, $created->wholesalePrices()->firstOrFail()->product_id);
        $this->assertNotSame(999999, $created->wholesalePrices()->firstOrFail()->id);

        $response = $this->postJson('/api/admin/coupons', ['code' => 'SERVER-COUPON', 'type' => 'fixed', 'value' => '1.00',
            'id' => 999999, 'used_count' => -100])->assertCreated();
        $coupon = Coupon::findOrFail($response->json('id'));
        $this->assertNotSame(999999, $coupon->id);
        $this->assertSame(0, $coupon->used_count);
        $this->putJson('/api/admin/coupons/'.$coupon->id, ['type' => 'fixed', 'value' => '2.00', 'used_count' => -100])->assertOk();
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_manual_payment_confirmation_uses_stored_order_data_and_preserves_delivered_cards(): void
    {
        $product = $this->product();
        $order = Order::create(['order_no' => generate_order_no(), 'product_id' => $product->id, 'email' => 'buyer@example.test',
            'query_password' => Hash::make('buyer-password'), 'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00',
            'discount_amount' => '0.00', 'payment_method' => 'alipay', 'status' => 'pending', 'ip' => '192.0.2.20', 'expires_at' => now()->addMinutes(15)]);
        $card = Card::create(['product_id' => $product->id, 'content' => 'server-delivered-secret', 'status' => 'locked', 'order_id' => $order->id]);
        $this->signIn();
        $this->postJson('/api/admin/orders/'.$order->id.'/paid', ['quantity' => 100, 'unit_price' => 0, 'total_amount' => 0,
            'status' => 'paid', 'cards' => [['content' => 'forged-card']]])->assertOk();
        $this->assertSame('10.00', $order->fresh()->total_amount);
        $this->assertSame(1, $order->fresh()->quantity);
        $this->assertSame('server-delivered-secret', $order->cards()->firstOrFail()->content);
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'unsold', 'order_id' => null])->assertUnprocessable();
        $this->deleteJson('/api/admin/cards/'.$card->id)->assertUnprocessable();
        $this->postJson('/api/admin/orders/'.$order->id.'/close', ['status' => 'closed'])->assertUnprocessable();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('sold', $card->fresh()->status);
        $this->assertSame($order->id, $card->fresh()->order_id);
    }

    public function test_admin_writes_and_login_require_a_matching_csrf_token(): void
    {
        $product = $this->product();
        $this->signIn();
        $originalEnvironment = $this->app['env'];
        try {
            $this->app['env'] = 'local';
            $this->postJson('/api/admin/products', $this->productPayload($product, ['_token' => 'forged']))->assertStatus(419);
            $this->deleteJson('/api/admin/products/'.$product->id, ['_token' => 'forged'])->assertStatus(419);
            $this->postJson('/api/admin/login', ['username' => 'security-admin', 'password' => 'admin-security-password', '_token' => 'forged'])->assertStatus(419);
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
        $this->assertSame(1, Product::count());
    }

    public function test_staff_password_change_is_bound_to_the_authenticated_account(): void
    {
        $owner = Admin::create(['username' => 'other-owner', 'password' => Hash::make('other-owner-password'), 'role' => 'owner', 'is_active' => true]);
        $ownerHash = $owner->password;
        $staff = $this->signIn(['role' => 'staff', 'permissions' => []]);
        $this->postJson('/api/admin/password', ['current_password' => 'admin-security-password', 'new_password' => 'new-staff-password',
            'new_password_confirmation' => 'new-staff-password', 'admin_id' => $owner->id, 'role' => 'owner', 'permissions' => ['orders:write']])->assertOk();
        $this->assertTrue(Hash::check('new-staff-password', $staff->fresh()->password));
        $this->assertSame('staff', $staff->fresh()->role);
        $this->assertSame([], $staff->fresh()->permissions);
        $this->assertSame($ownerHash, $owner->fresh()->password);
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('id', $staff->id)->assertJsonPath('role', 'staff');
        $this->getJson('/api/admin/admins')->assertForbidden();
    }
}
