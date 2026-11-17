<?php

namespace App\Support;

use App\Models\Article;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class Seo
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /**
     * Resolves the canonical site origin configured in Admin SEO settings (`site_url`),
     * falling back to `config('app.url')` while keeping payment callback URLs untouched.
     */
    public static function canonicalOrigin(): string
    {
        $configured = SafeUrl::http(setting('site_url'));
        $base = $configured ?: (string) config('app.url');

        return rtrim($base, '/') ?: rtrim(url('/'), '/');
    }

    /**
     * Builds a canonical absolute URL under `canonicalOrigin()`.
     */
    public static function url(string $path = '/'): string
    {
        $origin = self::canonicalOrigin();
        $trimmed = ltrim($path, '/');

        return $trimmed === '' ? $origin : $origin . '/' . $trimmed;
    }

    /**
     * Resolves an uploaded asset path or external image URL to an absolute URL.
     */
    public static function assetUrl(?string $asset): ?string
    {
        $safe = SafeUrl::asset($asset);
        if (!$safe) {
            return null;
        }
        if (preg_match('#^https?://#i', $safe)) {
            return $safe;
        }

        return self::url($safe);
    }

    /**
     * Self-referencing canonical URL for paginated listings.
     *
     * Page 1 (including explicit ?page=1) resolves to the clean base URL;
     * pages >= 2 self-reference ?page=N so search engines index deeper pages.
     */
    public static function paginationCanonical(string $baseUrl, LengthAwarePaginator $paginator): string
    {
        $page = max(1, (int) $paginator->currentPage());

        return $page > 1 ? $baseUrl . '?page=' . $page : $baseUrl;
    }

    /**
     * Normalizes paginator links so page 1 points directly to the canonical base URL
     * without `?page=1`, preventing duplicate crawl paths and split PageRank signals.
     */
    public static function cleanPageUrl(?string $url): string
    {
        $url = (string) $url;
        if ($url === '') {
            return '';
        }

        $cleaned = (string) preg_replace([
            '/\?page=1$/',
            '/\?page=1&/',
            '/&page=1(?=&|$)/',
        ], [
            '',
            '?',
            '',
        ], $url);

        return rtrim($cleaned, '?&');
    }

    /**
     * Appends a formal page-number suffix on page >= 2 to prevent duplicate titles.
     */
    public static function paginationTitle(string $baseTitle, LengthAwarePaginator $paginator): string
    {
        $page = max(1, (int) $paginator->currentPage());

        return $page > 1 ? $baseTitle . ' - 第 ' . $page . ' 页' : $baseTitle;
    }

    /**
     * Extracts a clean plain-text excerpt from rendered HTML or raw text for meta descriptions.
     */
    public static function excerpt(?string $htmlOrText, int $limit = 160): string
    {
        $raw = (string) $htmlOrText;
        if (trim($raw) === '') {
            return '';
        }

        $withoutShortcodes = (string) preg_replace('/\[\[product:\d+\]\]/', ' ', $raw);
        $decoded = html_entity_decode(strip_tags($withoutShortcodes), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $decoded));

        return $normalized !== '' ? Str::limit($normalized, $limit) : '';
    }

    /**
     * Safely serializes a Schema.org payload into a JSON-LD <script> tag.
     */
    public static function jsonLdScript(array $data): HtmlString
    {
        $json = json_encode($data, self::JSON_FLAGS);

        return new HtmlString('<script type="application/ld+json">' . ($json ?: '{}') . '</script>');
    }

    /**
     * Builds a Schema.org BreadcrumbList entity.
     *
     * @param  array<int, array{name: string, url: string}>  $items
     */
    public static function breadcrumbSchema(array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * Builds a Schema.org ItemList entity for catalog and collection pages.
     *
     * @param  array<int, array{name: string, url: string}>  $items
     */
    public static function itemListSchema(array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'url' => $item['url'],
            ];
        }

        return [
            '@type' => 'ItemList',
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
    }

    /**
     * Homepage structured data: Organization + WebSite with Sitelinks SearchAction (+ optional ItemList).
     *
     * @param  array<int, array{name: string, url: string}>  $items
     */
    public static function homeSchema(string $siteName, string $description, array $items = []): array
    {
        $homeUrl = self::url('/');
        $orgId = $homeUrl . '#organization';
        $organization = [
            '@type' => 'Organization',
            '@id' => $orgId,
            'name' => $siteName,
            'url' => $homeUrl,
        ];

        $logoUrl = self::assetUrl(setting('site_logo'));
        if ($logoUrl) {
            $organization['logo'] = [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
            ];
        }

        $website = [
            '@type' => 'WebSite',
            '@id' => $homeUrl . '#website',
            'name' => $siteName,
            'url' => $homeUrl,
            'inLanguage' => 'zh-CN',
            'publisher' => ['@id' => $orgId],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $homeUrl . '?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];

        if ($description !== '') {
            $website['description'] = $description;
        }

        $graph = [$organization, $website];
        if ($items !== []) {
            $graph[] = self::itemListSchema($items);
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /**
     * Category / Article listing structured data: BreadcrumbList + CollectionPage (+ optional ItemList).
     *
     * @param  array<int, array{name: string, url: string}>  $breadcrumbs
     * @param  array<int, array{name: string, url: string}>  $items
     */
    public static function collectionSchema(
        string $name,
        string $canonicalUrl,
        string $description,
        array $breadcrumbs,
        array $items = [],
    ): array {
        $collection = [
            '@type' => 'CollectionPage',
            'name' => $name,
            'url' => $canonicalUrl,
            'inLanguage' => 'zh-CN',
        ];

        if ($description !== '') {
            $collection['description'] = $description;
        }

        if ($items !== []) {
            $collection['mainEntity'] = self::itemListSchema($items);
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::breadcrumbSchema($breadcrumbs),
                $collection,
            ],
        ];
    }

    /**
     * Product detail structured data: BreadcrumbList + Product (Offer / AggregateOffer).
     */
    public static function productSchema(Product $product, string $description, bool $buyable): array
    {
        $siteName = (string) setting('site_name', 'CardShop');
        $productUrl = self::url('/product/' . $product->slug);

        $breadcrumbs = [
            ['name' => '商品目录', 'url' => self::url('/')],
        ];
        if ($product->category) {
            $breadcrumbs[] = [
                'name' => (string) $product->category->name,
                'url' => self::url('/category/' . $product->category->slug),
            ];
        }
        $breadcrumbs[] = ['name' => (string) $product->name, 'url' => $productUrl];

        $effectiveDescription = $description !== ''
            ? $description
            : ($product->name . '，支持在线支付与卡密交付。');

        $tiers = $product->relationLoaded('wholesalePrices')
            ? $product->wholesalePrices
            : collect();

        $seller = [
            '@type' => 'Organization',
            'name' => $siteName,
        ];
        $availability = $buyable
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';

        if ($tiers->isNotEmpty()) {
            $prices = collect([(float) $product->price])
                ->merge($tiers->pluck('price')->map(fn ($p) => (float) $p));

            $minQty = max(1, (int) $product->min_quantity);
            $nestedOffers = [
                [
                    '@type' => 'Offer',
                    'url' => $productUrl,
                    'priceCurrency' => 'CNY',
                    'price' => number_format((float) $product->price, 2, '.', ''),
                    'availability' => $availability,
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'seller' => $seller,
                    'eligibleQuantity' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => $minQty,
                    ],
                ],
            ];
            foreach ($tiers->sortBy('min_quantity') as $tier) {
                $nestedOffers[] = [
                    '@type' => 'Offer',
                    'url' => $productUrl,
                    'priceCurrency' => 'CNY',
                    'price' => number_format((float) $tier->price, 2, '.', ''),
                    'availability' => $availability,
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'seller' => $seller,
                    'eligibleQuantity' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => (int) $tier->min_quantity,
                    ],
                ];
            }

            $offers = [
                '@type' => 'AggregateOffer',
                'url' => $productUrl,
                'priceCurrency' => 'CNY',
                'lowPrice' => number_format((float) $prices->min(), 2, '.', ''),
                'highPrice' => number_format((float) $prices->max(), 2, '.', ''),
                'offerCount' => count($nestedOffers),
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => $seller,
                'offers' => $nestedOffers,
            ];
        } else {
            $offers = [
                '@type' => 'Offer',
                'url' => $productUrl,
                'priceCurrency' => 'CNY',
                'price' => number_format((float) $product->price, 2, '.', ''),
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => $seller,
            ];
        }

        $productEntity = [
            '@type' => 'Product',
            'name' => (string) $product->name,
            'description' => $effectiveDescription,
            'sku' => (string) $product->id,
            'mpn' => (string) $product->slug,
            'url' => $productUrl,
            'brand' => [
                '@type' => 'Brand',
                'name' => $siteName,
            ],
            'offers' => $offers,
        ];

        if ($product->category) {
            $productEntity['category'] = (string) $product->category->name;
        }

        $imageUrl = self::assetUrl($product->image) ?: self::assetUrl(setting('site_logo'));
        if ($imageUrl) {
            $productEntity['image'] = [$imageUrl];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::breadcrumbSchema($breadcrumbs),
                $productEntity,
            ],
        ];
    }

    /**
     * Article detail structured data: BreadcrumbList + Article.
     */
    public static function articleSchema(Article $article, string $description): array
    {
        $siteName = (string) setting('site_name', 'CardShop');
        $articleUrl = self::url('/articles/' . $article->slug);

        $breadcrumbs = [
            ['name' => '商品目录', 'url' => self::url('/')],
            ['name' => '帮助中心', 'url' => self::url('/articles')],
        ];
        if ($article->articleCategory) {
            $breadcrumbs[] = [
                'name' => (string) $article->articleCategory->name,
                'url' => self::url('/articles/category/' . $article->articleCategory->slug),
            ];
        }
        $breadcrumbs[] = ['name' => (string) $article->title, 'url' => $articleUrl];

        $publisher = [
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => self::url('/'),
        ];
        $logoUrl = self::assetUrl(setting('site_logo'));
        if ($logoUrl) {
            $publisher['logo'] = [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
            ];
        }

        $articleEntity = [
            '@type' => 'Article',
            'headline' => (string) $article->title,
            'description' => $description !== '' ? $description : (string) $article->title,
            'url' => $articleUrl,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $articleUrl,
            ],
            'inLanguage' => 'zh-CN',
            'datePublished' => $article->created_at?->toIso8601String(),
            'dateModified' => ($article->updated_at ?: $article->created_at)?->toIso8601String(),
            'author' => [
                '@type' => 'Organization',
                'name' => $siteName,
                'url' => self::url('/'),
            ],
            'publisher' => $publisher,
        ];

        $coverUrl = self::assetUrl($article->cover_image) ?: $logoUrl;
        if ($coverUrl) {
            $articleEntity['image'] = [$coverUrl];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::breadcrumbSchema($breadcrumbs),
                $articleEntity,
            ],
        ];
    }
}
