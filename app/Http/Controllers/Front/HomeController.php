<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Middleware\NoIndex;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Support\Seo;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['q' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1']);
        $categories = Category::active()
            ->ordered()
            ->withCount(['products' => function ($query) {
                $query->active();
            }])
            ->get();

        $products = Product::active()
            ->when($request->filled('q'), function ($query) use ($request) {
                $pattern = \App\Support\SqlLike::contains((string) $request->input('q'));
                $query->where(fn ($q) => $q->where('name', 'ilike', $pattern)
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'ilike', $pattern)));
            })
            ->ordered()
            ->withStock()
            ->with(['category', 'wholesalePrices'])
            ->paginate(24)->withQueryString();

        if ($request->filled('q') || ($products->currentPage() > 1 && $products->isEmpty())) {
            NoIndex::mark($request, NoIndex::SEARCH_DIRECTIVE);
        }

        $groupedProducts = $products->getCollection()->groupBy('category_id');
        $catalogTotal = $request->filled('q') ? Product::active()->count() : $products->total();

        $latestArticles = Article::published()->recent()->limit(5)->get();
        // views 全为 0 是新站的常态，并列时顺序同样不稳定，补一个 tiebreaker。
        $recommendedArticles = Article::published()
            ->orderByDesc('views')->orderByDesc('id')->limit(5)->get();

        $siteName = setting('site_name', 'CardShop');
        $siteDescription = setting('site_description', '');
        $siteAnnouncement = setting('site_announcement', '');

        $baseTitle = (string) (setting('seo_default_title') ?: $siteName);
        $seoTitle = $request->filled('q')
            ? ('搜索「' . $request->string('q') . '」 - ' . $siteName)
            : Seo::paginationTitle($baseTitle, $products);
        $seoCanonical = Seo::paginationCanonical(Seo::url('/'), $products);

        return theme_view('home', compact(
            'categories',
            'products',
            'groupedProducts',
            'catalogTotal',
            'latestArticles',
            'recommendedArticles',
            'siteName',
            'siteDescription',
            'siteAnnouncement',
            'seoTitle',
            'seoCanonical',
        ));
    }
}
