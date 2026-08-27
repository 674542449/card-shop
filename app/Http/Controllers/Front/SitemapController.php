<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $categories = Category::active()->ordered()->get();
        $products = Product::active()->ordered()->get();
        $articles = Article::published()->recent()->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // Homepage
        $xml .= $this->buildUrl(url('/'), now()->toIso8601String(), 'daily', '1.0');

        // Category pages
        foreach ($categories as $category) {
            $xml .= $this->buildUrl(
                url('/category/' . $category->slug),
                $category->updated_at->toIso8601String(),
                'weekly',
                '0.8'
            );
        }

        // Product pages
        foreach ($products as $product) {
            $xml .= $this->buildUrl(
                url('/product/' . $product->slug),
                $product->updated_at->toIso8601String(),
                'daily',
                '0.9'
            );
        }

        // Article list
        $xml .= $this->buildUrl(url('/articles'), now()->toIso8601String(), 'weekly', '0.7');

        // Article pages
        foreach ($articles as $article) {
            $xml .= $this->buildUrl(
                url('/articles/' . $article->slug),
                $article->updated_at->toIso8601String(),
                'weekly',
                '0.6'
            );
        }

        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    private function buildUrl(string $loc, string $lastmod, string $changefreq, string $priority): string
    {
        return '<url>'
            . '<loc>' . htmlspecialchars($loc) . '</loc>'
            . '<lastmod>' . $lastmod . '</lastmod>'
            . '<changefreq>' . $changefreq . '</changefreq>'
            . '<priority>' . $priority . '</priority>'
            . '</url>';
    }
}
