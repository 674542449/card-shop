<?php

namespace Tests\Feature;

use App\Exceptions\CheckoutException;
use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, Order, Product};
use App\Services\OrderService;
use Illuminate\Support\Facades\{Hash, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminWorkflowSimplificationTest extends TestCase
{
    private function actor(string $role = 'owner', array $permissions = []): Admin
    {
        Http::preventStrayRequests();
        $admin = Admin::create(['username' => uniqid('workflow-'), 'password' => Hash::make('dummy-admin-password'),
            'role' => $role, 'permissions' => $permissions, 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        return $admin;
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Fixture', 'slug' => uniqid('category-'), 'is_active' => true]);
        return Product::create(['category_id' => $category->id, 'name' => 'Fixture product', 'slug' => uniqid('product-'),
            'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 10, 'low_stock_threshold' => 0, 'is_active' => true]);
    }

    private function checkout(Product $product): array
    {
        return ['product_id' => $product->id, 'quantity' => 1, 'email' => 'workflow@example.test',
            'query_password' => 'dummy-buyer-password', 'payment_method' => 'alipay', 'ip' => '192.0.2.38'];
    }

    public function test_pause_and_resume_change_stock_without_creating_sales_and_preserve_deduplication(): void
    {
        $this->actor(); $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-PAUSED', 'status' => 'unsold']);
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'disabled'])->assertOk()->assertJsonPath('card.status', 'disabled');
        $this->assertSame(0, $product->stockCount());
        $this->assertNull($card->fresh()->sold_at);
        $this->assertSame(0, Order::count());
        $this->getJson('/api/admin/products/'.$product->id.'/cards?status=disabled')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('stats.disabled', 1)->assertJsonPath('stats.unsold', 0)->assertJsonPath('stats.sold', 0);
        $this->postJson('/api/admin/products/'.$product->id.'/cards/import', ['content' => 'DUMMY-PAUSED'])->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('low_stock_count', 1)->assertJsonPath('today_revenue', 0);
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'sold'])->assertUnprocessable();
        $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'unsold'])->assertOk();
        $this->assertSame(1, $product->stockCount());
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('low_stock_count', 0);
    }

    public function test_checkout_never_reserves_disabled_inventory_and_fails_without_available_stock(): void
    {
        $this->actor(); $product = $this->product();
        $disabled = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-NOT-FOR-SALE', 'status' => 'disabled']);
        try { app(OrderService::class)->createOrder($this->checkout($product)); $this->fail('Paused stock must not be sold'); }
        catch (CheckoutException $e) { $this->assertStringContainsString('库存不足', $e->getMessage()); }
        $this->assertSame(0, Order::count());
        $available = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-AVAILABLE', 'status' => 'unsold']);
        $order = app(OrderService::class)->createOrder($this->checkout($product));
        $this->assertSame([$available->id], $order->cards()->pluck('id')->all());
        $this->assertSame('disabled', $disabled->fresh()->status);
        $this->assertNull($disabled->fresh()->order_id);
    }

    public function test_order_bound_cards_cannot_be_paused_or_recycled_and_offline_sales_are_distinct(): void
    {
        $this->actor(); $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-RESERVED', 'status' => 'unsold']);
        $order = app(OrderService::class)->createOrder($this->checkout($product));
        foreach (['disabled', 'sold', 'unsold'] as $status) {
            $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => $status, 'order_id' => null])->assertUnprocessable();
        }
        $card->update(['status' => 'sold', 'sold_at' => now()]);
        foreach (['disabled', 'unsold'] as $status) {
            $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => $status, 'order_id' => null])->assertUnprocessable();
        }
        $this->assertSame($order->id, $card->fresh()->order_id);
        $offline = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-OFFLINE', 'status' => 'unsold']);
        $this->patchJson('/api/admin/cards/'.$offline->id.'/status', ['status' => 'sold'])->assertOk();
        $this->assertNotNull($offline->fresh()->sold_at);
        $this->patchJson('/api/admin/cards/'.$offline->id.'/status', ['status' => 'disabled'])->assertUnprocessable();
        $this->assertSame(1, Order::count());
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('today_revenue', 0);
    }

    public function test_replacement_skips_disabled_stock(): void
    {
        $this->actor(); $product = $this->product();
        $old = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-OLD', 'status' => 'unsold']);
        $order = app(OrderService::class)->createOrder($this->checkout($product));
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        $old->update(['status' => 'sold', 'sold_at' => now()]);
        $disabled = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-DISABLED-REPLACEMENT', 'status' => 'disabled']);
        $available = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-NEW', 'status' => 'unsold']);
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', ['card_ids' => [$old->id], 'reason' => 'Fixture replacement', 'request_token' => (string) Str::uuid()])->assertCreated();
        $this->assertSame([$available->id], $order->deliveryCards()->pluck('id')->all());
        $this->assertSame('disabled', $disabled->fresh()->status);
        $this->patchJson('/api/admin/cards/'.$old->id.'/status', ['status' => 'disabled'])->assertUnprocessable();
    }

    public function test_read_only_inventory_and_unrelated_staff_cannot_pause_cards(): void
    {
        $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-PROTECTED', 'status' => 'unsold']);
        foreach ([['cards:read'], ['catalog:write'], ['orders:write']] as $permissions) {
            $this->actor('staff', $permissions);
            $this->patchJson('/api/admin/cards/'.$card->id.'/status', ['status' => 'disabled'])->assertForbidden();
        }
        $this->assertSame('unsold', $card->fresh()->status);
    }

    public function test_task_center_keeps_independent_read_and_retry_permissions(): void
    {
        foreach ([['notifications:read'], ['content:read'], ['maintenance:read', 'orders:read']] as $permissions) {
            $this->actor('staff', $permissions);
            $definition = $this->getJson('/api/admin/me')->assertOk()->json('permission_definition');
            $tasks = collect($definition['pages'])->firstWhere('path', '/tasks');
            $this->assertNotEmpty(array_intersect($tasks['any'], $definition['capabilities']));
            $this->getJson('/api/admin/notifications')->assertStatus(in_array('notifications:read', $permissions) ? 200 : 403);
            $this->getJson('/api/admin/seo-deliveries')->assertStatus(in_array('content:read', $permissions) ? 200 : 403);
            $this->getJson('/api/admin/maintenance/reconciliation-jobs')->assertStatus(in_array('maintenance:read', $permissions) ? 200 : 403);
            $this->postJson('/api/admin/notifications/999/retry')->assertForbidden();
            $this->postJson('/api/admin/seo-deliveries/999/retry')->assertForbidden();
            $this->postJson('/api/admin/maintenance/reconciliation-jobs/999/retry')->assertForbidden();
        }
    }
}
