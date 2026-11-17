<?php

namespace Tests\Feature;

use App\Models\{Card, Category, Order, Product, Setting};
use App\Services\RefundService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class BuyerReliabilityThemeTest extends TestCase
{
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $theme = $this->providedData()[0];
        $app->booting(function () use ($theme) {
            if (config('database.connections.pgsql.database') !== 'cardshop_testing' || (int) config('database.redis.cache.database') !== 11 || (int) config('database.redis.default.database') !== 10) {
                throw new \RuntimeException('Theme checks require isolated test storage.');
            }
            settings_memo(['site_theme' => $theme]);
        });
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    public static function themes(): array { return [['default'], ['modern'], ['minimal']]; }

    #[DataProvider('themes')]
    public function test_payment_controls_refund_balance_and_category_search_for_each_theme(string $theme): void
    {
        Http::preventStrayRequests();
        Setting::set('site_theme', $theme);
        Setting::set('refund_enabled', '1');
        $category = Category::create(['name' => 'Theme', 'slug' => 'buyer-theme', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Theme product', 'slug' => 'buyer-theme', 'price' => '100', 'is_active' => true]);
        $order = Order::create(['order_no' => 'BUYERTHEME', 'product_id' => $product->id, 'email' => 'buyer@example.test', 'query_password' => bcrypt('buyer-password'), 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100, 'status' => 'pending', 'payment_method' => 'manual', 'ip' => '192.0.2.1', 'expires_at' => now()->addHour()]);
        $this->get('/order/pay/'.$order->order_no)->assertOk()->assertViewIs('templates.'.$theme.'.order.pay')->assertSee('data-payment-recheck', false)->assertSee('data-payment-verify', false)->assertSee('data-expires="'.$order->expires_at->toIso8601String().'"', false);
        $this->get('/category/'.$category->slug.'?q=other%26category')->assertOk()->assertSee('搜索当前分类')->assertSee('在全部商品中搜索')->assertSee('q=other%26category', false);
        $order->update(['status' => 'paid']);
        Card::create(['product_id' => $product->id, 'order_id' => $order->id, 'content' => 'PUBLIC-DUMMY', 'status' => 'sold']);
        $refund = app(RefundService::class)->request($order, '20.00', 'Reason');
        app(RefundService::class)->transition($refund, 'approved', null, 'INTERNAL-SECRET', 1, '买家说明');
        $this->withBuyerSession($order)->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('max="80.00"', false)->assertSee('value="80.00"', false)->assertSee('买家说明')->assertDontSee('INTERNAL-SECRET');
        Setting::set('refund_enabled', '0');
        $this->withBuyerSession($order)->get('/order/detail/'.$order->order_no)->assertSee('已有申请仍会继续处理')->assertDontSee('/order/refund/'.$order->order_no, false)->assertSee('买家说明');
    }
}
