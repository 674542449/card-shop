<?php

namespace Tests\Feature;

use App\Models\{ApiToken, Card, Category, Order, Product, Setting};
use App\Services\OrderService;
use Illuminate\Support\Facades\{Http, Route, URL};
use Tests\TestCase;

class RequestOriginSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::set('epay_api_url', 'https://gateway.example.test');
        Setting::set('epay_merchant_id', 'public-test-merchant');
        Setting::set('epay_merchant_key', 'public-test-key');
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Origin test', 'slug' => 'origin-test', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Origin product', 'slug' => 'origin-product',
            'price' => '10.00', 'is_active' => true, 'min_quantity' => 1, 'max_quantity' => 5]);
        Card::create(['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-origin', 'status' => 'unsold']);
        return $product;
    }

    private function data(Product $product): array
    {
        return ['product_id' => $product->id, 'quantity' => 1, 'email' => 'origin@example.test',
            'query_password' => 'public-origin-password', 'payment_method' => 'alipay', 'ip' => '192.0.2.80'];
    }

    private function assertTrustedCallbacks(string $paymentUrl): void
    {
        parse_str(parse_url($paymentUrl, PHP_URL_QUERY), $parameters);
        $base = rtrim(config('app.url'), '/');
        $this->assertSame($base.'/payment/epay/notify', $parameters['notify_url']);
        $this->assertSame($base.'/payment/epay/return', $parameters['return_url']);
        $this->assertStringNotContainsString('attacker.example.test', $paymentUrl);
    }

    public function test_forged_host_cannot_change_browser_payment_callbacks(): void
    {
        $product = $this->product();
        $this->post('http://attacker.example.test/order/create', $this->data($product))->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $response = $this->get('http://attacker.example.test/order/pay/'.$order->order_no)->assertOk();
        $this->assertTrustedCallbacks($response->viewData('paymentUrl'));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }

    public function test_forged_host_cannot_change_api_payment_callbacks(): void
    {
        $product = $this->product();
        ApiToken::create(['name' => 'Origin token', 'token' => hash('sha256', 'public-origin-token'), 'is_active' => true]);
        $response = $this->withToken('public-origin-token')->postJson('http://attacker.example.test/api/v1/orders', $this->data($product))->assertCreated();
        $this->assertTrustedCallbacks($response->json('data.payment_url'));
        $this->assertSame('pending', Order::firstOrFail()->status);
    }

    public function test_forged_proxy_headers_cannot_change_origin_or_client_address(): void
    {
        Route::get('/security-origin-ip', fn (\Illuminate\Http\Request $request) => response()->json(['ip' => $request->ip()]));
        $this->withHeaders(['Host' => 'attacker.example.test', 'X-Forwarded-Host' => 'attacker.example.test',
            'X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => '203.0.113.99', 'CF-Connecting-IP' => '203.0.113.99']);
        $this->get('http://attacker.example.test/sitemap.xml')->assertOk()->assertDontSee('attacker.example.test')->assertSee(rtrim(config('app.url'), '/'));
        $this->assertSame(rtrim(config('app.url'), '/'), URL::to('/'));
        $this->getJson('http://attacker.example.test/security-origin-ip')->assertOk()->assertJsonPath('ip', '127.0.0.1');
    }

    public function test_admin_module_assets_remain_same_origin_on_a_local_alias(): void
    {
        // APP_URL fixes signed callbacks, but a module loaded from that absolute
        // origin would fail CORS when the same local shop is opened as localhost.
        $response = $this->get('http://localhost:8000/admin/login')->assertOk();
        $response->assertSee('type="module" src="/admin-assets/', false)
            ->assertSee('rel="stylesheet" href="/admin-assets/', false)
            ->assertDontSee(rtrim(config('app.url'), '/').'/admin-assets/', false);
    }
}
