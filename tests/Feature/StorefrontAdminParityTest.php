<?php

namespace Tests\Feature;

use App\Models\{Article, ArticleCategory, Card, Category, Coupon, Order, Product, ProductWholesalePrice, Setting};
use App\Services\{BrowserOrderCredentialProof, OrderFulfilmentService, OrderService};
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontAdminParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::set('site_theme', 'default', 'general');
    }

    public function test_admin_site_url_setting_drives_storefront_canonicals_opengraph_jsonld_sitemap_and_robots_without_changing_payment_callbacks(): void
    {
        Setting::set('site_url', 'https://shop.canonical-example.test/', 'seo');
        Setting::set('site_logo', '/storage/uploads/canonical-logo.png', 'general');

        $category = Category::create([
            'name' => '规范域名分类',
            'slug' => 'canonical-cat',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => '规范域名商品',
            'slug' => 'canonical-prod',
            'category_id' => $category->id,
            'price' => '19.90',
            'image' => '/storage/uploads/canonical-prod.png',
            'min_quantity' => 1,
            'max_quantity' => 5,
            'is_active' => true,
        ]);
        Card::create(['product_id' => $product->id, 'content' => 'SECRET-CANON-1', 'status' => 'unsold']);

        $articleCat = ArticleCategory::create(['name' => '规范指南', 'slug' => 'canonical-guides', 'sort_order' => 1]);
        $article = Article::create([
            'title' => '规范链接说明',
            'slug' => 'canonical-guide-post',
            'article_category_id' => $articleCat->id,
            'content' => '<p>正文内容</p>',
            'cover_image' => '/storage/uploads/canonical-cover.png',
            'is_published' => true,
        ]);

        // 1. Homepage canonical, OG & JSON-LD
        $home = $this->get('/')->assertOk();
        $home->assertSee('<link rel="canonical" href="https://shop.canonical-example.test">', false);
        $home->assertSee('<meta property="og:url" content="https://shop.canonical-example.test">', false);
        $home->assertSee('<meta property="og:image" content="https://shop.canonical-example.test/storage/uploads/canonical-logo.png">', false);
        $home->assertSee('https://shop.canonical-example.test#organization', false);

        // 2. Category, Product & Article canonicals + OG images
        $this->get('/category/canonical-cat')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://shop.canonical-example.test/category/canonical-cat">', false);

        $this->get('/product/canonical-prod')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://shop.canonical-example.test/product/canonical-prod">', false)
            ->assertSee('<meta property="og:image" content="https://shop.canonical-example.test/storage/uploads/canonical-prod.png">', false);

        $this->get('/articles/canonical-guide-post')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://shop.canonical-example.test/articles/canonical-guide-post">', false)
            ->assertSee('<meta property="og:image" content="https://shop.canonical-example.test/storage/uploads/canonical-cover.png">', false);

        // 3. Sitemap & robots.txt use site_url
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>https://shop.canonical-example.test</loc>', false)
            ->assertSee('<loc>https://shop.canonical-example.test/product/canonical-prod</loc>', false)
            ->assertSee('<loc>https://shop.canonical-example.test/articles/canonical-guide-post</loc>', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://shop.canonical-example.test/sitemap.xml', false);

        // 4. Payment gateway notify_url remains pinned to APP_URL
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1001', 'payment');
        Setting::set('epay_merchant_key', 'gateway-secret', 'payment');

        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'canon@example.test',
            'query_password' => 'secret123',
            'payment_method' => 'alipay',
            'ip' => '192.0.2.11',
        ]);
        $payHtml = $this->get($this->signedPayPath($order))->assertOk()->getContent();
        $this->assertStringContainsString(urlencode(rtrim((string) config('app.url'), '/') . '/payment/epay/notify'), $payHtml);
    }

    public function test_admin_customer_support_settings_render_across_homepage_and_order_pages_including_url_only_or_text_only_modes(): void
    {
        // Case A: Only contact_url configured (contact_text & contact_qr_image empty)
        Setting::set('contact_text', '', 'general');
        Setting::set('contact_qr_image', '', 'general');
        Setting::set('contact_url', 'https://t.me/cardshop_support', 'general');

        foreach (['default', 'mall', 'minimal', 'modern'] as $theme) {
            Setting::set('site_theme', $theme, 'general');
            $this->get('/')->assertOk()->assertSee('https://t.me/cardshop_support', false);
        }

        // Case B: Text & QR configured without external URL -> visible in default layout footer on order query page
        Setting::set('site_theme', 'default', 'general');
        Setting::set('contact_url', '', 'general');
        Setting::set('contact_text', '工作时间 09:00-22:00 · 客服邮箱 help@example.test', 'general');
        Setting::set('contact_qr_image', '/storage/uploads/support-qr.png', 'general');

        $this->get('/order/query')
            ->assertOk()
            ->assertSee('工作时间 09:00-22:00 · 客服邮箱 help@example.test')
            ->assertSee('/storage/uploads/support-qr.png', false);
    }

    public function test_admin_product_low_stock_threshold_and_wholesale_tiers_align_with_storefront_badges_and_initial_ssr_pricing(): void
    {
        $category = Category::create(['name' => '阶梯测试分类', 'slug' => 'tier-cat', 'is_active' => true]);

        // Product 1: stock = 8, low_stock_threshold = 10 -> should trigger low-stock warning
        // min_quantity = 3, max_quantity = 6; tier at 3 pcs = 8.00, tier at 10 pcs = 6.00 (unreachable due to maxBuyable = 6)
        $product = Product::create([
            'name' => '阶梯预警测试商品',
            'slug' => 'tier-lowstock-prod',
            'category_id' => $category->id,
            'price' => '12.00',
            'min_quantity' => 3,
            'max_quantity' => 6,
            'low_stock_threshold' => 10,
            'is_active' => true,
        ]);
        for ($i = 0; $i < 8; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'TIER-CARD-' . $i, 'status' => 'unsold']);
        }
        ProductWholesalePrice::create(['product_id' => $product->id, 'min_quantity' => 3, 'price' => '8.00']);
        ProductWholesalePrice::create(['product_id' => $product->id, 'min_quantity' => 10, 'price' => '6.00']);

        // Product 2: stock = 2, low_stock_threshold = null -> warning explicitly disabled by admin
        $quietProduct = Product::create([
            'name' => '关闭预警测试商品',
            'slug' => 'quiet-stock-prod',
            'category_id' => $category->id,
            'price' => '15.00',
            'min_quantity' => 1,
            'max_quantity' => 5,
            'low_stock_threshold' => null,
            'is_active' => true,
        ]);
        Card::create(['product_id' => $quietProduct->id, 'content' => 'QUIET-1', 'status' => 'unsold']);
        Card::create(['product_id' => $quietProduct->id, 'content' => 'QUIET-2', 'status' => 'unsold']);

        $home = $this->get('/')->assertOk();
        $home->assertSee('库存紧张 · 余 8 件');
        $home->assertSee('库存 2 件');
        $home->assertDontSee('库存紧张 · 余 2 件');
        $home->assertDontSee('人工发货');

        // Product detail SSR: min_quantity=3 qualifies for 8.00/pc (total 24.00), and 10-pc tier is marked unavailable
        $detail = $this->get('/product/tier-lowstock-prod')->assertOk();
        $detail->assertSee('id="unit-price">&yen;8.00</span>', false);
        $detail->assertSee('id="total-price">&yen;24.00</strong>', false);
        $detail->assertSee('超出当前可购上限 6 件');
        $detail->assertSee('data-tier-disabled="1"', false);
    }

    public function test_admin_article_cover_views_category_counts_and_embedded_product_state_align_with_storefront(): void
    {
        $prodCat = Category::create(['name' => '内嵌分类', 'slug' => 'embed-cat', 'is_active' => true]);
        $soldOutProduct = Product::create([
            'name' => '售罄内嵌商品',
            'slug' => 'soldout-embed-prod',
            'category_id' => $prodCat->id,
            'price' => '29.00',
            'image' => '/storage/uploads/embed-thumb.png',
            'min_quantity' => 1,
            'max_quantity' => 5,
            'is_active' => true,
        ]);

        $artCat = ArticleCategory::create(['name' => '常见问题', 'slug' => 'faq-list', 'sort_order' => 1]);
        $published = Article::create([
            'title' => '带封面帮助文档',
            'slug' => 'covered-faq',
            'article_category_id' => $artCat->id,
            'summary' => '列表摘要说明',
            'content' => "<p>正文引导</p><p>[[product:{$soldOutProduct->id}]]</p>",
            'cover_image' => '/storage/uploads/article-list-cover.png',
            'is_published' => true,
        ]);
        Article::create([
            'title' => '未发布草稿',
            'slug' => 'draft-faq',
            'article_category_id' => $artCat->id,
            'content' => '<p>草稿</p>',
            'is_published' => false,
        ]);

        // Article detail increments views and embedded product card reflects out-of-stock state & thumbnail
        $show = $this->get('/articles/' . $published->slug)->assertOk();
        $show->assertSee('/storage/uploads/embed-thumb.png', false);
        $show->assertSee('暂时缺货');
        $show->assertSee('查看详情');

        // Article list renders cover image, updated views (1), and published-only category count
        $list = $this->get('/articles')->assertOk();
        $list->assertSee('/storage/uploads/article-list-cover.png', false);
        $list->assertSee('阅读量 1');
        $list->assertDontSee('未发布草稿');
    }

    public function test_order_query_form_constraint_and_paid_order_coupon_or_empty_card_fallback_align_with_backend(): void
    {
        $queryPage = $this->get('/order/query?order_no=ORD202611160001')->assertOk();
        $queryPage->assertSee('value="ORD202611160001"', false);
        $queryPage->assertSee('maxlength="30"', false);

        $category = Category::create(['name' => '回执测试分类', 'slug' => 'receipt-cat', 'is_active' => true]);
        $product = Product::create([
            'name' => '回执测试商品',
            'slug' => 'receipt-prod',
            'category_id' => $category->id,
            'price' => '20.00',
            'min_quantity' => 1,
            'max_quantity' => 5,
            'is_active' => true,
        ]);
        Card::create(['product_id' => $product->id, 'content' => 'RECEIPT-CARD-1', 'status' => 'unsold']);
        Coupon::create(['code' => 'SAVE5', 'type' => 'fixed', 'value' => '5.00', 'is_active' => true]);

        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'quantity' => 1,
            'email' => 'receipt@example.test',
            'query_password' => 'receipt-pass',
            'coupon_code' => 'SAVE5',
            'payment_method' => 'usdt_trc20',
            'ip' => '192.0.2.55',
        ]);
        app(OrderFulfilmentService::class)->fulfilManually($order);

        // Grant verified proof in session
        $proof = [];
        foreach ([
            $order->order_no => hash('sha256', implode("\0", [(string) $order->id, mb_strtolower($order->email), (string) $order->query_password])),
        ] as $k => $v) {
            $proof[$k] = $v;
        }
        // Use the real proof service via a test request session
        $this->post('/order/query', [
            'email' => 'receipt@example.test',
            'query_password' => 'receipt-pass',
            'order_no' => $order->order_no,
        ])->assertOk();

        $detail = $this->get('/order/detail/' . $order->order_no)->assertOk();
        $detail->assertSee('SAVE5');
        $detail->assertSee('RECEIPT-CARD-1');

        // Edge case: if cards are detached/empty on a paid order, header and body show consistent fallback
        $order->cards()->delete();
        $emptyDetail = $this->get('/order/detail/' . $order->order_no)->assertOk();
        $emptyDetail->assertSee('当前订单暂无关联的可展示卡密凭据');
        $emptyDetail->assertDontSee('请妥善保存下方卡密凭据');
    }
}
