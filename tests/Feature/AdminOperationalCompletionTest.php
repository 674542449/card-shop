<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, Order, OrderRefund, PaymentReceipt, Product, SeoDelivery};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminOperationalCompletionTest extends TestCase
{
    private function admin(array $permissions = [], string $role = 'owner'): Admin
    {
        $admin = Admin::create(['username' => uniqid('ops-'), 'password' => Hash::make('dummy-password-123'),
            'role' => $role, 'permissions' => $permissions, 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        return $admin;
    }

    private function product(array $attributes = []): Product
    {
        $category = Category::create(['name' => 'Dummy category', 'slug' => uniqid('cat-'), 'is_active' => true]);
        return Product::create(array_replace(['category_id' => $category->id, 'name' => 'Dummy product', 'slug' => uniqid('product-'),
            'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true], $attributes));
    }

    private function order(Product $product, array $attributes = []): Order
    {
        return Order::create(array_replace(['order_no' => uniqid('DUMMY-'), 'product_id' => $product->id, 'email' => 'dummy@example.test',
            'query_password' => Hash::make('dummy-password'), 'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00',
            'discount_amount' => '0.00', 'payment_method' => 'alipay', 'status' => 'paid', 'ip' => '192.0.2.11',
            'paid_at' => now(), 'expires_at' => now()->addMinutes(30)], $attributes));
    }

    public function test_refund_and_seo_page_sizes_do_not_hide_records(): void
    {
        $this->admin(); $order = $this->order($this->product());
        for ($i = 0; $i < 61; $i++) {
            OrderRefund::create(['order_id' => $order->id, 'amount' => '0.01', 'reason' => 'dummy', 'status' => 'rejected']);
            SeoDelivery::create(['dedupe_key' => 'dummy-'.$i, 'provider' => 'baidu', 'url' => 'https://example.test/'.$i, 'available_at' => now()]);
        }
        foreach (['refunds', 'seo-deliveries'] as $endpoint) {
            $this->getJson('/api/admin/'.$endpoint.'?per_page=100')->assertOk()->assertJsonCount(61, 'data')->assertJsonPath('total', 61);
            $first = $this->getJson('/api/admin/'.$endpoint.'?per_page=50&page=1')->assertOk()->json('data');
            $second = $this->getJson('/api/admin/'.$endpoint.'?per_page=50&page=2')->assertOk()->json('data');
            $this->assertCount(61, array_unique(array_column([...$first, ...$second], 'id')));
            $this->getJson('/api/admin/'.$endpoint.'?per_page=201')->assertUnprocessable();
        }
    }

    public function test_coupon_operator_can_search_catalog_labels_and_keep_an_inactive_binding(): void
    {
        $this->admin(['coupons:write'], 'staff');
        $active = $this->product(['name' => 'Needle active']);
        $inactive = $this->product(['name' => 'Old binding', 'is_active' => false]);
        Card::create(['product_id' => $active->id, 'content' => 'DUMMY-PRIVATE-STOCK', 'status' => 'unsold']);
        $this->getJson('/api/admin/products')->assertForbidden();
        $response = $this->getJson('/api/admin/coupons/product-options?q=Needle&include='.$inactive->id)->assertOk();
        $this->assertEqualsCanonicalizing([$active->id, $inactive->id], array_column($response->json('data'), 'id'));
        foreach ($response->json('data') as $product) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'is_active'], array_keys($product));
        }
        $this->assertStringNotContainsString('DUMMY-PRIVATE-STOCK', $response->getContent());
        $this->postJson('/api/admin/coupons', ['type' => 'fixed', 'value' => '1.00', 'product_id' => $active->id])->assertCreated();
    }

    public function test_duplicate_receipt_flag_is_consistent_for_list_detail_and_dashboard(): void
    {
        $this->admin(); $order = $this->order($this->product(), ['payment_no' => 'dummy-primary']);
        $receipt = PaymentReceipt::create(['order_id' => $order->id, 'channel' => 'epay', 'trade_no' => 'dummy-extra', 'amount' => '10.00',
            'received_at' => now(), 'review_reason' => 'Duplicate receipt']);
        $this->getJson('/api/admin/orders?payment_review=1')->assertOk()->assertJsonPath('data.0.has_payment_review', true);
        $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('has_payment_review', true);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('recent_orders.0.has_payment_review', true)->assertJsonPath('payment_review_orders', 1);
        $receipt->update(['review_resolved_at' => now()]);
        $this->getJson('/api/admin/orders/'.$order->id)->assertJsonPath('has_payment_review', false);
        $this->getJson('/api/admin/dashboard')->assertJsonPath('payment_review_orders', 0);
    }

    public function test_financial_cache_keeps_live_tasks_and_expiry_refreshes_day_buckets(): void
    {
        // ArrayStore uses the application's clock; Redis TTL is a wall clock.
        config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array', 'serialize' => false]]);
        $this->admin(); $product = $this->product();
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->order($product, ['paid_at' => now()->copy()->startOfDay(), 'total_amount' => '10.00']);
        $this->order($product, ['paid_at' => now()->copy()->startOfDay()->subSecond(), 'total_amount' => '20.00']);
        $first = $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('today_revenue', 10)->json();
        $this->assertSame(20.0, (float) $first['chart_data'][5]);
        $this->assertSame(10.0, (float) $first['chart_data'][6]);
        $this->order($product, ['status' => 'pending', 'paid_at' => null]);
        $this->order($product, ['total_amount' => '5.00']);
        $this->getJson('/api/admin/dashboard')->assertJsonPath('today_revenue', 10)->assertJsonPath('pending_orders', 1);
        $this->travel(16)->seconds();
        $this->getJson('/api/admin/dashboard')->assertJsonPath('today_revenue', 15)->assertJsonPath('total_revenue', 35);
        Cache::flush();
    }
}
