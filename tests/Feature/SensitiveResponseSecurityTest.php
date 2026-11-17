<?php

namespace Tests\Feature;

use App\Models\{Card, Category, Order, Product};
use Illuminate\Support\Facades\{Hash, Http, Route, Storage};
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SensitiveResponseSecurityTest extends TestCase
{
    private function assertPrivate(TestResponse $response): void
    {
        foreach (['no-store', 'private', 'must-revalidate'] as $directive) {
            $this->assertStringContainsString($directive, $response->headers->get('Cache-Control'));
        }
        $response->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public static function dynamicEndpoints(): array
    {
        return [
            'storefront with CSRF session' => ['/', 200],
            'admin login shell' => ['/admin/login', 200],
            'unauthenticated admin API' => ['/api/admin/orders', 401],
            'unauthenticated buyer API' => ['/api/v1/products', 401],
            'unknown order' => ['/order/detail/UNKNOWN-ORDER', 404],
        ];
    }

    #[DataProvider('dynamicEndpoints')]
    public function test_dynamic_pages_and_denials_cannot_be_cached_or_framed(string $path, int $status): void
    {
        Http::preventStrayRequests();
        $response = str_starts_with($path, '/api/') ? $this->getJson($path) : $this->get($path);
        $this->assertPrivate($response->assertStatus($status));
    }

    public function test_card_html_and_download_are_private_and_do_not_expose_card_markup_as_html(): void
    {
        $category = Category::create(['name' => 'Privacy', 'slug' => 'privacy', 'is_active' => true]);
        $product = Product::create(['name' => 'Privacy', 'slug' => 'privacy', 'category_id' => $category->id,
            'price' => '10.00', 'is_active' => true]);
        $order = Order::create(['order_no' => generate_order_no(), 'product_id' => $product->id,
            'product_name' => $product->name, 'email' => 'privacy@example.test',
            'query_password' => Hash::make('public-privacy-password'), 'quantity' => 1,
            'unit_price' => '10.00', 'total_amount' => '10.00', 'discount_amount' => '0.00',
            'payment_method' => 'alipay', 'ip' => '192.0.2.90', 'status' => 'paid', 'paid_at' => now(), 'expires_at' => now()->addHour()]);
        $dummySecret = 'PUBLIC-DUMMY-</textarea><script>window.cardTheft=true</script>';
        Card::create(['product_id' => $product->id, 'order_id' => $order->id, 'content' => $dummySecret,
            'status' => 'sold', 'sold_at' => now()]);
        $this->withBuyerSession($order);
        $html = $this->get('/order/detail/'.$order->order_no)->assertOk();
        $html->assertDontSee('<script>window.cardTheft=true</script>', false)->assertSee(e($dummySecret), false);
        $this->assertPrivate($html);
        $file = $this->get('/order/cards/'.$order->order_no.'/download')->assertOk()->assertSee($dummySecret, false);
        $file->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertPrivate($file);
    }

    public function test_private_storage_has_no_generic_http_read_or_write_route(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('shop-backups/public-dummy.txt', 'PUBLIC-DUMMY-PRIVATE-BACKUP');
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->get('/storage/shop-backups/public-dummy.txt')->assertNotFound()->assertDontSee('PUBLIC-DUMMY-PRIVATE-BACKUP');
        $this->put('/storage/shop-backups/public-dummy.txt', ['content' => 'forged'])->assertNotFound();
        $this->assertSame('PUBLIC-DUMMY-PRIVATE-BACKUP', Storage::disk('local')->get('shop-backups/public-dummy.txt'));
    }
}
