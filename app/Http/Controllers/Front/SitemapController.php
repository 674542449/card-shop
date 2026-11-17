<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Seo;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $categories = Category::active()
            ->whereHas('products', fn ($q) => $q->where('products.is_active', true))
            ->withMax(['products as latest_product_updated_at' => fn ($q) => $q->where('products.is_active', true)], 'updated_at')
            ->ordered()
            ->get();
        $products = Product::active()->ordered()->get();
        $articles = Article::published()->recent()->get();
        $articleCategories = ArticleCategory::whereHas('articles', fn ($q) => $q->published())
            ->withMax(['articles as latest_article_updated_at' => fn ($q) => $q->published()], 'updated_at')
            ->ordered()
            ->get();

        $homeLastMod = $this->latestIso([
            $products->max('updated_at'),
            $categories->max('updated_at'),
            $articles->max('updated_at'),
            Setting::max('updated_at'),
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

        // Homepage
        $xml .= $this->buildUrl(Seo::url('/'), $homeLastMod);

        // Category pages (only categories with active products)
        foreach ($categories as $category) {
            $xml .= $this->buildUrl(
                Seo::url('/category/' . $category->slug),
                $this->latestIso([$category->updated_at, $category->latest_product_updated_at]),
            );
        }

        // Product pages (with Google Image Sitemap entries for cover & inline diagrams)
        foreach ($products as $product) {
            $xml .= $this->buildUrl(
                Seo::url('/product/' . $product->slug),
                $this->latestIso([$product->updated_at]),
                $this->extractImages($product->image, $product->description, (string) $product->name),
            );
        }

        // Article list
        $xml .= $this->buildUrl(Seo::url('/articles'), $this->latestIso([$articles->max('updated_at')]));

        // Article category & detail pages
        foreach ($articleCategories as $articleCategory) {
            $xml .= $this->buildUrl(
                Seo::url('/articles/category/' . $articleCategory->slug),
                $this->latestIso([$articleCategory->updated_at, $articleCategory->latest_article_updated_at]),
            );
        }
        foreach ($articles as $article) {
            $xml .= $this->buildUrl(
                Seo::url('/articles/' . $article->slug),
                $this->latestIso([$article->updated_at]),
                $this->extractImages($article->cover_image, $article->content, (string) $article->title),
            );
        }

        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * Served by the app rather than as a static file: the Sitemap line must be an
     * absolute URL, and APP_URL is the only place the real domain is known.
     *
     * /order/ and /payment/ are deliberately not disallowed. They carry noindex
     * (see NoIndex), and a crawler that may not fetch a page never sees it. The
     * admin path is not listed either: naming it here would publish the location
     * ADMIN_PATH exists to hide, and its shell already sends noindex.
     */
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /api/',
            '',
            'Sitemap: '.Seo::url('/sitemap.xml'),
            '',
        ];

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function latestIso(array $candidates): ?string
    {
        $latest = null;
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $ts = $candidate instanceof Carbon ? $candidate : Carbon::parse((string) $candidate);
            if ($latest === null || $ts->gt($latest)) {
                $latest = $ts;
            }
        }

        return $latest?->toIso8601String();
    }

    /**
     * @return list<array{loc: string, title: string}>
     */
    private function extractImages(?string $primaryImage, ?string $htmlBody, string $defaultTitle): array
    {
        $seen = [];
        $images = [];

        $primaryUrl = Seo::assetUrl($primaryImage);
        if ($primaryUrl !== null) {
            $seen[$primaryUrl] = true;
            $images[] = ['loc' => $primaryUrl, 'title' => $defaultTitle];
        }

        if (is_string($htmlBody) && str_contains($htmlBody, '<img')) {
            if (preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $htmlBody, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $imgUrl = Seo::assetUrl($match[1]);
                    if ($imgUrl === null || isset($seen[$imgUrl])) {
                        continue;
                    }
                    $seen[$imgUrl] = true;
                    $alt = $defaultTitle;
                    if (preg_match('/\balt=["\']([^"\']+)["\']/i', $match[0], $altMatch)) {
                        $decodedAlt = trim(html_entity_decode($altMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if ($decodedAlt !== '') {
                            $alt = $decodedAlt;
                        }
                    }
                    $images[] = ['loc' => $imgUrl, 'title' => $alt];
                    if (count($images) >= 10) {
                        break;
                    }
                }
            }
        }

        return $images;
    }

    /**
     * @param  list<array{loc: string, title: string}>  $images
     */
    private function buildUrl(string $loc, ?string $lastmod = null, array $images = []): string
    {
        $imageXml = '';
        foreach ($images as $img) {
            $imageXml .= '<image:image>'
                . '<image:loc>' . htmlspecialchars($img['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</image:loc>'
                . ($img['title'] !== '' ? '<image:title>' . htmlspecialchars($img['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</image:title>' : '')
                . '</image:image>';
        }

        return '<url>'
            . '<loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
            . ($lastmod !== null ? '<lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>' : '')
            . $imageXml
            . '</url>';
    }
}
