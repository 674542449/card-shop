<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeoService
{
    /**
     * Get SEO meta tags for a given page type.
     *
     * @param string     $type  Page type: 'home', 'product', 'article', 'category'.
     * @param mixed|null $model The model instance (Product, Article, Category).
     * @return array{title: string, description: string, keywords: string}
     */
    public function getMeta(string $type, mixed $model = null): array
    {
        $siteName = (string) setting('site_name', '卡密商城');

        return match ($type) {
            'home' => [
                'title' => (string) setting('seo_title', $siteName),
                'description' => (string) setting('seo_description', ''),
                'keywords' => (string) setting('seo_keywords', ''),
            ],
            'product' => $model instanceof Product ? [
                'title' => ($model->seo_title ?: $model->name) . " - {$siteName}",
                'description' => $model->seo_description ?: mb_substr(strip_tags((string) $model->description), 0, 160),
                'keywords' => $model->seo_keywords ?: '',
            ] : $this->getMeta('home'),
            'article' => $model instanceof Article ? [
                'title' => ($model->seo_title ?: $model->title) . " - {$siteName}",
                'description' => $model->seo_description ?: mb_substr(strip_tags((string) $model->summary), 0, 160),
                'keywords' => $model->seo_keywords ?: '',
            ] : $this->getMeta('home'),
            'category' => $model instanceof Category ? [
                'title' => "{$model->name} - {$siteName}",
                'description' => mb_substr(strip_tags((string) $model->description), 0, 160),
                'keywords' => $model->name,
            ] : $this->getMeta('home'),
            default => $this->getMeta('home'),
        };
    }

    /**
     * Generate a sitemap XML string with all active products, published articles, and categories.
     */
    public function generateSitemap(): string
    {
        $baseUrl = rtrim((string) setting('site_url', config('app.url', 'https://example.com')), '/');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Home page
        $xml .= $this->sitemapEntry($baseUrl . '/', now()->toDateString(), 'daily', '1.0');

        // Categories
        $categories = Category::where('is_active', true)->orderBy('sort_order')->get();
        foreach ($categories as $category) {
            $xml .= $this->sitemapEntry(
                "{$baseUrl}/category/{$category->slug}",
                $category->updated_at->toDateString(),
                'weekly',
                '0.8'
            );
        }

        // Products
        $products = Product::where('is_active', true)->orderBy('sort_order')->get();
        foreach ($products as $product) {
            $xml .= $this->sitemapEntry(
                "{$baseUrl}/product/{$product->slug}",
                $product->updated_at->toDateString(),
                'daily',
                '0.9'
            );
        }

        // Articles
        $articles = Article::where('is_published', true)->orderByDesc('created_at')->get();
        foreach ($articles as $article) {
            $xml .= $this->sitemapEntry(
                "{$baseUrl}/articles/{$article->slug}",
                $article->updated_at->toDateString(),
                'weekly',
                '0.7'
            );
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Push URLs to Baidu for indexing.
     */
    public function pushToBaidu(array $urls): bool
    {
        $token = setting('baidu_push_token');
        $site = setting('site_url', config('app.url'));

        if (empty($token) || empty($site) || empty($urls)) {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withBody(implode("\n", $urls), 'text/plain')
                ->post("http://data.zz.baidu.com/urls?site={$site}&token={$token}");

            if (!$response->successful()) {
                Log::warning('Baidu push failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Baidu push exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Push URLs to Bing via IndexNow protocol.
     */
    public function pushToIndexNow(array $urls): bool
    {
        $apiKey = setting('bing_indexnow_key');
        $host = parse_url((string) setting('site_url', config('app.url')), PHP_URL_HOST);

        if (empty($apiKey) || empty($host) || empty($urls)) {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->post('https://api.indexnow.org/indexnow', [
                    'host' => $host,
                    'key' => $apiKey,
                    'urlList' => $urls,
                ]);

            if (!$response->successful()) {
                Log::warning('IndexNow push failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('IndexNow push exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Generate a single sitemap URL entry.
     */
    private function sitemapEntry(string $loc, string $lastmod, string $changefreq, string $priority): string
    {
        $escapedLoc = htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "  <url>\n"
            . "    <loc>{$escapedLoc}</loc>\n"
            . "    <lastmod>{$lastmod}</lastmod>\n"
            . "    <changefreq>{$changefreq}</changefreq>\n"
            . "    <priority>{$priority}</priority>\n"
            . "  </url>\n";
    }
}
