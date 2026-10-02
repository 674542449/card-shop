<?php

namespace Tests\Feature;

use App\Models\{Article, ArticleCategory, Card, Category, Order, Product, Setting};
use App\Services\{NotificationService, OrderFulfilmentService, OrderService};
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use Tests\TestCase;

// theme() resolves once per PHP request. Each dataset needs its own process so
// a previous theme cannot silently make all three datasets render the same views.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ThemeViewsTest extends TestCase
{
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $theme = $this->providedData()[0];

        // AppServiceProvider resolves theme() while it configures pagination,
        // before TestCase::setUp() and RefreshDatabase can prepare fixtures.
        $app->booting(function () use ($theme): void {
            if (config('database.default') !== 'pgsql'
                || config('database.connections.pgsql.database') !== 'cardshop_testing'
                || (int) config('database.redis.cache.database') !== 11
                || (int) config('database.redis.default.database') !== 10) {
                throw new \RuntimeException('Theme tests require the isolated cardshop_testing database and Redis DBs 10/11.');
            }

            // Eloquent is not booted yet, and RefreshDatabase has not migrated.
            // Supply the request settings snapshot for bootstrap only. setUp()
            // clears it and useTheme() persists the same fixture before HTTP calls.
            settings_memo(['site_theme' => $theme]);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public static function themes(): array
    {
        return [
            'default' => ['default'],
            'modern' => ['modern'],
            'minimal' => ['minimal'],
        ];
    }

    public static function independentThemes(): array
    {
        return ['modern' => ['modern'], 'minimal' => ['minimal']];
    }

    public static function independentDeadOrderStates(): array
    {
        return [
            'modern expired' => ['modern', 'expired'],
            'modern closed' => ['modern', 'closed'],
            'minimal expired' => ['minimal', 'expired'],
            'minimal closed' => ['minimal', 'closed'],
        ];
    }

    private function useTheme(string $theme): void
    {
        Setting::set('site_theme', $theme);
        Setting::set('site_name', '模板回归商城');
        $this->assertSame($theme, theme());
    }

    #[DataProvider('themes')]
    public function test_complete_catalog_search_pagination_refund_and_article_cards(string $theme): void
    {
        Setting::set('refund_enabled', '1');
        $this->useTheme($theme); $product = $this->product();
        for ($i = 0; $i < 26; $i++) {
            Product::create(['category_id' => $product->category_id, 'name' => '全目录商品-'.$i, 'slug' => 'full-catalog-'.$i, 'price' => 10, 'is_active' => true]);
        }
        $this->get('/')->assertOk()->assertSee('搜索全部商品')->assertSee('page=2', false)->assertDontSee('full-catalog-25');
        $this->get('/?q=全目录商品-25')->assertOk()->assertSee('full-catalog-25')->assertDontSee('full-catalog-24');
        $order = Order::create(['order_no' => 'FULLTHEMEORDER', 'product_id' => $product->id, 'email' => 'buyer@example.test', 'query_password' => bcrypt('buyer-password'), 'quantity' => 1,
            'unit_price' => 12, 'total_amount' => 12, 'status' => 'paid', 'payment_method' => 'manual', 'ip' => '192.0.2.1', 'expires_at' => now()->addMinutes(30)]);
        $this->withBuyerSession($order)->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('申请退款')->assertSee('/order/refund/'.$order->order_no);
        $html = \App\Support\ContentRenderer::toHtml('<p>[[product:'.$product->id.']]</p>', true);
        $this->assertStringContainsString('/product/'.$product->slug, $html);
        $product->category->update(['is_active' => false]);
        $this->get('/')->assertDontSee($product->slug)->assertDontSee('full-catalog-25');
    }

    private function product(array $attributes = []): Product
    {
        $category = Category::create([
            'name' => '模板回归分类',
            'slug' => 'theme-category',
            'is_active' => true,
        ]);
        $product = Product::create(array_replace([
            'category_id' => $category->id,
            'name' => '模板回归商品',
            'slug' => 'theme-product',
            'price' => '12.00',
            'min_quantity' => 1,
            'max_quantity' => 10,
            'is_active' => true,
        ], $attributes));
        Card::create(['product_id' => $product->id, 'content' => 'theme-test-card', 'status' => 'unsold']);

        return $product;
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $this->assertTrue($loaded, 'The HTTP response must contain parseable HTML.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function articleLinks(DOMXPath $dom): array
    {
        $links = [];
        foreach ($dom->query('//a[starts-with(@href, "/articles/theme-article-")]') as $link) {
            $links[] = $link->getAttribute('href');
        }

        return $links;
    }

    private function modernOrder(Product $product, array $attributes = []): Order
    {
        // Fixtures reserve real stock, while delivery messages and gateway calls
        // remain inside the test. No external recipient or payment is involved.
        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('sendOrderEmail')->willReturn(true);
        $this->instance(NotificationService::class, $notifications);
        Http::preventStrayRequests();

        return app(OrderService::class)->createOrder(array_replace([
            'product_id' => $product->id,
            'email' => 'theme-buyer@example.test',
            'query_password' => 'theme-query-password',
            'quantity' => 1,
            'payment_method' => 'alipay',
            'ip' => '192.0.2.40',
        ], $attributes));
    }

    private function assertIndependentShell(TestResponse $response): DOMXPath
    {
        $response->assertOk();
        $dom = $this->dom($response->getContent());
        $theme = theme();
        $this->assertSame(1, $dom->query('//body[contains(concat(" ", normalize-space(@class), " "), " '.$theme.'-store ")]')->length);
        $this->assertSame(1, $dom->query('//main[@id="main-content"]')->length);
        $this->assertSame(1, $dom->query('//main//h1')->length, 'Each order page needs one visible page heading.');
        $this->assertSame(1, $dom->query('//link[@rel="stylesheet" and contains(@href, "/themes/'.$theme.'/style.css")]')->length);
        $this->assertSame(0, $dom->query('//link[@rel="stylesheet" and contains(@href, "/css/front.css")]')->length, 'Modern must render with its own styles instead of inheriting the default storefront.');
        $this->assertSame(1, $dom->query('//script[contains(@src, "/js/front.js")]')->length, 'Copying, polling and submit guards require the shared behavior script.');

        return $dom;
    }

    private function assertPendingPaymentContract(DOMXPath $dom, Order $order): void
    {
        $polling = $dom->query('//*[@id="payment-polling"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $polling);
        $this->assertSame($order->order_no, $polling->getAttribute('data-order-no'));

        $timer = $dom->query('//*[@id="countdown-timer"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $timer);
        $this->assertSame($order->expires_at->getTimestamp(), strtotime($timer->getAttribute('data-expires')));
        $this->assertSame(1, $dom->query('//*[@id="countdown-timer"]//*[contains(concat(" ", normalize-space(@class), " "), " time ")]')->length);
    }

    #[DataProvider('themes')]
    public function test_storefront_pages_and_article_pagination_render_for_each_theme(string $theme): void
    {
        $this->useTheme($theme);
        $product = $this->product();
        $articleCategory = ArticleCategory::create(['name' => '模板教程', 'slug' => 'theme-guides']);
        $timestamp = now()->startOfSecond();
        for ($i = 1; $i <= 11; $i++) {
            $article = Article::create([
                'article_category_id' => $articleCategory->id,
                'title' => '模板回归文章 '.$i,
                'slug' => 'theme-article-'.$i,
                'content' => '<p>模板文章正文 '.$i.'</p>',
                'is_published' => true,
            ]);
            // A shared timestamp recreates ties that used to duplicate/omit rows
            // between pages. Both pages must contain all eleven articles once.
            $article->created_at = $timestamp;
            $article->save();
        }

        $this->get('/')->assertOk()->assertViewIs('templates.'.$theme.'.home')->assertSee($product->name);
        $this->get('/category/theme-category')->assertOk()->assertSee($product->name);
        $this->get('/product/theme-product')->assertOk()->assertSee($product->name);
        $this->get('/order/query')->assertOk()->assertSee('查询密码');
        $this->get('/articles/theme-article-1')->assertOk()->assertSee('模板文章正文 1');

        foreach (['/articles', '/articles/category/theme-guides'] as $url) {
            $first = $this->get($url)->assertOk();
            $second = $this->get($url.'?page=2')->assertOk();
            $firstDom = $this->dom($first->getContent());
            $firstLinks = $this->articleLinks($firstDom);
            $secondLinks = $this->articleLinks($this->dom($second->getContent()));
            $this->assertCount(10, $firstLinks, $url.' page one');
            $this->assertCount(1, $secondLinks, $url.' page two');
            $this->assertCount(11, array_unique(array_merge($firstLinks, $secondLinks)));
            $this->assertSame([], array_values(array_intersect($firstLinks, $secondLinks)));

            $pageTwoLinks = $firstDom->query('//a[contains(@href, "page=2")]');
            $this->assertGreaterThan(0, $pageTwoLinks->length, 'Buyers must be able to reach the second page.');
        }
    }

    #[DataProvider('themes')]
    public function test_image_only_product_description_is_visible_in_rendered_html(string $theme): void
    {
        $this->useTheme($theme);
        $image = '/storage/theme-description-test.png';
        $product = $this->product(['description' => '<p><img src="'.$image.'" alt="商品使用说明"></p>']);
        $response = $this->get('/product/'.$product->slug)->assertOk();
        $dom = $this->dom($response->getContent());
        $images = $dom->query('//main//img[@src="'.$image.'"]');
        $this->assertSame(1, $images->length, 'A description containing only an image must not be hidden as empty text.');
        $this->assertSame('商品使用说明', $images->item(0)->getAttribute('alt'));
    }

    #[DataProvider('themes')]
    public function test_checkout_and_query_forms_expose_the_server_validation_constraints(string $theme): void
    {
        $this->useTheme($theme);
        $product = $this->product();
        $response = $this->get('/product/'.$product->slug)->assertOk();
        $dom = $this->dom($response->getContent());
        $quantity = $dom->query('//form[@action="/order/create"]//input[@name="quantity"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $quantity);
        $this->assertTrue($quantity->hasAttribute('required'), 'Empty quantity must be rejected by the browser before checkout.');
        $this->assertSame('number', $quantity->getAttribute('type'));
        $this->assertSame('1', $quantity->getAttribute('step'), 'Cards can only be purchased in whole quantities.');
        $this->assertSame('1', $quantity->getAttribute('min'));
        $this->assertSame('1', $quantity->getAttribute('max'), 'The form must cap quantity at actual stock.');

        $checkoutPassword = $dom->query('//form[@action="/order/create"]//input[@name="query_password"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $checkoutPassword);
        $this->assertTrue($checkoutPassword->hasAttribute('required'));
        $this->assertSame('6', $checkoutPassword->getAttribute('minlength'));

        $query = $this->get('/order/query')->assertOk();
        $queryPassword = $this->dom($query->getContent())->query('//form[@action="/order/query"]//input[@name="query_password"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $queryPassword);
        $this->assertTrue($queryPassword->hasAttribute('required'));
    }

    #[DataProvider('independentThemes')]
    public function test_independent_query_and_result_keep_credentials_private_and_orders_accessible(string $theme): void
    {
        $this->useTheme($theme);
        $query = $this->withSession(['_old_input' => [
            'email' => 'theme-buyer@example.test',
            'query_password' => 'never-render-this-password',
        ]])->get('/order/query')->assertViewIs('templates.'.$theme.'.order.query');
        $query->assertDontSee('never-render-this-password');
        $queryDom = $this->assertIndependentShell($query);
        $form = $queryDom->query('//main//form[@action="/order/query"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        $this->assertTrue($form->hasAttribute('data-guard'));
        $this->assertSame(1, $queryDom->query('//form[@action="/order/query"]//input[@name="_token" and @type="hidden"]')->length);
        $this->assertSame(1, $queryDom->query('//form[@action="/order/query"]//input[@name="email" and @type="email" and @required]')->length);
        $password = $queryDom->query('//form[@action="/order/query"]//input[@name="query_password"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $password);
        $this->assertSame('password', $password->getAttribute('type'));
        $this->assertTrue($password->hasAttribute('required'));
        $this->assertFalse($password->hasAttribute('value'), 'A rejected query password must never be redisplayed.');
        $this->assertSame(1, $queryDom->query('//form[@action="/order/query"]//button[@type="submit"]')->length);

        $product = $this->product();
        Card::create(['product_id' => $product->id, 'content' => 'second-private-card', 'status' => 'unsold']);
        $paid = $this->modernOrder($product);
        app(OrderFulfilmentService::class)->fulfilManually($paid);
        $pending = $this->modernOrder($product);
        $result = $this->post('/order/query', [
            'email' => 'theme-buyer@example.test',
            'query_password' => 'theme-query-password',
        ])->assertViewIs('templates.'.$theme.'.order.result');
        $resultDom = $this->assertIndependentShell($result);
        foreach ([$paid, $pending] as $order) {
            $result->assertSee($order->order_no);
            $this->assertSame(1, $resultDom->query('//main//a[@href="/order/detail/'.$order->order_no.'"]')->length, 'Each matched order must have a working detail link.');
        }
        $result->assertSee('已支付')->assertSee('待支付');
        $result->assertDontSee('theme-test-card')->assertDontSee('second-private-card');
        $this->assertSame(0, $resultDom->query('//*[@id="card-content-text"]')->length, 'The history page must not contain a hidden copy of card secrets.');
    }

    #[DataProvider('independentThemes')]
    public function test_independent_pending_payment_keeps_gateway_timer_polling_and_unpaid_cards_separate(string $theme): void
    {
        $this->useTheme($theme);
        $order = $this->modernOrder($this->product());
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', 'theme-merchant', 'payment');
        Setting::set('epay_merchant_key', 'theme-gateway-key', 'payment');
        $response = $this->get('/order/pay/'.$order->order_no)->assertViewIs('templates.'.$theme.'.order.pay');
        $response->assertSee('12.00')->assertDontSee('theme-test-card');
        $dom = $this->assertIndependentShell($response);
        $this->assertPendingPaymentContract($dom, $order);
        $paymentLink = $dom->query('//main//a[starts-with(@href, "https://gateway.example.test/")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $paymentLink);
        parse_str(parse_url($paymentLink->getAttribute('href'), PHP_URL_QUERY), $gatewayFields);
        $this->assertSame($order->order_no, $gatewayFields['out_trade_no']);
        $this->assertSame('12.00', $gatewayFields['money']);
        $this->assertSame('_blank', $paymentLink->getAttribute('target'));
        $this->assertStringContainsString('noopener', $paymentLink->getAttribute('rel'));
        $this->assertSame(0, $dom->query('//main//a[@href="/order/cards/'.$order->order_no.'/download"]')->length);

        $detail = $this->withBuyerSession($order)
            ->get('/order/detail/'.$order->order_no)->assertViewIs('templates.'.$theme.'.order.detail');
        $detail->assertDontSee('theme-test-card');
        $detailDom = $this->assertIndependentShell($detail);
        $this->assertSame(1, $detailDom->query('//main//a[@href="/order/pay/'.$order->order_no.'"]')->length, 'The verified owner must be able to continue an unpaid order.');
        $this->assertSame(0, $detailDom->query('//*[@id="card-content-text"]')->length);
        $this->assertSame(0, $detailDom->query('//main//a[@href="/order/cards/'.$order->order_no.'/download"]')->length);
    }

    #[DataProvider('independentThemes')]
    public function test_independent_unavailable_payment_has_a_retry_action_without_exposing_cards(string $theme): void
    {
        $this->useTheme($theme);
        $order = $this->modernOrder($this->product());
        $response = $this->get('/order/pay/'.$order->order_no)->assertViewIs('templates.'.$theme.'.order.pay');
        $response->assertSee('支付渠道暂时不可用')->assertDontSee('theme-test-card');
        $dom = $this->assertIndependentShell($response);
        $this->assertPendingPaymentContract($dom, $order);
        $this->assertGreaterThan(0, $dom->query('//main//*[@role="alert"]')->length, 'A missing gateway must produce an actionable error instead of indefinite preparation.');
        $this->assertSame(1, $dom->query('//main//a[@href="/order/pay/'.$order->order_no.'"]')->length);
        $this->assertSame(0, $dom->query('//main//a[starts-with(@href, "https://gateway.example.test/")]')->length);
        $this->assertSame('pending', $order->fresh()->status);
    }

    #[DataProvider('independentDeadOrderStates')]
    public function test_independent_dead_payment_pages_never_restart_countdown_or_offer_payment(string $theme, string $state): void
    {
        $this->useTheme($theme);
        $order = $this->modernOrder($this->product());
        // A configured gateway must not turn an expired/closed receipt back into
        // a payable order, even when it could generate a valid live-order link.
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', 'theme-merchant', 'payment');
        Setting::set('epay_merchant_key', 'theme-gateway-key', 'payment');
        if ($state === 'expired') {
            $order->update(['expires_at' => now()->subMinute()]);
        } else {
            app(OrderService::class)->closeOrder($order);
        }
        $response = $this->get('/order/pay/'.$order->order_no)->assertViewIs('templates.'.$theme.'.order.pay');
        $response->assertSee($state === 'expired' ? '订单已过期' : '订单已关闭')->assertDontSee('theme-test-card');
        $dom = $this->assertIndependentShell($response);
        $this->assertSame($state, $order->fresh()->status);
        $this->assertSame(0, $dom->query('//*[@id="payment-polling" or @id="countdown-timer"]')->length, 'Dead orders must not get a past-deadline timer that repeatedly reloads the page.');
        $this->assertSame(0, $dom->query('//main//a[starts-with(@href, "https://gateway.example.test/")]')->length);
        $this->assertGreaterThan(0, $dom->query('//main//a[@href="/"]')->length, 'A dead order must let the buyer start a new purchase.');
    }

    #[DataProvider('themes')]
    public function test_received_but_unfulfilled_payment_prompts_review_instead_of_repayment(string $theme): void
    {
        $this->useTheme($theme);
        $order = $this->modernOrder($this->product());
        app(OrderService::class)->closeOrder($order);
        $order->update(['payment_no' => 'theme-received-payment']);

        $pay = $this->get('/order/pay/'.$order->order_no)->assertOk()->assertSee('付款待核对')->assertSee('请勿重复支付');
        $pay->assertDontSee('countdown-timer')->assertDontSee('payment-polling')->assertDontSee('theme-test-card');
        $this->withBuyerSession($order)
            ->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('付款待核对')->assertSee('请勿重复支付')->assertDontSee('theme-test-card');
        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }

    #[DataProvider('independentThemes')]
    public function test_independent_paid_delivery_requires_verification_and_preserves_copy_and_download_sources(string $theme): void
    {
        $this->useTheme($theme);
        $product = $this->product();
        Card::create(['product_id' => $product->id, 'content' => '0', 'status' => 'unsold']);
        $order = $this->modernOrder($product, ['quantity' => 2]);
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $contents = $order->cards()->pluck('content')->all();

        $this->get('/order/detail/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/pay/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertRedirect('/order/query');
        $this->post('/order/query', [
            'email' => 'theme-buyer@example.test',
            'query_password' => 'theme-query-password',
        ])->assertViewIs('templates.'.$theme.'.order.result');

        foreach (['/order/detail/', '/order/pay/'] as $route) {
            $response = $this->get($route.$order->order_no)->assertViewIs('templates.'.$theme.'.order.detail');
            $dom = $this->assertIndependentShell($response);
            $this->assertSame(0, $dom->query('//*[@id="payment-polling" or @id="countdown-timer"]')->length);
            $copySource = $dom->query('//textarea[@id="card-content-text"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $copySource);
            $this->assertTrue($copySource->hasAttribute('readonly'));
            $this->assertSame('-1', $copySource->getAttribute('tabindex'));
            $this->assertSame('true', $copySource->getAttribute('aria-hidden'));
            $this->assertSame(implode("\n", $contents)."\n", $copySource->textContent, 'Copy all must retain every complete card, including a zero-only card.');
            $buttons = $dom->query('//main//button[contains(concat(" ", normalize-space(@class), " "), " btn-copy ")]');
            $this->assertSame(count($contents) + 1, $buttons->length);
            $individualContents = [];
            foreach ($buttons as $button) {
                $this->assertSame('button', $button->getAttribute('type'), 'Copy controls must not accidentally submit a form.');
                $target = $dom->query('//*[@id="'.$button->getAttribute('data-target').'"]');
                $this->assertSame(1, $target->length, 'Every copy control needs exactly one real source element.');
                if ($button->getAttribute('data-target') !== 'card-content-text') {
                    $individualContents[] = $target->item(0)->textContent;
                }
            }
            $this->assertEqualsCanonicalizing($contents, $individualContents);
            $this->assertSame(1, $dom->query('//main//a[@href="/order/cards/'.$order->order_no.'/download"]')->length);
        }
        $download = $this->get('/order/cards/'.$order->order_no.'/download')->assertOk();
        $download->assertHeader('Content-Disposition', 'attachment; filename="cards-'.$order->order_no.'.txt"');
        foreach ($contents as $content) {
            $this->assertStringContainsString("\r\n".$content."\r\n", $download->getContent());
        }
    }
}
