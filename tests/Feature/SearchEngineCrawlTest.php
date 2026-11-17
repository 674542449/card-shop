<?php

namespace Tests\Feature;

use App\Models\{Article, ArticleCategory, Blacklist, Card, Category, Product, ProductWholesalePrice, Setting};
use App\Support\SearchBotVerifier;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;

class SearchEngineCrawlTest extends TestCase
{
    public function test_robots_txt_points_crawlers_at_the_absolute_sitemap_without_hiding_noindex_pages(): void
    {
        $response = $this->get('/robots.txt')->assertOk();

        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $body = $response->getContent();
        $this->assertStringContainsString("User-agent: *\n", $body);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $body);
        $this->assertMatchesRegularExpression('#^Sitemap: https?://#m', $body);
        $this->assertStringContainsString("Disallow: /api/\n", $body);
        // A disallowed page cannot be fetched, so its noindex would never be seen.
        $this->assertStringNotContainsString('Disallow: /order', $body);
        $this->assertStringNotContainsString('Disallow: /payment', $body);
        // Naming the admin path would publish what ADMIN_PATH exists to hide.
        $this->assertStringNotContainsString(admin_path(), $body);
    }

    public function test_storefront_pages_are_indexable_and_titles_are_escaped_once(): void
    {
        $category = Category::create(['name' => '收录分类', 'slug' => 'crawl-category', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Tom & Jerry "套装"',
            'slug' => 'crawl-product',
            'price' => '9.90',
            'is_active' => true,
        ]);

        foreach (['/', '/category/'.$category->slug, '/product/'.$product->slug, '/articles', '/sitemap.xml', '/robots.txt'] as $path) {
            $this->get($path)->assertOk()->assertHeaderMissing('X-Robots-Tag');
        }

        $this->get('/product/'.$product->slug)
            ->assertSee('<title>Tom &amp; Jerry &quot;套装&quot; - ', false)
            ->assertDontSee('&amp;amp;', false);
    }

    public function test_paginated_lists_emit_self_referencing_canonicals_unique_titles_and_noindex_out_of_range_pages(): void
    {
        $category = Category::create(['name' => '分页分类', 'slug' => 'paged-category', 'is_active' => true]);
        for ($i = 1; $i <= 25; $i++) {
            Product::create([
                'category_id' => $category->id,
                'name' => '分页商品 '.$i,
                'slug' => 'paged-product-'.$i,
                'price' => '12.00',
                'is_active' => true,
            ]);
        }

        $articleCategory = ArticleCategory::create(['name' => '分页文档', 'slug' => 'paged-docs']);
        for ($i = 1; $i <= 11; $i++) {
            Article::create([
                'article_category_id' => $articleCategory->id,
                'title' => '分页指南 '.$i,
                'slug' => 'paged-guide-'.$i,
                'content' => '<p>文档正文 '.$i.'</p>',
                'is_published' => true,
            ]);
        }

        // Explicit ?page=1 canonicalizes to the clean base URL without ?page=1
        $homePage1 = $this->xpath($this->get('/?page=1')->assertOk()->assertHeaderMissing('X-Robots-Tag')->getContent());
        $this->assertSame(url('/'), $this->canonical($homePage1));

        // Page 2 self-references ?page=2 on both canonical and og:url, with unique title,
        // and its "上一页" / page-1 links point to clean canonical URLs without ?page=1.
        $homePage2 = $this->xpath($this->get('/?page=2')->assertOk()->assertHeaderMissing('X-Robots-Tag')->getContent());
        $this->assertSame(url('/').'?page=2', $this->canonical($homePage2));
        $this->assertSame(url('/').'?page=2', $this->meta($homePage2, 'property', 'og:url'));
        $this->assertStringContainsString('第 2 页', $homePage2->query('//head/title')->item(0)?->textContent ?? '');
        $this->assertSame(url('/'), $homePage2->query('//nav[@aria-label="分页导航"]//a[@rel="prev"]')->item(0)?->getAttribute('href'));
        $this->assertSame(url('/'), $homePage2->query('//nav[@aria-label="分页导航"]//a[@aria-label="第 1 页"]')->item(0)?->getAttribute('href'));

        $catPage2 = $this->xpath($this->get('/category/paged-category?page=2')->assertOk()->assertHeaderMissing('X-Robots-Tag')->getContent());
        $this->assertSame(url('/category/paged-category').'?page=2', $this->canonical($catPage2));
        $this->assertStringContainsString('分页分类 - 第 2 页', $catPage2->query('//head/title')->item(0)?->textContent ?? '');

        $artPage2 = $this->xpath($this->get('/articles?page=2')->assertOk()->assertHeaderMissing('X-Robots-Tag')->getContent());
        $this->assertSame(url('/articles').'?page=2', $this->canonical($artPage2));

        // Out-of-range empty pagination (?page=99) sends noindex, follow and omits canonical
        $emptyPageResponse = $this->get('/category/paged-category?page=99')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow');
        $emptyPageDom = $this->xpath($emptyPageResponse->getContent());
        $this->assertSame('noindex, follow', $this->meta($emptyPageDom, 'name', 'robots'));
        $this->assertNull($this->canonical($emptyPageDom));
    }

    public function test_internal_search_results_send_noindex_follow_and_omit_conflicting_canonical(): void
    {
        $category = Category::create(['name' => '搜索分类', 'slug' => 'search-category', 'is_active' => true]);
        Product::create([
            'category_id' => $category->id,
            'name' => '可搜索商品',
            'slug' => 'searchable-product',
            'price' => '15.00',
            'is_active' => true,
        ]);

        foreach (['/?q=可搜索', '/category/search-category?q=可搜索'] as $searchUrl) {
            $response = $this->get($searchUrl)
                ->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, follow');
            $dom = $this->xpath($response->getContent());
            $this->assertSame('noindex, follow', $this->meta($dom, 'name', 'robots'));
            $this->assertNull($this->canonical($dom), 'noindex search pages must not emit a conflicting canonical link.');
        }
    }

    public function test_honeypot_exempts_fcrdns_verified_googlebot_while_banning_spoofed_googlebot_ua(): void
    {
        Setting::set('honeypot_enabled', '1');
        Setting::set('honeypot_skip_reserved_ips', '0');

        $googlebotIp = '66.249.66.1';
        $spoofedIp = '203.0.113.88';

        $this->app->instance(SearchBotVerifier::class, new SearchBotVerifier(
            reverseResolver: fn (string $ip): ?string => match ($ip) {
                $googlebotIp => 'crawl-66-249-66-1.googlebot.com.',
                $spoofedIp => 'scanner.attacker.example.net',
                default => null,
            },
            forwardResolver: fn (string $host): array => match ($host) {
                'crawl-66-249-66-1.googlebot.com' => [$googlebotIp],
                default => [$spoofedIp],
            },
        ));

        $googleUa = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

        // Real Googlebot hitting a dirty external link (/wp-login.php) receives 404,
        // is NOT blacklisted, and continues crawling / and /sitemap.xml with 200 OK.
        $this->withServerVariables(['REMOTE_ADDR' => $googlebotIp])
            ->withHeader('User-Agent', $googleUa)
            ->get('/wp-login.php')
            ->assertNotFound();

        $this->assertFalse(Blacklist::isBlocked($googlebotIp));
        $this->withServerVariables(['REMOTE_ADDR' => $googlebotIp])
            ->withHeader('User-Agent', $googleUa)
            ->get('/')
            ->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => $googlebotIp])
            ->withHeader('User-Agent', $googleUa)
            ->get('/sitemap.xml')
            ->assertOk();

        // Spoofed Googlebot UA from an unverified IP is banned immediately on probe
        $this->withServerVariables(['REMOTE_ADDR' => $spoofedIp])
            ->withHeader('User-Agent', $googleUa)
            ->get('/wp-login.php')
            ->assertNotFound();

        $this->assertTrue(Blacklist::isBlocked($spoofedIp));
        $this->withServerVariables(['REMOTE_ADDR' => $spoofedIp])
            ->withHeader('User-Agent', $googleUa)
            ->get('/')
            ->assertForbidden();
    }

    public function test_sitemap_uses_deterministic_lastmod_timestamps_and_excludes_empty_product_categories(): void
    {
        $populatedCat = Category::create(['name' => '有货分类', 'slug' => 'active-cat', 'is_active' => true]);
        $emptyCat = Category::create(['name' => '空分类', 'slug' => 'empty-cat', 'is_active' => true]);
        Product::create([
            'category_id' => $populatedCat->id,
            'name' => '测试商品',
            'slug' => 'sitemap-product',
            'price' => '19.90',
            'is_active' => true,
        ]);

        $firstXml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/category/'.$populatedCat->slug, $firstXml);
        $this->assertStringNotContainsString('/category/'.$emptyCat->slug, $firstXml);

        // Advancing the wall clock without modifying records must NOT mutate <lastmod>
        $this->travel(10)->minutes();
        $secondXml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertSame($firstXml, $secondXml);
    }

    public function test_storefront_pages_emit_rich_json_ld_schemas_twitter_cards_and_auto_excerpt_descriptions(): void
    {
        Setting::set('site_name', 'NeoCard 商城');
        Setting::set('site_logo', '/storage/uploads/brand-logo.png');

        $category = Category::create(['name' => '效率软件', 'slug' => 'productivity', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => '专业版激活码',
            'slug' => 'pro-license',
            'description' => '<p>支持多端同步与云端备份，付款核验后自动发放激活凭证。</p>',
            'image' => '/storage/uploads/pro.png',
            'price' => '29.00',
            'is_active' => true,
        ]);
        Card::create(['product_id' => $product->id, 'content' => 'KEY-0001', 'status' => 'unsold']);
        ProductWholesalePrice::create([
            'product_id' => $product->id,
            'min_quantity' => 5,
            'price' => '24.00',
        ]);

        // 1. Homepage JSON-LD contains Organization + WebSite with SearchAction + ItemList
        $homeHtml = $this->get('/')->assertOk()->getContent();
        $homeDom = $this->xpath($homeHtml);
        $this->assertSame('zh_CN', $this->meta($homeDom, 'property', 'og:locale'));
        $this->assertSame(1, $homeDom->query('//h1')->length);
        $this->assertGreaterThanOrEqual(1, $homeDom->query('//h3/a[contains(@class, "ws-item-title")]')->length);
        $this->assertSame('high', $homeDom->query('//article[contains(@class, "fb-listing-card")][1]//img')->item(0)?->getAttribute('fetchpriority'));
        $homeGraph = $this->jsonLdGraph($homeHtml);
        $website = collect($homeGraph)->firstWhere('@type', 'WebSite');
        $homeItemList = collect($homeGraph)->firstWhere('@type', 'ItemList');
        $this->assertNotNull($website);
        $this->assertSame('SearchAction', $website['potentialAction']['@type'] ?? null);
        $this->assertNotNull($homeItemList);
        $this->assertSame(1, $homeItemList['numberOfItems'] ?? null);

        // 2. Product page auto-generates meta description from body HTML, Twitter card, BreadcrumbList & AggregateOffer with nested Offers
        $productHtml = $this->get('/product/pro-license')->assertOk()->getContent();
        $productDom = $this->xpath($productHtml);
        $this->assertSame(1, $productDom->query('//h1')->length);
        $this->assertStringContainsString('支持多端同步与云端备份', (string) $this->meta($productDom, 'name', 'description'));
        $this->assertSame('summary_large_image', $this->meta($productDom, 'name', 'twitter:card'));

        $productGraph = $this->jsonLdGraph($productHtml);
        $breadcrumb = collect($productGraph)->firstWhere('@type', 'BreadcrumbList');
        $productSchema = collect($productGraph)->firstWhere('@type', 'Product');
        $this->assertNotNull($breadcrumb);
        $this->assertCount(3, $breadcrumb['itemListElement']);
        $this->assertNotNull($productSchema);
        $this->assertSame((string) $product->id, $productSchema['sku']);
        $this->assertSame('pro-license', $productSchema['mpn']);
        $this->assertSame('AggregateOffer', $productSchema['offers']['@type']);
        $this->assertSame('24.00', $productSchema['offers']['lowPrice']);
        $this->assertSame('29.00', $productSchema['offers']['highPrice']);
        $this->assertSame('https://schema.org/InStock', $productSchema['offers']['availability']);
        $this->assertSame('https://schema.org/NewCondition', $productSchema['offers']['itemCondition']);
        $this->assertCount(2, $productSchema['offers']['offers']);
        $this->assertSame('Offer', $productSchema['offers']['offers'][0]['@type']);

        // Category page emits CollectionPage + mainEntity ItemList, and Sitemap includes Google Image Sitemap entries
        $catGraph = $this->jsonLdGraph($this->get('/category/productivity')->assertOk()->getContent());
        $collectionPage = collect($catGraph)->firstWhere('@type', 'CollectionPage');
        $this->assertSame('ItemList', $collectionPage['mainEntity']['@type'] ?? null);
        $sitemapXml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $sitemapXml);
        $this->assertStringContainsString('<image:loc>' . url('/storage/uploads/pro.png') . '</image:loc>', $sitemapXml);

        // Admin SPA shell and 404 pages are explicitly kept out of search indexes
        $this->get('/' . admin_path())->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $notFoundDom = $this->xpath($this->get('/product/non-existent-slug')->assertNotFound()->getContent());
        $this->assertSame('noindex, follow', $this->meta($notFoundDom, 'name', 'robots'));

        // 3. Article page emits BreadcrumbList + Article with author, publisher logo & auto body excerpt
        $artCategory = ArticleCategory::create(['name' => '使用说明', 'slug' => 'manuals']);
        Article::create([
            'article_category_id' => $artCategory->id,
            'title' => '如何兑换激活码',
            'slug' => 'how-to-redeem',
            'content' => '<p>打开客户端设置页面，粘贴卡密并点击激活即可生效。</p>',
            'is_published' => true,
        ]);

        $articleHtml = $this->get('/articles/how-to-redeem')->assertOk()->getContent();
        $articleDom = $this->xpath($articleHtml);
        $this->assertStringContainsString('打开客户端设置页面', (string) $this->meta($articleDom, 'name', 'description'));

        $articleGraph = $this->jsonLdGraph($articleHtml);
        $articleSchema = collect($articleGraph)->firstWhere('@type', 'Article');
        $this->assertNotNull($articleSchema);
        $this->assertSame('NeoCard 商城', $articleSchema['author']['name'] ?? null);
        $this->assertSame(url('/storage/uploads/brand-logo.png'), $articleSchema['publisher']['logo']['url'] ?? null);
    }

    public function test_storefront_layout_satisfies_page_experience_and_crawlable_link_guidelines(): void
    {
        Setting::set('popup_announcement', '<p>系统将于凌晨进行短暂数据库例行维护，不影响自动发卡。</p>');

        $category = Category::create([
            'name' => '亚太区',
            'slug' => 'oci-apac',
            'image' => '/storage/uploads/cat-apac.svg',
            'description' => '亚太区云节点专区',
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => '甲骨文云 日本东京（ap-tokyo-1）免费号 Always Free',
            'slug' => 'oci-free-ap-tokyo-1',
            'description' => '<p>东京节点低延迟直连。</p>',
            'image' => '/storage/uploads/oci-tokyo.svg',
            'price' => '188.00',
            'is_active' => true,
        ]);
        Card::create(['product_id' => $product->id, 'content' => 'OCI-TOKYO-01', 'status' => 'unsold']);

        $artCategory = ArticleCategory::create(['name' => '选区指南', 'slug' => 'guides']);
        $article = Article::create([
            'article_category_id' => $artCategory->id,
            'title' => '亚太区节点评测',
            'slug' => 'apac-node-review',
            'cover_image' => '/storage/uploads/article-cover.svg',
            'content' => "<p>节点评测正文。</p><p>[[product:{$product->id}]]</p>",
            'is_published' => true,
        ]);

        foreach (['/', '/category/oci-apac', '/product/oci-free-ap-tokyo-1', '/articles', '/articles/apac-node-review', '/order/query'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $dom = $this->xpath($html);

            // Single main & single h1 landmark hierarchy
            $this->assertSame(1, $dom->query('//main')->length, "Expected 1 <main> on {$path}");
            $this->assertSame(1, $dom->query('//h1')->length, "Expected 1 <h1> on {$path}");

            // Every <a> element is crawlable (non-empty href, never javascript:)
            foreach ($dom->query('//a') as $link) {
                $this->assertInstanceOf(DOMElement::class, $link);
                $href = trim($link->getAttribute('href'));
                $this->assertNotSame('', $href, "Uncrawlable empty <a href> on {$path}");
                $this->assertStringNotContainsString('javascript:', strtolower($href), "Uncrawlable javascript: link on {$path}");
            }

            // Every template <img> carries explicit numeric width & height to prevent CLS
            foreach ($dom->query('//img') as $img) {
                $this->assertInstanceOf(DOMElement::class, $img);
                $this->assertMatchesRegularExpression('/^\d+$/', $img->getAttribute('width'), "Missing numeric width on <img> on {$path}");
                $this->assertMatchesRegularExpression('/^\d+$/', $img->getAttribute('height'), "Missing numeric height on <img> on {$path}");
            }

            // Popup announcement never renders a blocking aria-modal interstitial; where included (/ and /product/*), it uses a non-modal bar
            $this->assertSame(0, $dom->query('//*[@id="announcement-modal"]//*[@aria-modal="true"]')->length);
            $notice = $dom->query('//*[@id="announcement-modal"]')->item(0);
            if (in_array($path, ['/', '/product/oci-free-ap-tokyo-1'], true)) {
                $this->assertInstanceOf(DOMElement::class, $notice, "Expected announcement notice element on {$path}");
                $this->assertSame('1', $notice->getAttribute('data-non-modal'));
                $this->assertSame(0, $dom->query('//*[@id="ann-modal-close" and @disabled]')->length);
            }
        }

        // Generic visual CTA text includes contextual sr-only product/section names for crawlers & screen readers
        $homeDom = $this->xpath($this->get('/')->assertOk()->getContent());
        $detailLinkText = trim($homeDom->query('//article[contains(@class, "fb-listing-card")]//a[contains(@class, "ws-btn-secondary")]')->item(0)?->textContent ?? '');
        $this->assertStringContainsString('查看详情：甲骨文云 日本东京', $detailLinkText);
        $allGuidesText = trim($homeDom->query('//section[@aria-label="公告与帮助文档"]//a[@href="/articles"]')->item(0)?->textContent ?? '');
        $this->assertStringContainsString('查看全部公告与帮助指南', $allGuidesText);
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    private function canonical(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//head/link[@rel="canonical"]')->item(0);

        return $node instanceof DOMElement ? $node->getAttribute('href') : null;
    }

    private function meta(DOMXPath $xpath, string $attr, string $name): ?string
    {
        $node = $xpath->query('//head/meta[@'.$attr.'="'.$name.'"]')->item(0);

        return $node instanceof DOMElement ? $node->getAttribute('content') : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLdGraph(string $html): array
    {
        $xpath = $this->xpath($html);
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        $entities = [];
        foreach ($scripts as $script) {
            $decoded = json_decode((string) $script->textContent, true, flags: JSON_THROW_ON_ERROR);
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $item) {
                    $entities[] = $item;
                }
            } elseif (is_array($decoded)) {
                $entities[] = $decoded;
            }
        }

        return $entities;
    }
}
