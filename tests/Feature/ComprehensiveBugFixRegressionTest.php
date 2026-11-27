<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, ApiOrderRequest, ApiToken, Article, ArticleCategory, Card, Category, Coupon, Order, PaymentAttempt, PaymentReceipt, Product, ProductWholesalePrice, Setting};
use App\Services\{CheckoutPricingService, CloudBackupStorageService, EpayService, HeartbeatService, OrderFulfilmentService, OrderLookupService, OrderService, PaymentReconciliationQueue, PaymentReconciliationService, RefundService, ShopBackupService};
use App\Support\{SafeUrl, SearchBotVerifier, UsdtSignatureValue};
use Illuminate\Support\Facades\{Hash, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class ComprehensiveBugFixRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(): Admin
    {
        $admin = Admin::create([
            'username' => uniqid('audit-admin-'),
            'password' => Hash::make('audit-admin-password-123'),
            'is_active' => true,
        ]);
        $this->withSession([
            'admin_id' => $admin->id,
            'admin_pw' => AdminAuth::passwordFingerprint($admin->password),
        ]);

        return $admin;
    }

    private function createProductWithTiers(int $stock = 25, string $name = 'Multi Tier Product'): Product
    {
        $category = Category::create(['name' => '阶梯分类', 'slug' => uniqid('tier-cat-'), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => uniqid('tier-prod-'),
            'price' => '100.00',
            'min_quantity' => 1,
            'max_quantity' => 50,
            'is_active' => true,
        ]);
        ProductWholesalePrice::create(['product_id' => $product->id, 'min_quantity' => 5, 'price' => '90.00']);
        ProductWholesalePrice::create(['product_id' => $product->id, 'min_quantity' => 10, 'price' => '80.00']);
        ProductWholesalePrice::create(['product_id' => $product->id, 'min_quantity' => 20, 'price' => '70.00']);

        for ($i = 0; $i < $stock; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'TIER-CARD-' . $product->id . '-' . $i, 'status' => 'unsold']);
        }

        return $product;
    }

    public function test_p0_multi_tier_wholesale_pricing_works_for_second_and_third_tiers(): void
    {
        $product = $this->createProductWithTiers(25);

        $this->assertSame('100.00', $product->getEffectivePrice(4));
        $this->assertSame('90.00', $product->getEffectivePrice(5));
        $this->assertSame('80.00', $product->getEffectivePrice(10));
        $this->assertSame('70.00', $product->getEffectivePrice(20));

        $pricing = app(CheckoutPricingService::class);
        $q10 = $pricing->calculate($product, 10, null, false);
        $this->assertSame('80.00', $q10['unit_price']);
        $this->assertSame('800.00', $q10['total_amount']);

        $q20 = $pricing->calculate($product, 20, null, false);
        $this->assertSame('70.00', $q20['unit_price']);
        $this->assertSame('1400.00', $q20['total_amount']);

        // Quote endpoint must succeed for tier 2+
        $this->postJson('/order/quote', [
            'product_id' => $product->id,
            'quantity' => 10,
        ])->assertOk()
            ->assertJsonPath('data.unit_price', '80.00')
            ->assertJsonPath('data.total_amount', '800.00');

        // Storefront OrderService::createOrder must succeed for tier 2
        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'email' => 'tier-buyer@example.test',
            'query_password' => 'tier-pass-123',
            'quantity' => 10,
            'payment_method' => 'alipay',
            'ip' => '198.51.100.10',
        ]);
        $this->assertSame('80.00', $order->unit_price);
        $this->assertSame('800.00', $order->total_amount);
    }

    public function test_p1_epay_trims_truncated_name_and_notify_preserves_signed_whitespace(): void
    {
        Setting::set('epay_api_url', 'https://epay.example.test', 'payment');
        Setting::set('epay_merchant_id', '10086', 'payment');
        Setting::set('epay_merchant_key', 'epay-secret-key-999', 'payment');

        // 49 ASCII chars + space at index 50 + more text => mb_substr(..., 0, 50) ends with a space
        $nameWithSpaceAt50 = str_repeat('A', 49) . ' Tail Edition';
        $product = $this->createProductWithTiers(5, $nameWithSpaceAt50);
        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'email' => 'epay-buyer@example.test',
            'query_password' => 'epay-pass-123',
            'quantity' => 1,
            'payment_method' => 'alipay',
            'ip' => '198.51.100.11',
        ]);

        // Outbound payment URL must trim trailing whitespace after 50-char truncation
        $url = app(EpayService::class)->createPayment($order, 'alipay');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $outParams);
        $this->assertSame(str_repeat('A', 49), $outParams['name']);

        // Inbound callback with trailing space in `name` must NOT be stripped by TrimStrings before MD5 verification
        $notifyParams = [
            'pid' => '10086',
            'trade_no' => 'EPAY-SPACE-001',
            'out_trade_no' => $order->order_no,
            'type' => 'alipay',
            'name' => str_repeat('A', 49) . ' ',
            'money' => '100.00',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        ksort($notifyParams);
        $signParts = [];
        foreach ($notifyParams as $k => $v) {
            $signParts[] = "{$k}={$v}";
        }
        $notifyParams['sign'] = md5(implode('&', $signParts) . 'epay-secret-key-999');
        $notifyParams['sign_type'] = 'MD5';

        $this->get('/payment/epay/notify?' . http_build_query($notifyParams))
            ->assertOk()
            ->assertSee('success');
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_p2_bepusdt_exact_million_integer_callback_passes_signature_verification(): void
    {
        Setting::set('usdt_gateway', 'bepusdt', 'payment');
        Setting::set('epusdt_api_url', 'https://bepusdt.example.test', 'payment');
        Setting::set('epusdt_api_token', 'bepusdt-secret-token-777', 'payment');

        $category = Category::create(['name' => '大额分类', 'slug' => uniqid('big-cat-'), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => '大额商品',
            'slug' => uniqid('big-prod-'),
            'price' => '100000.00',
            'min_quantity' => 1,
            'max_quantity' => 20,
            'is_active' => true,
        ]);
        for ($i = 0; $i < 10; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'BIG-CARD-' . $i, 'status' => 'unsold']);
        }

        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'email' => 'whale@example.test',
            'query_password' => 'whale-pass-123',
            'quantity' => 10,
            'payment_method' => 'usdt_trc20',
            'ip' => '198.51.100.12',
        ]);
        $this->assertSame('1000000.00', $order->total_amount);

        // Simulate BEpusdt Go encoding/json sending `amount: 1000000` (decoded as PHP int) signed with float64 `"1e+06"`
        $payload = [
            'trade_id' => 'BEP-MILLION-001',
            'order_id' => $order->order_no,
            'amount' => 1000000,
            'actual_amount' => 138888.88,
            'token' => 'TReceiverWalletAddress',
            'block_transaction_id' => '0xabc123',
            'status' => 2,
            'trade_type' => 'usdt.trc20',
            'fiat' => 'CNY',
        ];
        $signMap = $payload;
        ksort($signMap);
        $parts = [];
        foreach ($signMap as $k => $v) {
            $val = in_array($k, ['amount', 'actual_amount'], true) ? UsdtSignatureValue::canonical((float) $v) : UsdtSignatureValue::canonical($v);
            $parts[] = "{$k}={$val}";
        }
        $payload['signature'] = md5(implode('&', $parts) . 'bepusdt-secret-token-777');

        $this->postJson('/payment/epusdt/notify', $payload)
            ->assertOk()
            ->assertSee('ok');
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_p1_and_p2_api_idempotent_retry_handles_expired_closed_paid_and_archived_orders_safely(): void
    {
        Setting::set('epay_api_url', 'https://epay.example.test', 'payment');
        Setting::set('epay_merchant_id', '10086', 'payment');
        Setting::set('epay_merchant_key', 'epay-secret-key-999', 'payment');

        $plainToken = 'cardshop_live_' . Str::random(32);
        $token = ApiToken::create([
            'name' => 'Idem Client',
            'token' => hash('sha256', $plainToken),
            'scopes' => ['orders:create', 'orders:read'],
            'is_active' => true,
        ]);
        $product = $this->createProductWithTiers(5);
        $body = [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'api-idem@example.test',
            'query_password' => 'api-pass-123',
            'payment_method' => 'alipay',
        ];

        // 1. Expired order without cached response must return 409 (not 201 with payment_url: null)
        $expiredOrder = app(OrderService::class)->createOrder($body + ['ip' => '198.51.100.20', 'api_token_id' => $token->id]);
        $expiredOrder->update(['expires_at' => now()->subMinute()]);
        $this->assertTrue(app(OrderService::class)->expireOrder($expiredOrder));
        $this->assertSame('expired', $expiredOrder->fresh()->status);
        $idemKey1 = 'idem-expired-' . Str::uuid();
        $canonical = [
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'api-idem@example.test',
            'password_proof' => Order::passwordKey($body['email'], $body['query_password']),
            'coupon_code' => '',
            'payment_method' => 'alipay',
        ];
        ApiOrderRequest::create([
            'api_token_id' => $token->id,
            'key_hash' => hash('sha256', $idemKey1),
            'request_hash' => hash_hmac('sha256', json_encode($canonical, JSON_THROW_ON_ERROR), (string) config('app.key')),
            'order_id' => $expiredOrder->id,
        ]);

        $this->withToken($plainToken)
            ->withHeader('Idempotency-Key', $idemKey1)
            ->postJson('/api/v1/orders', $body)
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'expired');

        // 2. Archived order (order_id set back to null via nullOnDelete) must return 409 conflict, not create duplicate order
        $idemKey2 = 'idem-archived-' . Str::uuid();
        ApiOrderRequest::create([
            'api_token_id' => $token->id,
            'key_hash' => hash('sha256', $idemKey2),
            'request_hash' => hash_hmac('sha256', json_encode($canonical, JSON_THROW_ON_ERROR), (string) config('app.key')),
            'order_id' => null,
            'response_payload' => null,
        ]);
        $beforeOrders = Order::count();
        $this->withToken($plainToken)
            ->withHeader('Idempotency-Key', $idemKey2)
            ->postJson('/api/v1/orders', $body)
            ->assertStatus(409);
        $this->assertSame($beforeOrders, Order::count());
    }

    public function test_p1_and_p2_security_guards_trap_composer_reject_gcp_and_trailing_dot_ssrf(): void
    {
        // TrapScanners catches /composer.json and /composer.lock
        Setting::set('honeypot_enabled', '1', 'security');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44'])
            ->get('/composer.json')
            ->assertNotFound();

        // SearchBotVerifier rejects *.bc.googleusercontent.com even with Googlebot UA
        $verifier = new SearchBotVerifier(
            reverseResolver: static fn (): string => '10.20.30.40.bc.googleusercontent.com',
            forwardResolver: static fn (): array => ['203.0.113.40'],
        );
        $this->assertFalse($verifier->isVerifiedCrawler('203.0.113.40', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));

        $legitVerifier = new SearchBotVerifier(
            reverseResolver: static fn (): string => 'crawl-66-249-66-1.googlebot.com',
            forwardResolver: static fn (): array => ['66.249.66.1'],
        );
        $this->assertTrue($legitVerifier->isVerifiedCrawler('66.249.66.1', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));

        // SafeUrl rejects trailing-dot hostnames and octal / short dotted IPv4 encodings
        foreach ([
            'https://rebind.example.com./',
            'https://169.254.169.254./',
            'https://0177.0.0.1/',
            'https://127.1/',
            'https://0x7f.0.0.1/',
        ] as $unsafe) {
            $this->assertNull(SafeUrl::outboundGateway($unsafe, false), "Expected SafeUrl to reject {$unsafe}");
        }
    }

    public function test_p1_reconciliation_ack_and_cloud_secret_blank_preservation_and_replace_cards_smtp_msg(): void
    {
        $this->admin();

        // P1-8: Acknowledging reconciliation health with null through_id/observed_at succeeds (not 422)
        $heartbeats = app(HeartbeatService::class);
        $heartbeats->recordReconciliation(false);
        $heartbeats->recordReconciliation(false);
        $heartbeats->recordReconciliation(false);

        $this->postJson('/api/' . admin_path() . '/maintenance/health/acknowledge', [
            'type' => 'reconciliation',
            'through_id' => null,
            'observed_at' => null,
            'failure_version' => 3,
        ])->assertOk();

        // P1-9: Leaving backup_r2_secret_key empty preserves stored encrypted secret
        Setting::set('backup_r2_enabled', '1', 'backup');
        Setting::set('backup_r2_endpoint', 'https://acct.r2.cloudflarestorage.com', 'backup');
        Setting::set('backup_r2_region', 'auto', 'backup');
        Setting::set('backup_r2_bucket', 'shop-backups', 'backup');
        Setting::set('backup_r2_access_key', 'R2ACCESSKEY', 'backup');
        Setting::set('backup_r2_secret_key', 'R2-SUPER-SECRET-VALUE', 'backup');

        $this->postJson('/api/' . admin_path() . '/settings', [
            'backup_r2_enabled' => true,
            'backup_r2_secret_key' => '',
        ])->assertOk();
        settings_forget();
        $this->assertSame('R2-SUPER-SECRET-VALUE', setting('backup_r2_secret_key'));

        // P2-16: replaceCards reports SMTP disabled accurately when mail_enabled = 0
        Setting::set('mail_enabled', '0', 'mail');
        $product = $this->createProductWithTiers(5);
        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'email' => 'replace-buyer@example.test',
            'query_password' => 'replace-pass-123',
            'quantity' => 1,
            'payment_method' => 'alipay',
            'ip' => '198.51.100.33',
        ]);
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $deliveredCardId = $order->fresh()->deliveryCards()->firstOrFail()->id;

        $this->postJson('/api/' . admin_path() . '/orders/' . $order->id . '/replacements', [
            'card_ids' => [$deliveredCardId],
            'reason' => '卡密失效测试',
            'request_token' => (string) Str::uuid(),
        ])->assertStatus(201)
            ->assertJsonPath('message', '已完成换卡，旧卡保留售后历史且不会再次售出（SMTP 邮件投递已关闭，未发送邮件）。');
    }

    public function test_p2_reconciliation_uncertain_cap_and_unpaid_resolve_and_batch_destroy_limits(): void
    {
        $this->admin();

        // P2-2: Uncertain orders older than 14 days stop re-enqueuing; expired > 2h unpaid resolves attempt to failed
        Setting::set('payment_reconciliation_enabled', '1', 'payment');
        Setting::set('epay_api_url', 'https://epay.example.test', 'payment');
        Setting::set('epay_merchant_id', '10086', 'payment');
        Setting::set('epay_merchant_key', 'epay-secret', 'payment');

        $product = $this->createProductWithTiers(6);
        $ancientOrder = Order::create([
            'order_no' => 'ANC' . now()->format('YmdHis') . '001',
            'product_id' => $product->id,
            'email' => 'ancient@example.test',
            'query_password' => Hash::make('pass1234'),
            'query_password_key' => Order::passwordKey('ancient@example.test', 'pass1234'),
            'quantity' => 1,
            'unit_price' => '100.00',
            'total_amount' => '100.00',
            'discount_amount' => '0.00',
            'payment_method' => 'alipay',
            'status' => 'expired',
            'ip' => '198.51.100.51',
            'expires_at' => now()->subDays(15),
        ]);
        Order::whereKey($ancientOrder->id)->update(['created_at' => now()->subDays(16)]);
        PaymentAttempt::create([
            'order_id' => $ancientOrder->id,
            'method' => 'alipay',
            'status' => 'uncertain',
            'error_code' => 'gateway_uncertain',
        ]);
        $this->assertSame(0, app(PaymentReconciliationQueue::class)->enqueueDue());

        $recentExpired = Order::create([
            'order_no' => 'REC' . now()->format('YmdHis') . '002',
            'product_id' => $product->id,
            'email' => 'recent@example.test',
            'query_password' => Hash::make('pass1234'),
            'query_password_key' => Order::passwordKey('recent@example.test', 'pass1234'),
            'quantity' => 1,
            'unit_price' => '100.00',
            'total_amount' => '100.00',
            'discount_amount' => '0.00',
            'payment_method' => 'alipay',
            'status' => 'expired',
            'ip' => '198.51.100.52',
            'expires_at' => now()->subHours(3),
        ]);
        $recentAttempt = PaymentAttempt::create([
            'order_id' => $recentExpired->id,
            'method' => 'alipay',
            'status' => 'uncertain',
            'error_code' => 'gateway_uncertain',
        ]);
        Http::fake([
            'epay.example.test/*' => Http::response([
                'code' => 1,
                'pid' => '10086',
                'out_trade_no' => $recentExpired->order_no,
                'type' => 'alipay',
                'status' => 0,
                'money' => '100.00',
            ]),
        ]);
        app(PaymentReconciliationService::class)->sync($recentExpired);
        $this->assertSame('failed', $recentAttempt->fresh()->status);
        $this->assertSame('reconciled_unpaid', $recentAttempt->fresh()->error_code);

        // P2-3: Resolving unpaid order receipt clears requiresPaymentReview()
        $unpaidReviewOrder = Order::create([
            'order_no' => 'REV' . now()->format('YmdHis') . '003',
            'product_id' => $product->id,
            'email' => 'rev@example.test',
            'query_password' => Hash::make('pass1234'),
            'query_password_key' => Order::passwordKey('rev@example.test', 'pass1234'),
            'quantity' => 1,
            'unit_price' => '100.00',
            'total_amount' => '100.00',
            'discount_amount' => '0.00',
            'payment_method' => 'alipay',
            'payment_no' => 'WRONG-CHANNEL-TRADE',
            'status' => 'expired',
            'ip' => '198.51.100.53',
            'expires_at' => now()->subHour(),
            'payment_review_reason' => '通道不符',
            'payment_review_code' => 'binding_mismatch',
        ]);
        $receipt = PaymentReceipt::create([
            'order_id' => $unpaidReviewOrder->id,
            'channel' => 'epay',
            'trade_no' => 'WRONG-CHANNEL-TRADE',
            'amount' => '100.00',
            'received_at' => now(),
            'review_code' => 'binding_mismatch',
            'review_reason' => '通道不符',
        ]);
        $this->assertTrue($unpaidReviewOrder->fresh()->requiresPaymentReview());
        $this->postJson('/api/' . admin_path() . '/orders/' . $unpaidReviewOrder->id . '/receipts/' . $receipt->id . '/resolve', [
            'note' => '已线下原路退回核销',
        ])->assertOk();
        $this->assertFalse($unpaidReviewOrder->fresh()->requiresPaymentReview());

        // P2-4: batchDestroy enforces max:500
        $this->deleteJson('/api/' . admin_path() . '/cards/batch-destroy', [
            'ids' => range(1, 501),
        ])->assertStatus(422);
    }

    public function test_p2_backup_prune_sorts_by_mtime_and_cloud_list_paginates_list_objects_v2(): void
    {
        // P2-6 & P2-9: prune preserves newer scheduled backup over older shop-upload-* and removes >2h orphan temp files
        $tmpDir = storage_path('framework/testing/prune-mtime-' . Str::uuid());
        mkdir($tmpDir, 0700, true);
        try {
            $oldUpload = $tmpDir . '/shop-upload-20260101-000000-aaaaaaaa.tar.gz';
            $newScheduled = $tmpDir . '/shop-20261128-030000-bbbbbbbb.tar.gz';
            $staleTemp = $tmpDir . '/.pull-orphan.tar.gz';
            file_put_contents($oldUpload, 'old-upload');
            file_put_contents($newScheduled, 'new-scheduled');
            file_put_contents($staleTemp, 'stale-temp');
            touch($oldUpload, now()->subDays(10)->timestamp);
            touch($newScheduled, now()->subMinutes(5)->timestamp);
            touch($staleTemp, now()->subHours(5)->timestamp);

            Setting::set('backup_retention_count', '1', 'backup');
            Setting::set('backup_retention_days', '30', 'backup');

            $service = new class($tmpDir) extends ShopBackupService {
                public function __construct(private readonly string $customDir) {}
                public function directory(): string { return $this->customDir; }
            };
            $service->prune();

            $this->assertFileExists($newScheduled);
            $this->assertFileDoesNotExist($oldUpload);
            $this->assertFileDoesNotExist($staleTemp);
        } finally {
            foreach (glob($tmpDir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }

        // P2-7: CloudBackupStorageService::listBackups paginates via NextContinuationToken
        Setting::set('backup_r2_enabled', '1', 'backup');
        Setting::set('backup_r2_endpoint', 'https://acct.r2.cloudflarestorage.com', 'backup');
        Setting::set('backup_r2_region', 'auto', 'backup');
        Setting::set('backup_r2_bucket', 'shop-backups', 'backup');
        Setting::set('backup_r2_prefix', 'prod', 'backup');
        Setting::set('backup_r2_access_key', 'AKIA-TEST', 'backup');
        Setting::set('backup_r2_secret_key', 'SECRET-TEST', 'backup');

        $page1 = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult>
  <IsTruncated>true</IsTruncated>
  <NextContinuationToken>page-2-token</NextContinuationToken>
  <Contents>
    <Key>prod/shop-20260101-030000-aaaaaaaa.tar.gz</Key>
    <LastModified>2026-01-01T03:00:00.000Z</LastModified>
    <Size>1024</Size>
  </Contents>
</ListBucketResult>
XML;
        $page2 = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult>
  <IsTruncated>false</IsTruncated>
  <Contents>
    <Key>prod/shop-20261128-030000-bbbbbbbb.tar.gz</Key>
    <LastModified>2026-11-28T03:00:00.000Z</LastModified>
    <Size>2048</Size>
  </Contents>
</ListBucketResult>
XML;

        $call = 0;
        Http::fake(function ($request) use (&$call, $page1, $page2) {
            $call++;
            if ($call === 1) {
                return Http::response($page1, 200, ['Content-Type' => 'application/xml']);
            }
            $this->assertStringContainsString('continuation-token=page-2-token', $request->url());
            return Http::response($page2, 200, ['Content-Type' => 'application/xml']);
        });

        $list = app(CloudBackupStorageService::class)->listBackups('r2');
        $this->assertSame(2, $call);
        $this->assertCount(2, $list);
        $this->assertSame('shop-20261128-030000-bbbbbbbb.tar.gz', $list[0]['name']);
        $this->assertSame('shop-20260101-030000-aaaaaaaa.tar.gz', $list[1]['name']);
    }

    public function test_p1_and_p2_storefront_themes_pagination_search_escaping_wholesale_price_and_seo(): void
    {
        $product = $this->createProductWithTiers(15, 'Cloud Node A & B');
        Setting::set('contact_qr_image', '/storage/uploads/qr.png', 'site');
        Setting::set('contact_url', '', 'site');
        Setting::set('contact_text', '', 'site');

        // 1. Search heading must NOT double-escape `&` into `&amp;amp;`
        foreach (['default', 'mall', 'modern'] as $theme) {
            Setting::set('site_theme', $theme, 'site');
            $html = $this->get('/?q=' . urlencode('A & B'))->assertOk()->getContent();
            $this->assertStringNotContainsString('&amp;amp;', $html, "Double-escaped search query on theme {$theme}");
            $this->assertStringContainsString('A &amp; B', $html);
        }

        // 2. Default product row shows lowest wholesale price (70.00 起), NOT base price (100.00 起)
        Setting::set('site_theme', 'default', 'site');
        $defaultHome = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('70.00', $defaultHome);

        // 3. Modern & minimal: QR-only header trigger + product og:type + BreadcrumbList/AggregateOffer + article collection schema
        $articleCategory = ArticleCategory::create([
            'name' => '使用指南',
            'slug' => 'guides-' . uniqid(),
            'is_active' => true,
        ]);
        Article::create([
            'article_category_id' => $articleCategory->id,
            'title' => '测试指南',
            'slug' => 'guide-' . uniqid(),
            'summary' => '测试文章摘要',
            'content' => '[[product:' . $product->id . ']]',
            'is_published' => true,
        ]);
        foreach (['modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme, 'site');
            $prodHtml = $this->get('/product/' . $product->slug)->assertOk()->getContent();
            $this->assertStringContainsString('property="og:type" content="product"', $prodHtml);
            $this->assertStringContainsString('AggregateOffer', $prodHtml);
            $this->assertStringContainsString('BreadcrumbList', $prodHtml);
            $this->assertStringContainsString('commandfor="contact-qr-dialog"', $prodHtml);

            $articlesHtml = $this->get('/articles')->assertOk()->getContent();
            $this->assertStringContainsString('CollectionPage', $articlesHtml);
        }

        // 4. Empty first batch with hasMore = true still renders order-search-next pagination form on mall/modern/minimal
        $email = 'paged-buyer@example.test';
        $olderPass = 'older-match-pass';
        $otherPass = 'newer-other-pass';
        $oldOrder = Order::create([
            'order_no' => 'OLD' . now()->format('YmdHis') . '000',
            'product_id' => $product->id,
            'email' => $email,
            'query_password' => Hash::make($olderPass),
            'query_password_key' => null,
            'quantity' => 1,
            'unit_price' => '100.00',
            'total_amount' => '100.00',
            'discount_amount' => '0.00',
            'payment_method' => 'alipay',
            'status' => 'paid',
            'paid_at' => now()->subDays(2),
            'ip' => '198.51.100.60',
            'expires_at' => now()->subDays(2),
        ]);
        for ($i = 0; $i < 9; $i++) {
            Order::create([
                'order_no' => 'NEW' . now()->format('YmdHis') . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'product_id' => $product->id,
                'email' => $email,
                'query_password' => Hash::make($otherPass . '-' . $i),
                'query_password_key' => null,
                'quantity' => 1,
                'unit_price' => '100.00',
                'total_amount' => '100.00',
                'discount_amount' => '0.00',
                'payment_method' => 'alipay',
                'status' => 'expired',
                'ip' => '198.51.100.61',
                'expires_at' => now()->subHour(),
            ]);
        }

        foreach (['mall', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme, 'site');
            $resHtml = $this->post('/order/query', [
                'email' => $email,
                'query_password' => $olderPass,
            ])->assertOk()->getContent();
            $this->assertStringContainsString('/order/query/page', $resHtml, "Missing next-batch form on theme {$theme}");
        }
        $this->assertNotNull($oldOrder->id);
    }
}
