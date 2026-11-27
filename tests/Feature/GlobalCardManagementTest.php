<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, OperationLog, Order, Product};
use App\Services\OrderService;
use Illuminate\Support\Facades\{Hash, Http};
use Tests\TestCase;

class GlobalCardManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function actor(string $role = 'owner', array $permissions = []): Admin
    {
        $admin = Admin::create([
            'username' => uniqid('cards-admin-'),
            'password' => Hash::make('admin-secret-password-123'),
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);

        return $admin;
    }

    private function createCatalogFixture(): array
    {
        $catA = Category::create(['name' => '亚太区域', 'slug' => uniqid('apac-'), 'is_active' => true]);
        $catB = Category::create(['name' => '北美区域', 'slug' => uniqid('na-'), 'is_active' => true]);

        $prodA = Product::create([
            'category_id' => $catA->id,
            'name' => '东京节点',
            'slug' => uniqid('tokyo-'),
            'price' => '100.00',
            'min_quantity' => 1,
            'max_quantity' => 10,
            'low_stock_threshold' => 1,
            'low_stock_notified' => true,
            'is_active' => true,
        ]);
        $prodB = Product::create([
            'category_id' => $catB->id,
            'name' => '圣何塞节点',
            'slug' => uniqid('sjc-'),
            'price' => '120.00',
            'min_quantity' => 1,
            'max_quantity' => 10,
            'low_stock_threshold' => 1,
            'is_active' => true,
        ]);

        // Unsold cards across both categories/products
        $unsoldA1 = Card::create(['product_id' => $prodA->id, 'content' => 'TOKYO-UNSOLD-1', 'status' => 'unsold']);
        $unsoldA2 = Card::create(['product_id' => $prodA->id, 'content' => '=FORMULA-INJECTION', 'status' => 'unsold']);
        $unsoldB1 = Card::create(['product_id' => $prodB->id, 'content' => 'SJC-UNSOLD-1', 'status' => 'unsold']);

        // Disabled cards across both products
        $disabledA = Card::create(['product_id' => $prodA->id, 'content' => 'TOKYO-DISABLED-1', 'status' => 'disabled']);
        $disabledB = Card::create(['product_id' => $prodB->id, 'content' => 'SJC-DISABLED-1', 'status' => 'disabled']);

        // Offline sold card (must never be touched by bulk pause/resume/delete)
        $offlineSold = Card::create(['product_id' => $prodA->id, 'content' => 'TOKYO-OFFLINE-SOLD', 'status' => 'sold', 'sold_at' => now()]);

        // Locked card attached to a real pending order (must never be touched)
        $lockedForOrder = Card::create(['product_id' => $prodB->id, 'content' => 'SJC-LOCKED-1', 'status' => 'unsold']);
        // Ensure order grabs a specific card by creating order while only $lockedForOrder is created? Actually prodB already has $unsoldB1, so order will lock $unsoldB1 (lowest id). Let's inspect which card was locked!
        $order = app(OrderService::class)->createOrder([
            'product_id' => $prodB->id,
            'email' => 'buyer@example.test',
            'query_password' => 'buyer-pass-123',
            'quantity' => 1,
            'payment_method' => 'alipay',
            'ip' => '192.0.2.88',
        ]);
        $lockedCard = $order->cards()->firstOrFail();

        // Delivered order-bound sold card + retired replaced card (must never be touched)
        $deliveredCard = Card::create([
            'product_id' => $prodA->id,
            'order_id' => $order->id,
            'content' => 'TOKYO-ORDER-SOLD',
            'status' => 'sold',
            'sold_at' => now(),
        ]);
        $replacedCard = Card::create([
            'product_id' => $prodA->id,
            'order_id' => $order->id,
            'content' => 'TOKYO-REPLACED',
            'status' => 'sold',
            'sold_at' => now(),
        ]);
        $replacedCard->forceFill(['replaced_at' => now()])->save();

        return compact(
            'catA', 'catB', 'prodA', 'prodB',
            'unsoldA1', 'unsoldA2', 'unsoldB1', 'lockedForOrder',
            'disabledA', 'disabledB', 'offlineSold', 'lockedCard', 'deliveredCard', 'replacedCard', 'order'
        );
    }

    public function test_global_cards_listing_filters_stats_and_product_options(): void
    {
        $this->actor();
        $f = $this->createCatalogFixture();

        // Unfiltered listing returns all 9 cards with product & category info and accurate stats
        $all = $this->getJson('/api/admin/cards')
            ->assertOk()
            ->assertJsonPath('total', 9)
            ->assertJsonPath('stats.total', 9)
            ->assertJsonPath('stats.unsold', 3)
            ->assertJsonPath('stats.disabled', 2)
            ->assertJsonPath('stats.locked', 1)
            ->assertJsonPath('stats.sold', 3)
            ->assertJsonPath('global_stats.total', 9);

        $firstProductNames = collect($all->json('data'))->pluck('product.name')->filter()->unique()->all();
        $this->assertContains('东京节点', $firstProductNames);
        $this->assertContains('圣何塞节点', $firstProductNames);

        // Filter by category_id scopes both data and stats while preserving global_stats
        $this->getJson('/api/admin/cards?category_id=' . $f['catA']->id)
            ->assertOk()
            ->assertJsonPath('total', 6)
            ->assertJsonPath('stats.unsold', 2)
            ->assertJsonPath('stats.disabled', 1)
            ->assertJsonPath('global_stats.total', 9);

        // Exact cross-product content fingerprint lookup finds the card across all products
        $this->getJson('/api/admin/cards?content=' . urlencode('TOKYO-DISABLED-1'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $f['disabledA']->id)
            ->assertJsonPath('data.0.product.name', '东京节点');

        // Product options returns lightweight inventory counts for every product
        $opts = $this->getJson('/api/admin/cards/product-options')->assertOk()->json('data');
        $byId = collect($opts)->keyBy('id');
        $this->assertSame(2, $byId[$f['prodA']->id]['stock_count']);
        $this->assertSame(1, $byId[$f['prodA']->id]['disabled_count']);
        $this->assertSame(1, $byId[$f['prodB']->id]['stock_count']);
        $this->assertSame(1, $byId[$f['prodB']->id]['disabled_count']);
    }

    public function test_bulk_pause_resume_and_clear_enforce_scopes_and_strictly_protect_locked_and_sold_cards(): void
    {
        $this->actor();
        $f = $this->createCatalogFixture();

        // 1. Pause unsold across category A only -> pauses prodA's 2 unsold cards, leaves prodB's unsold untouched
        $this->postJson('/api/admin/cards/bulk-action', [
            'action' => 'pause_unsold',
            'scope' => 'category',
            'category_id' => $f['catA']->id,
        ])->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('affected_products', 1);

        $this->assertSame('disabled', $f['unsoldA1']->fresh()->status);
        $this->assertSame('disabled', $f['unsoldA2']->fresh()->status);
        $this->assertSame('unsold', $f['lockedForOrder']->fresh()->status); // prodB's remaining unsold card
        // Protected cards untouched
        $this->assertSame('locked', $f['lockedCard']->fresh()->status);
        $this->assertSame('sold', $f['offlineSold']->fresh()->status);
        $this->assertSame('sold', $f['deliveredCard']->fresh()->status);
        $this->assertSame('sold', $f['replacedCard']->fresh()->status);

        // 2. Resume ALL disabled cards across all products -> restores 4 disabled cards (3 on prodA + 1 on prodB)
        // and clears low_stock_notified on prodA because new stock (3) > threshold (1)
        $this->postJson('/api/admin/cards/bulk-action', [
            'action' => 'resume_disabled',
            'scope' => 'all',
        ])->assertOk()
            ->assertJsonPath('count', 4)
            ->assertJsonPath('affected_products', 2);

        $this->assertFalse((bool) $f['prodA']->fresh()->low_stock_notified);
        $this->assertSame('unsold', $f['disabledA']->fresh()->status);
        $this->assertSame('unsold', $f['disabledB']->fresh()->status);
        // Protected cards STILL untouched
        $this->assertSame('locked', $f['lockedCard']->fresh()->status);
        $this->assertSame('sold', $f['offlineSold']->fresh()->status);
        $this->assertSame('sold', $f['deliveredCard']->fresh()->status);

        // 3. Pause selected card IDs including a locked & sold card -> only the unsold card changes
        $this->postJson('/api/admin/cards/bulk-action', [
            'action' => 'pause_unsold',
            'scope' => 'cards',
            'card_ids' => [$f['disabledA']->id, $f['lockedCard']->id, $f['offlineSold']->id, $f['deliveredCard']->id],
        ])->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('affected_products', 1);

        $this->assertSame('disabled', $f['disabledA']->fresh()->status);
        $this->assertSame('locked', $f['lockedCard']->fresh()->status);
        $this->assertSame('sold', $f['offlineSold']->fresh()->status);
        $this->assertSame('sold', $f['deliveredCard']->fresh()->status);

        // 4. Delete ALL disabled cards across all products -> deletes ONLY disabledA, leaves unsold/locked/sold intact
        $this->postJson('/api/admin/cards/bulk-action', [
            'action' => 'delete_disabled',
            'scope' => 'all',
        ])->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('affected_products', 1);

        $this->assertNull(Card::find($f['disabledA']->id));
        $this->assertNotNull(Card::find($f['unsoldA1']->id));
        $this->assertNotNull(Card::find($f['lockedCard']->id));
        $this->assertNotNull(Card::find($f['deliveredCard']->id));

        // 5. Delete ALL unsold cards across selected products [prodA, prodB] -> deletes remaining 4 unsold cards only
        $this->postJson('/api/admin/cards/bulk-action', [
            'action' => 'delete_unsold',
            'scope' => 'products',
            'product_ids' => [$f['prodA']->id, $f['prodB']->id],
        ])->assertOk()
            ->assertJsonPath('count', 4)
            ->assertJsonPath('affected_products', 2);

        $this->assertSame(0, Card::where('status', 'unsold')->count());
        // Locked, offline sold, delivered sold, and replaced cards all survive intact!
        $this->assertSame(1, Card::where('status', 'locked')->count());
        $this->assertSame(3, Card::where('status', 'sold')->count());

        // Operation logs never contain plaintext card secrets
        $logText = OperationLog::pluck('detail')->implode("\n");
        $this->assertStringNotContainsString('TOKYO-UNSOLD-1', $logText);
        $this->assertStringNotContainsString('FORMULA-INJECTION', $logText);
    }

    public function test_streaming_export_supports_txt_and_formula_safe_csv_with_filters(): void
    {
        $this->actor();
        $f = $this->createCatalogFixture();

        // TXT export of unsold cards on prodA
        $txt = $this->get('/api/admin/cards/export?format=txt&status=unsold&product_id=' . $f['prodA']->id)
            ->assertOk();
        $txt->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('text/plain', (string) $txt->headers->get('Content-Type'));
        $body = $txt->streamedContent();
        $this->assertStringContainsString('TOKYO-UNSOLD-1', $body);
        $this->assertStringContainsString('=FORMULA-INJECTION', $body);
        $this->assertStringNotContainsString('TOKYO-DISABLED-1', $body);
        $this->assertStringNotContainsString('SJC-UNSOLD-1', $body);

        // CSV export across selected product_ids neutralizes leading '=' formula injection
        $csv = $this->get('/api/admin/cards/export?format=csv&product_ids=' . $f['prodA']->id . ',' . $f['prodB']->id)
            ->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $csvBody = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFID,所属分类,商品名称,卡密内容", $csvBody);
        $this->assertStringContainsString('"\'=FORMULA-INJECTION"', $csvBody);
        $this->assertStringContainsString('"东京节点"', $csvBody);
        $this->assertStringContainsString('"圣何塞节点"', $csvBody);
    }

    public function test_global_card_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/cards')->assertUnauthorized();
        $this->getJson('/api/admin/cards/product-options')->assertUnauthorized();
        $this->getJson('/api/admin/cards/export')->assertUnauthorized();
        $this->get('/api/admin/cards/export')->assertRedirect();
        $this->postJson('/api/admin/cards/bulk-action', ['action' => 'pause_unsold', 'scope' => 'all'])->assertUnauthorized();
    }
}
