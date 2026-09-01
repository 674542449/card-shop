<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::active()
            ->ordered()
            ->withCount(['products' => function ($query) {
                $query->active();
            }])
            ->get();

        $products = Product::active()
            ->ordered()
            ->withStock()
            ->with(['category', 'wholesalePrices'])
            ->get();

        $groupedProducts = $products->groupBy('category_id');

        $latestArticles = Article::published()->recent()->limit(5)->get();
        // views 全为 0 是新站的常态，并列时顺序同样不稳定，补一个 tiebreaker。
        $recommendedArticles = Article::published()
            ->orderByDesc('views')->orderByDesc('id')->limit(5)->get();

        $siteName = setting('site_name', 'CardShop');
        $siteDescription = setting('site_description', '');
        $siteAnnouncement = setting('site_announcement', '');

        return theme_view('home', compact(
            'categories',
            'products',
            'groupedProducts',
            'latestArticles',
            'recommendedArticles',
            'siteName',
            'siteDescription',
            'siteAnnouncement',
        ));
    }
}
