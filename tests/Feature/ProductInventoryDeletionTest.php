<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, Product};
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductInventoryDeletionTest extends TestCase
{
    private function actor(string $role = 'owner', array $permissions = []): void
    {
        $admin = Admin::create(['username' => uniqid('deletion-audit-'), 'password' => Hash::make('fixture-password'),
            'role' => $role, 'permissions' => $permissions, 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Fixture', 'slug' => uniqid('delete-category-')]);
        return Product::create(['category_id' => $category->id, 'name' => 'Fixture', 'slug' => uniqid('delete-product-'), 'price' => '10.00']);
    }

    public static function protectedInventory(): array
    {
        return ['paused' => ['disabled'], 'offline sold' => ['sold'], 'locked' => ['locked']];
    }

    #[DataProvider('protectedInventory')]
    public function test_product_deletion_does_not_erase_protected_inventory(string $status): void
    {
        $this->actor(); $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-PROTECTED', 'status' => $status]);
        $this->deleteJson('/api/admin/products/'.$product->id)->assertUnprocessable();
        $this->assertNotNull($product->fresh());
        $this->assertSame($status, $card->fresh()->status);
    }

    public function test_catalog_only_staff_cannot_delete_inventory_through_product_deletion(): void
    {
        $this->actor('staff', ['catalog:read', 'catalog:write']); $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-STOCK', 'status' => 'unsold']);
        $this->deleteJson('/api/admin/products/'.$product->id)->assertForbidden();
        $this->assertNotNull($card->fresh());
        $this->assertNotNull($product->fresh());
    }

    public function test_catalog_only_staff_can_delete_empty_products(): void
    {
        $this->actor('staff', ['catalog:read', 'catalog:write']); $product = $this->product();
        $this->deleteJson('/api/admin/products/'.$product->id)->assertOk();
        $this->assertNull($product->fresh());
    }

    public function test_authorized_staff_can_delete_product_and_unsold_inventory(): void
    {
        $this->actor('staff', ['catalog:read', 'catalog:write', 'cards:read', 'cards:write']); $product = $this->product();
        $card = Card::create(['product_id' => $product->id, 'content' => 'DUMMY-STOCK', 'status' => 'unsold']);
        $this->deleteJson('/api/admin/products/'.$product->id)->assertOk();
        $this->assertNull($product->fresh());
        $this->assertNull($card->fresh());
    }

    public function test_protected_card_beyond_the_first_batch_keeps_all_inventory(): void
    {
        $this->actor(); $product = $this->product();
        $rows = [];
        for ($i = 0; $i < 501; $i++) $rows[] = ['product_id' => $product->id, 'content' => 'DUMMY-BATCH-'.$i, 'status' => 'unsold'];
        Card::insert($rows);
        Card::create(['product_id' => $product->id, 'content' => 'DUMMY-PROTECTED-LAST', 'status' => 'sold']);
        $this->deleteJson('/api/admin/products/'.$product->id)->assertUnprocessable();
        $this->assertSame(502, $product->cards()->count());
        $this->assertNotNull($product->fresh());
    }
}
