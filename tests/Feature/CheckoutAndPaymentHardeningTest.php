<?php

namespace Tests\Feature;

use App\Enums\PaymentReviewCode;
use App\Exceptions\{CheckoutException, PaymentUncertainException};
use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, ApiToken, Card, Category, Coupon, Order, OrderRefund, PaymentAttempt, PaymentReceipt, Product, Setting};
use App\Services\{
    ApiOrderIdempotency,
    CheckoutIntentService,
    CheckoutPricingService,
    EpayService,
    EpusdtService,
    NotificationService,
    OrderFulfilmentService,
    OrderService,
    PaymentInitiationService,
    PaymentReconciliationService,
    RefundService
};
use App\Support\IpAddress;
use Illuminate\Support\Facades\{DB, Hash, Http};
use Tests\TestCase;

class CheckoutAndPaymentHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('sendOrderEmail')->willReturn(true);
        $this->instance(NotificationService::class, $notifications);
        Http::preventStrayRequests();
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1001', 'payment');
        Setting::set('epay_merchant_key', 'hardening-epay-secret', 'payment');
        Setting::set('epusdt_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epusdt_api_token', 'hardening-usdt-secret', 'payment');
    }

    private function signInAdmin(): Admin
    {
        $admin = Admin::create([
            'username' => 'hardening-admin',
            'password' => Hash::make('hardening-admin-password'),
            'role' => 'owner',
            'permissions' => [],
            'is_active' => true,
        ]);
        $this->withSession([
            'admin_id' => $admin->id,
            'admin_pw' => AdminAuth::passwordFingerprint($admin->password),
        ]);

        return $admin;
    }

    private function createProduct(string $price = '10.00', int $cards = 10): Product
    {
        $category = Category::create([
            'name' => '加固测试分类',
            'slug' => uniqid('hardening-cat-'),
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => '加固测试商品',
            'slug' => uniqid('hardening-prod-'),
            'price' => $price,
            'min_quantity' => 1,
            'max_quantity' => 20,
            'is_active' => true,
        ]);
        for ($i = 0; $i < $cards; $i++) {
            Card::create([
                'product_id' => $product->id,
                'content' => 'hardening-card-' . $product->id . '-' . $i,
                'status' => 'unsold',
            ]);
        }

        return $product;
    }

    private function signMd5(array $params, string $secret): string
    {
        unset($params['sign'], $params['sign_type'], $params['signature']);
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_scalar($value) && $value !== '' && $value !== null) {
                $parts[] = $key . '=' . $value;
            }
        }

        return md5(implode('&', $parts) . $secret);
    }

    public function test_ipv6_subnet64_and_ipv4_mapped_aliases_cannot_bypass_pending_order_quota_and_expiry_is_clamped(): void
    {
        $this->assertNull(IpAddress::subnet64('198.51.100.10'));
        $this->assertNull(IpAddress::subnet64('::1'));
        $this->assertNull(IpAddress::subnet64('::ffff:198.51.100.10'));
        $this->assertSame('2001:db8:abcd:12::/64', IpAddress::subnet64('2001:0db8:ABCD:0012:dead:beef:0001:0002'));

        $product = $this->createProduct('10.00', 15);
        $orders = app(OrderService::class);

        // Rotate host addresses inside the same /64 IPv6 subnet.
        foreach ([
            '2001:db8:abcd:0012::1',
            '2001:0db8:abcd:12:1111::2',
            '2001:db8:abcd:12:ffff:ffff:ffff:ffff',
        ] as $idx => $ip) {
            $orders->createOrder([
                'product_id' => $product->id,
                'quantity' => 1,
                'email' => "subnet{$idx}@example.test",
                'query_password' => 'valid-pass-123',
                'payment_method' => 'alipay',
                'ip' => $ip,
            ]);
        }

        try {
            $orders->createOrder([
                'product_id' => $product->id,
                'quantity' => 1,
                'email' => 'subnet-fourth@example.test',
                'query_password' => 'valid-pass-123',
                'payment_method' => 'alipay',
                'ip' => '2001:db8:abcd:12::9999',
            ]);
            $this->fail('Fourth unpaid order from the same IPv6 /64 subnet should be rejected.');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('未完成的订单', $e->getMessage());
        }

        // IPv4-mapped IPv6 shares the exact normalized IPv4 bucket and stores canonical IPv4.
        for ($i = 0; $i < 3; $i++) {
            $created = $orders->createOrder([
                'product_id' => $product->id,
                'quantity' => 1,
                'email' => "v4map{$i}@example.test",
                'query_password' => 'valid-pass-123',
                'payment_method' => 'alipay',
                'ip' => $i === 0 ? '::ffff:198.51.100.88' : '198.51.100.88',
            ]);
            $this->assertSame('198.51.100.88', $created->ip);
        }

        try {
            $orders->createOrder([
                'product_id' => $product->id,
                'quantity' => 1,
                'email' => 'v4map-fourth@example.test',
                'query_password' => 'valid-pass-123',
                'payment_method' => 'alipay',
                'ip' => '::ffff:198.51.100.88',
            ]);
            $this->fail('IPv4-mapped IPv6 alias should not bypass pending order limit.');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('未完成的订单', $e->getMessage());
        }

        // Misconfigured non-positive order_expire_minutes must fall back to 30 minutes rather than immediate expiry.
        Setting::set('order_expire_minutes', '-10', 'order');
        $clampedLow = $orders->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'clamp-low@example.test',
            'query_password' => 'valid-pass-123',
            'payment_method' => 'alipay',
            'ip' => '203.0.113.11',
        ]);
        $this->assertTrue($clampedLow->expires_at->gt(now()->addMinutes(25)));

        Setting::set('order_expire_minutes', '9999999', 'order');
        $clampedHigh = $orders->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'clamp-high@example.test',
            'query_password' => 'valid-pass-123',
            'payment_method' => 'alipay',
            'ip' => '203.0.113.12',
        ]);
        $this->assertTrue($clampedHigh->expires_at->lte(now()->addMinutes(10081)));
    }

    public function test_admin_and_checkout_enforce_wholesale_invariants_coupon_floor_and_trimmed_idempotency(): void
    {
        $this->signInAdmin();
        $product = $this->createProduct('10.00', 10);

        // Tier price above base price is rejected.
        $this->postJson('/api/admin/products', [
            'name' => '倒挂批发价商品',
            'category_id' => $product->category_id,
            'price' => '10.00',
            'wholesale_prices' => [
                ['min_quantity' => 3, 'price' => '12.00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('wholesale_prices.0.price');

        // Higher-quantity tier charging more than lower-quantity tier is rejected.
        $this->postJson('/api/admin/products', [
            'name' => '阶梯递增异常商品',
            'category_id' => $product->category_id,
            'price' => '10.00',
            'wholesale_prices' => [
                ['min_quantity' => 3, 'price' => '8.00'],
                ['min_quantity' => 10, 'price' => '9.00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('wholesale_prices.1.price');

        // Lowering product base price below an existing wholesale tier without updating tiers is rejected.
        $product->wholesalePrices()->create(['min_quantity' => 3, 'price' => '8.00']);
        $this->putJson('/api/admin/products/' . $product->id, [
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => '7.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('price');

        // Legacy/DB-corrupted tier above base price is also blocked at runtime in CheckoutPricingService.
        DB::table('product_wholesale_prices')->where('product_id', $product->id)->update(['price' => '15.00']);
        try {
            app(CheckoutPricingService::class)->calculate($product->fresh(), 3, null);
            $this->fail('CheckoutPricingService must reject wholesale price above base price.');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('批发价格配置异常', $e->getMessage());
        }
        $product->wholesalePrices()->delete();

        // Coupon::calculateDiscount enforces exact bcmath and the 0.01 payable floor.
        $fixedCoupon = Coupon::create(['code' => 'FLOOR-FIXED', 'type' => 'fixed', 'value' => '100.00', 'is_active' => true]);
        $percentCoupon = Coupon::create(['code' => 'FLOOR-PCT', 'type' => 'percent', 'value' => '100.00', 'is_active' => true]);
        $this->assertSame(9.99, $fixedCoupon->calculateDiscount(10.00));
        $this->assertSame(9.99, $percentCoupon->calculateDiscount(10.00));

        // Admin CouponController trims surrounding whitespace on coupon codes.
        $createdCoupon = $this->postJson('/api/admin/coupons', [
            'code' => '  TRIMMED10  ',
            'type' => 'fixed',
            'value' => '2.00',
            'is_active' => true,
        ])->assertCreated()->json();
        $this->assertSame('TRIMMED10', $createdCoupon['code']);

        // Web CheckoutIntentService and ApiOrderIdempotency hash trimmed coupon codes identically.
        $intentService = app(CheckoutIntentService::class);
        $intentKey = $intentService->issue($product->id);
        $firstWeb = $intentService->reserve($intentKey, [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'idem@example.test',
            'query_password' => 'idem-pass-123',
            'coupon_code' => '  TRIMMED10  ',
            'payment_method' => 'alipay',
        ], fn () => app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'idem@example.test',
            'query_password' => 'idem-pass-123',
            'coupon_code' => '  TRIMMED10  ',
            'payment_method' => 'alipay',
            'ip' => '203.0.113.55',
        ]));
        $replayedWeb = $intentService->reserve($intentKey, [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'idem@example.test',
            'query_password' => 'idem-pass-123',
            'coupon_code' => 'TRIMMED10',
            'payment_method' => 'alipay',
        ], fn () => $this->fail('Should replay existing web order instead of creating another.'));
        $this->assertSame($firstWeb->id, $replayedWeb->id);

        $token = ApiToken::create(['name' => 'Idempotency token', 'token' => hash('sha256', 'hardening-token'), 'is_active' => true]);
        $apiIdem = app(ApiOrderIdempotency::class);
        $firstApi = $apiIdem->reserve($token->id, 'idem-key-1', [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'api-idem@example.test',
            'query_password' => 'idem-pass-123',
            'coupon_code' => ' TRIMMED10 ',
            'payment_method' => 'alipay',
        ], fn () => app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'api-idem@example.test',
            'query_password' => 'idem-pass-123',
            'coupon_code' => ' TRIMMED10 ',
            'payment_method' => 'alipay',
            'ip' => '203.0.113.56',
            'api_token_id' => $token->id,
        ]));
        $replayedApi = $apiIdem->reserve($token->id, 'idem-key-1', [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'api-idem@example.test',
            'query_password' => 'idem-pass-123',
            'coupon_code' => 'TRIMMED10',
            'payment_method' => 'alipay',
        ], fn () => $this->fail('Should replay existing API order instead of creating another.'));
        $this->assertSame($firstApi->order_id, $replayedApi->order_id);
    }

    public function test_payment_initiation_reloads_settings_uses_fresh_order_blocks_zero_amount_and_quarantines_corrupt_cache(): void
    {
        $product = $this->createProduct('15.00', 6);

        // Instantiate gateway services before rotating credentials to verify dynamic setting lookup.
        $epay = new EpayService();
        $usdt = new EpusdtService();
        $initiation = new PaymentInitiationService($epay, $usdt);

        Setting::set('epay_merchant_id', '2002', 'payment');
        Setting::set('epay_merchant_key', 'rotated-epay-secret', 'payment');

        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'fresh-pay@example.test',
            'query_password' => 'fresh-pass-123',
            'payment_method' => 'alipay',
            'ip' => '198.51.100.61',
        ]);

        // Mutate the in-memory copy to simulate a stale caller reference while DB holds 15.00.
        $staleCopy = clone $order;
        $staleCopy->total_amount = '0.01';

        $payResult = $initiation->initiate($staleCopy, 'alipay');
        parse_str((string) parse_url($payResult['url'], PHP_URL_QUERY), $query);
        $this->assertSame('2002', $query['pid']);
        $this->assertSame('15.00', $query['money']);

        // Corrupt cached succeeded payload must not fall through into an unleased duplicate gateway call.
        $usdtOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'corrupt-cache@example.test',
            'query_password' => 'fresh-pass-123',
            'payment_method' => 'usdt_trc20',
            'ip' => '198.51.100.62',
        ]);
        PaymentAttempt::create([
            'order_id' => $usdtOrder->id,
            'method' => 'usdt_trc20',
            'status' => 'succeeded',
            'response_payload' => null,
        ]);

        try {
            $initiation->initiate($usdtOrder, 'usdt_trc20');
            $this->fail('Corrupt succeeded PaymentAttempt should transition to uncertain and throw.');
        } catch (PaymentUncertainException) {
            $attempt = PaymentAttempt::where('order_id', $usdtOrder->id)->firstOrFail();
            $this->assertSame('uncertain', $attempt->status);
            $this->assertSame('corrupt_cached_payload', $attempt->error_code);
        }
        Http::assertNothingSent();

        // Zero-amount corrupt order must be blocked before calling any gateway (preventing BEpusdt open-amount mode).
        $zeroOrder = Order::create([
            'order_no' => generate_order_no(),
            'product_id' => $product->id,
            'email' => 'zero@example.test',
            'query_password' => Hash::make('zero-pass-123'),
            'quantity' => 1,
            'unit_price' => '0.00',
            'total_amount' => '0.00',
            'payment_method' => 'usdt_trc20',
            'status' => 'pending',
            'ip' => '198.51.100.63',
            'expires_at' => now()->addMinutes(30),
        ]);

        foreach ([
            fn () => $initiation->initiate($zeroOrder, 'usdt_trc20'),
            fn () => $epay->createPayment($zeroOrder, 'alipay'),
            fn () => $usdt->createPayment($zeroOrder, 'trc20'),
        ] as $call) {
            try {
                $call();
                $this->fail('Zero-amount order must never initiate gateway payment.');
            } catch (CheckoutException $e) {
                $this->assertStringContainsString('应付金额无效', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_uppercase_md5_signatures_work_and_bepusdt_trade_type_substitution_is_blocked(): void
    {
        Setting::set('usdt_gateway', 'bepusdt', 'payment');
        $product = $this->createProduct('20.00', 8);

        // 1. Uppercase MD5 EPay callback succeeds.
        $epayOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'upper-epay@example.test',
            'query_password' => 'upper-pass-123',
            'payment_method' => 'alipay',
            'ip' => '198.51.100.71',
        ]);
        $epayParams = [
            'pid' => '1001',
            'out_trade_no' => $epayOrder->order_no,
            'trade_no' => 'EPAY-UPPER-1',
            'type' => 'alipay',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '20.00',
        ];
        $epayParams['sign'] = strtoupper($this->signMd5($epayParams, 'hardening-epay-secret'));
        $epayParams['sign_type'] = 'MD5';
        $this->post('/payment/epay/notify', $epayParams)->assertOk()->assertSeeText('success');
        $this->assertTrue($epayOrder->fresh()->isPaid());

        // 2. Uppercase MD5 BEpusdt callback with matching trade_type succeeds.
        $usdtOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'upper-usdt@example.test',
            'query_password' => 'upper-pass-123',
            'payment_method' => 'usdt_trc20',
            'ip' => '198.51.100.72',
        ]);
        $usdtOrder->update(['gateway_trade_no' => 'USDT-UPPER-1']);
        $usdtParams = [
            'order_id' => $usdtOrder->order_no,
            'trade_id' => 'USDT-UPPER-1',
            'trade_type' => 'usdt.trc20',
            'status' => 2,
            'amount' => '20.00',
            'actual_amount' => '2.85',
        ];
        $usdtParams['signature'] = strtoupper($this->signMd5($usdtParams, 'hardening-usdt-secret'));
        $this->postJson('/payment/epusdt/notify', $usdtParams)->assertOk()->assertSeeText('ok');
        $this->assertTrue($usdtOrder->fresh()->isPaid());

        // 3. EpusdtService::createPayment rejects BEpusdt creation response with substituted trade_type (e.g. tron.trx).
        $createMismatchOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'create-mismatch@example.test',
            'query_password' => 'upper-pass-123',
            'payment_method' => 'usdt_trc20',
            'ip' => '198.51.100.73',
        ]);
        Http::fake([
            'gateway.example.test/api/v1/order/create-transaction' => Http::response([
                'status_code' => 200,
                'data' => [
                    'trade_id' => 'TRX-SUBSTITUTED-1',
                    'order_id' => $createMismatchOrder->order_no,
                    'amount' => '20.00',
                    'fiat' => 'CNY',
                    'trade_type' => 'tron.trx',
                    'status' => 1,
                    'payment_url' => 'https://gateway.example.test/pay/TRX-SUBSTITUTED-1',
                ],
            ]),
        ]);
        try {
            app(EpusdtService::class)->createPayment($createMismatchOrder, 'trc20');
            $this->fail('BEpusdt creation response with mismatched trade_type must be rejected.');
        } catch (PaymentUncertainException) {
            $this->assertNull($createMismatchOrder->fresh()->gateway_trade_no);
        }

        // 4. Signed callback carrying substituted trade_type (tron.trx on usdt_trc20) must NOT deliver cards and must flag BindingMismatch.
        $notifyMismatchOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'notify-mismatch@example.test',
            'query_password' => 'upper-pass-123',
            'payment_method' => 'usdt_trc20',
            'ip' => '198.51.100.74',
        ]);
        $notifyMismatchOrder->update(['gateway_trade_no' => 'USDT-TRX-CB-1']);
        $badTypeCallback = [
            'order_id' => $notifyMismatchOrder->order_no,
            'trade_id' => 'USDT-TRX-CB-1',
            'trade_type' => 'tron.trx',
            'status' => 2,
            'amount' => '20.00',
        ];
        $badTypeCallback['signature'] = $this->signMd5($badTypeCallback, 'hardening-usdt-secret');
        $this->postJson('/payment/epusdt/notify', $badTypeCallback)->assertOk();
        $notifyMismatchOrder->refresh();
        $this->assertSame('pending', $notifyMismatchOrder->status);
        $this->assertSame(0, $notifyMismatchOrder->cards()->where('status', 'sold')->count());
        $this->assertSame(PaymentReviewCode::BindingMismatch->value, $notifyMismatchOrder->payment_review_code);

        // 5. PaymentReconciliationService rejects mismatched trade_type on BEpusdt /api/v1/pay/info.
        $reconcileOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'reconcile-mismatch@example.test',
            'query_password' => 'upper-pass-123',
            'payment_method' => 'usdt_trc20',
            'ip' => '198.51.100.75',
        ]);
        $reconcileOrder->update(['gateway_trade_no' => 'USDT-REC-1']);
        Http::fake([
            'gateway.example.test/api/v1/pay/info' => Http::sequence()
                ->push([
                    'status_code' => '200',
                    'data' => [
                        'order_id' => $reconcileOrder->order_no,
                        'trade_id' => 'USDT-REC-1',
                        'fiat' => 'CNY',
                        'trade_type' => 'tron.trx',
                        'status' => 2,
                        'money' => '20.00',
                    ],
                ])
                ->push([
                    'status_code' => '200',
                    'data' => [
                        'order_id' => $reconcileOrder->order_no,
                        'trade_id' => 'USDT-REC-1',
                        'fiat' => 'CNY',
                        'trade_type' => 'usdt.trc20',
                        'status' => 2,
                        'money' => '20.00',
                        'block_transaction_id' => '0xabc123def456',
                    ],
                ]),
        ]);

        try {
            app(PaymentReconciliationService::class)->sync($reconcileOrder);
            $this->fail('Reconciliation must reject BEpusdt trade_type mismatch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('不匹配', $e->getMessage());
            $this->assertSame('pending', $reconcileOrder->fresh()->status);
        }

        $synced = app(PaymentReconciliationService::class)->sync($reconcileOrder->fresh());
        $this->assertTrue($synced['delivered']);
        $this->assertTrue($reconcileOrder->fresh()->isPaid());
        $this->assertSame('0xabc123def456', PaymentReceipt::where('trade_no', 'USDT-REC-1')->value('transaction_hash'));
    }

    public function test_binding_mismatched_callback_preserves_existing_valid_payment_no_and_split_legacy_refunds_resolve_review(): void
    {
        Setting::set('refund_enabled', '1', 'payment');
        $admin = $this->signInAdmin();
        $product = $this->createProduct('10.00', 2);

        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'preserve@example.test',
            'query_password' => 'preserve-pass-123',
            'payment_method' => 'alipay',
            'ip' => '198.51.100.81',
        ]);

        // Expire the order and consume remaining stock so a valid late callback lands in InsufficientStock review.
        $order->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireOrder($order);
        Card::where('product_id', $product->id)->update(['status' => 'sold']);

        $fulfilment = app(OrderFulfilmentService::class);
        $first = $fulfilment->fulfilFromGateway($order->order_no, 'TRADE-VALID-PRIMARY', '10.00', 'epay', ['epay_type' => 'alipay']);
        $this->assertSame('refused', $first->status);
        $this->assertSame('TRADE-VALID-PRIMARY', $order->fresh()->payment_no);

        // Now a binding-mismatched callback arrives (wxpay on an alipay order): primary payment_no must NOT be clobbered!
        $second = $fulfilment->fulfilFromGateway($order->order_no, 'TRADE-WRONG-CHANNEL', '10.00', 'epay', ['epay_type' => 'wxpay']);
        $this->assertSame('refused', $second->status);
        $this->assertSame('TRADE-VALID-PRIMARY', $order->fresh()->payment_no);
        $this->assertSame(
            PaymentReviewCode::BindingMismatch->value,
            PaymentReceipt::where('trade_no', 'TRADE-WRONG-CHANNEL')->value('review_code')
        );

        // Split legacy (payment_receipt_id = null) + receipt-linked completed refunds must resolve the primary receipt review.
        $splitOrder = app(OrderService::class)->createOrder([
            'product_id' => $this->createProduct('10.00', 2)->id,
            'quantity' => 1,
            'email' => 'split-refund@example.test',
            'query_password' => 'preserve-pass-123',
            'payment_method' => 'alipay',
            'ip' => '198.51.100.82',
        ]);
        $splitOrder->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireOrder($splitOrder);
        Card::where('product_id', $splitOrder->product_id)->update(['status' => 'sold']);

        $fulfilment->fulfilFromGateway($splitOrder->order_no, 'SPLIT-RECEIPT-1', '10.00', 'epay', ['epay_type' => 'alipay']);
        $primaryReceipt = PaymentReceipt::where('trade_no', 'SPLIT-RECEIPT-1')->firstOrFail();
        $this->assertNull($primaryReceipt->review_resolved_at);

        // Simulate an earlier completed partial refund recorded before receipt-level linkage (payment_receipt_id = null).
        OrderRefund::create([
            'order_id' => $splitOrder->id,
            'payment_receipt_id' => null,
            'amount' => '4.00',
            'reason' => '早期部分退款',
            'source' => 'admin',
            'status' => 'completed',
            'reference' => 'LEGACY-REF-1',
            'admin_id' => $admin->id,
            'completed_at' => now(),
        ]);

        $refunds = app(RefundService::class);
        $this->assertSame('6.00', $refunds->balance($splitOrder->fresh(), $primaryReceipt)['available']);

        $remainder = $refunds->request($splitOrder->fresh(), '6.00', '退还剩余款项', $primaryReceipt->id, 'admin');
        $refunds->transition($remainder, 'approved', null, '同意退款', $admin->id);
        $refunds->transition($remainder->fresh(), 'completed', 'FINAL-REF-2', '已完成打款', $admin->id);

        $this->assertNotNull($primaryReceipt->fresh()->review_resolved_at);
        $splitFresh = $splitOrder->fresh();
        $this->assertFalse($splitFresh->requiresPaymentReview());
        $this->assertSame('closed', $splitFresh->status);
    }

    public function test_second_pass_multi_receipt_manual_delivery_expired_lease_recovery_and_epay_channel_review(): void
    {
        $product = $this->createProduct('10.00', 2);
        $fulfilment = app(OrderFulfilmentService::class);

        // 1. Buyer pays TWICE while an expired order is out of stock; manual fulfilment must only resolve the funding receipt!
        $doublePaidOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'double-manual@example.test',
            'query_password' => 'double-pass-123',
            'payment_method' => 'alipay',
            'ip' => '198.51.100.91',
        ]);
        $doublePaidOrder->update(['expires_at' => now()->subMinute()]);
        app(OrderService::class)->expireOrder($doublePaidOrder);
        Card::where('product_id', $product->id)->update(['status' => 'sold']);

        $fulfilment->fulfilFromGateway($doublePaidOrder->order_no, 'TRADE-STOCK-1', '10.00', 'epay', ['epay_type' => 'alipay']);
        $fulfilment->fulfilFromGateway($doublePaidOrder->order_no, 'TRADE-STOCK-2', '10.00', 'epay', ['epay_type' => 'alipay']);

        // Replenish 1 card and run manual fulfilment.
        Card::create(['product_id' => $product->id, 'content' => 'replenished-card-1', 'status' => 'unsold']);
        $this->assertTrue($fulfilment->fulfilManually($doublePaidOrder->fresh())->wasFulfilled());

        $r1 = PaymentReceipt::where('trade_no', 'TRADE-STOCK-1')->firstOrFail();
        $r2 = PaymentReceipt::where('trade_no', 'TRADE-STOCK-2')->firstOrFail();
        // Funding receipt (#2 was latest valid payment_no, so #2 funded and #1 becomes DuplicatePayment, or vice versa: exactly ONE resolved, ONE unresolved DuplicatePayment).
        $resolvedCount = collect([$r1, $r2])->filter(fn ($r) => $r->review_resolved_at !== null)->count();
        $unresolved = collect([$r1, $r2])->first(fn ($r) => $r->review_resolved_at === null);
        $this->assertSame(1, $resolvedCount);
        $this->assertNotNull($unresolved);
        $this->assertSame(PaymentReviewCode::DuplicatePayment->value, $unresolved->review_code);
        $this->assertTrue($doublePaidOrder->fresh()->requiresPaymentReview());
        $this->assertSame(PaymentReviewCode::DuplicatePayment->value, $doublePaidOrder->fresh()->payment_review_code);

        // 2. Non-standard EPay channel (e.g. qqpay) on an unpaid order is recorded for review without delivering cards;
        //    and cross-channel duplicate payment on an ALREADY PAID order is classified as DuplicatePayment.
        Card::create(['product_id' => $product->id, 'content' => 'replenished-card-2', 'status' => 'unsold']);
        $qqpayOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'qqpay@example.test',
            'query_password' => 'double-pass-123',
            'payment_method' => 'alipay',
            'ip' => '198.51.100.92',
        ]);
        $qqParams = [
            'pid' => '1001',
            'out_trade_no' => $qqpayOrder->order_no,
            'trade_no' => 'EPAY-QQPAY-1',
            'type' => 'qqpay',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '10.00',
        ];
        $qqParams['sign'] = $this->signMd5($qqParams, 'hardening-epay-secret');
        $this->post('/payment/epay/notify', $qqParams)->assertOk()->assertSeeText('success');
        $this->assertSame('pending', $qqpayOrder->fresh()->status);
        $this->assertSame(0, $qqpayOrder->cards()->where('status', 'sold')->count());
        $this->assertSame(PaymentReviewCode::BindingMismatch->value, $qqpayOrder->fresh()->payment_review_code);

        // Cross-channel second payment on already-paid doublePaidOrder must record DuplicatePayment.
        $extraCrossParams = [
            'pid' => '1001',
            'out_trade_no' => $doublePaidOrder->order_no,
            'trade_no' => 'EPAY-PAID-EXTRA-WX',
            'type' => 'wxpay',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '10.00',
        ];
        $extraCrossParams['sign'] = $this->signMd5($extraCrossParams, 'hardening-epay-secret');
        $this->post('/payment/epay/notify', $extraCrossParams)->assertOk()->assertSeeText('success');
        $this->assertSame(
            PaymentReviewCode::DuplicatePayment->value,
            PaymentReceipt::where('trade_no', 'EPAY-PAID-EXTRA-WX')->value('review_code')
        );

        // 3. Expired processing lease is promoted to uncertain on /order/pay/{order_no} and in enqueueDue() even when reconciliation is disabled.
        Card::create(['product_id' => $product->id, 'content' => 'replenished-card-3', 'status' => 'unsold']);
        $stuckOrder = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'stuck-lease@example.test',
            'query_password' => 'double-pass-123',
            'payment_method' => 'usdt_trc20',
            'ip' => '198.51.100.93',
        ]);
        $attempt = PaymentAttempt::create([
            'order_id' => $stuckOrder->id,
            'method' => 'usdt_trc20',
            'status' => 'processing',
            'lease_token' => '11111111-2222-4333-8444-666666666666',
            'lease_expires_at' => now()->subSeconds(5),
        ]);

        $this->getJson('/order/pay/' . $stuckOrder->order_no)
            ->assertOk()
            ->assertJsonPath('payment_initialization', 'uncertain')
            ->assertJsonPath('payment_review', true);
        $this->assertSame('uncertain', $attempt->fresh()->status);
        $this->assertSame('worker_interrupted', $attempt->fresh()->error_code);

        Setting::set('payment_reconciliation_enabled', '0', 'payment');
        $attempt->update([
            'status' => 'processing',
            'error_code' => null,
            'lease_token' => '11111111-2222-4333-8444-777777777777',
            'lease_expires_at' => now()->subSeconds(5),
        ]);
        $this->assertSame(0, app(\App\Services\PaymentReconciliationQueue::class)->enqueueDue());
        $this->assertSame('uncertain', $attempt->fresh()->status);
    }
}
