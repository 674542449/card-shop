<?php

namespace Tests\Feature;

use App\Models\{Card, Category, Order, PaymentReceipt, Product, Setting};
use App\Services\OrderService;
use App\Support\UsdtSignatureValue;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BepusdtNumericSignatureTest extends TestCase
{
    // Captured from Go fmt.Sprintf("%v", float64); upstream BEpusdt uses it
    // in app/utils/utils.go:EpusdtSign, including JSON-number callback fields.
    public static function goNumbers(): array
    {
        return [
            'cent' => [0.01, '0.01'], 'million' => [1000000.0, '1e+06'],
            'million with cents' => [1000000.25, '1.00000025e+06'],
            'maximum checkout' => [99999999.99, '9.999999999e+07'],
            'token atomic unit' => [0.00000001, '1e-08'], 'small scientific' => [0.00001, '1e-05'],
            'decimal boundary' => [0.0001, '0.0001'], 'long fractional' => [3.141592653589793, '3.141592653589793'],
        ];
    }

    #[DataProvider('goNumbers')]
    public function test_signature_values_match_the_gateway_float64_contract(float $number, string $expected): void
    {
        $this->assertSame($expected, UsdtSignatureValue::canonical($number));
    }

    private function order(string $amount = '1000000.25'): Order
    {
        Http::preventStrayRequests();
        Setting::set('usdt_gateway', 'bepusdt');
        Setting::set('epusdt_api_url', 'https://usdt.example.test');
        Setting::set('epusdt_api_token', 'fixture-bepusdt-key');
        $category = Category::create(['name' => 'Fixture', 'slug' => uniqid('numeric-category-'), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Fixture', 'slug' => uniqid('numeric-product-'), 'price' => $amount, 'is_active' => true]);
        Card::create(['product_id' => $product->id, 'content' => 'DUMMY-NUMERIC-CARD', 'status' => 'unsold']);
        return app(OrderService::class)->createOrder(['product_id' => $product->id, 'quantity' => 1, 'email' => 'numeric@example.test',
            'query_password' => 'fixture-password', 'payment_method' => 'usdt_trc20', 'ip' => '192.0.2.88']);
    }

    public function test_high_value_checkout_uses_gateway_signature_without_changing_the_amount(): void
    {
        $order = $this->order();
        Http::fake(['usdt.example.test/*' => function ($request) use ($order) {
            $params = $request->data();
            $this->assertSame(1000000.25, $params['amount']);
            unset($params['signature']); ksort($params);
            $parts = [];
            foreach ($params as $key => $value) $parts[] = $key.'='.($key === 'amount' ? '1.00000025e+06' : $value);
            $this->assertSame(md5(implode('&', $parts).'fixture-bepusdt-key'), $request['signature']);
            return Http::response(['status_code' => 200, 'data' => ['trade_id' => 'fixture-numeric-trade', 'order_id' => $order->order_no,
                'fiat' => 'CNY', 'amount' => '1000000.25', 'payment_url' => 'https://usdt.example.test/pay/fixture-numeric-trade']]);
        }]);
        $result = app(OrderService::class)->processPayment($order, 'usdt_trc20');
        $this->assertSame('fixture-numeric-trade', $result['trade_id']);
        $this->assertSame('1000000.25', $order->fresh()->total_amount);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_callback_go_numbers_validate_without_confusing_crypto_and_fiat_amounts(): void
    {
        $order = $this->order();
        $order->update(['gateway_trade_no' => 'fixture-numeric-trade']);
        $params = ['order_id' => $order->order_no, 'trade_id' => 'fixture-numeric-trade', 'amount' => 0.0, 'actual_amount' => 0.00000001, 'status' => 2];
        $sign = function (array $values, string $fiat): string {
            ksort($values); $parts = [];
            foreach ($values as $key => $value) $parts[] = $key.'='.($key === 'amount' ? $fiat : ($key === 'actual_amount' ? '1e-08' : $value));
            return md5(implode('&', $parts).'fixture-bepusdt-key');
        };
        $this->postJson('/payment/epusdt/notify', $params + ['signature' => $sign($params, '0')])->assertOk();
        $this->assertSame('pending', $order->fresh()->status);
        $params['amount'] = 1000000.25;
        $this->postJson('/payment/epusdt/notify', $params + ['signature' => $sign($params, '1.00000025e+06')])->assertOk()->assertSee('ok');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->deliveryCards()->count());
        $this->assertSame('0.00000001', PaymentReceipt::where('order_id', $order->id)->firstOrFail()->actual_amount);
    }

    public function test_legacy_epusdt_retains_fixed_decimal_signature_for_tiny_token_amounts(): void
    {
        $order = $this->order();
        Setting::set('usdt_gateway', 'epusdt');
        $order->update(['gateway_trade_no' => 'fixture-numeric-trade']);
        $params = ['order_id' => $order->order_no, 'trade_id' => 'fixture-numeric-trade', 'amount' => 1000000.25, 'actual_amount' => 0.00000001, 'status' => 2];
        ksort($params); $parts = [];
        foreach ($params as $key => $value) $parts[] = $key.'='.($key === 'actual_amount' ? '0.00000001' : $value);
        $params['signature'] = md5(implode('&', $parts).'fixture-bepusdt-key');
        $this->postJson('/payment/epusdt/notify', $params)->assertOk()->assertSee('ok');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->deliveryCards()->count());
        $this->assertSame('0.00000001', PaymentReceipt::where('order_id', $order->id)->firstOrFail()->actual_amount);
    }
}
