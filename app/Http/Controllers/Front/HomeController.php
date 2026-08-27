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

        $query = Product::active()->ordered()->with(['category', 'wholesalePrices']);

        if ($request->filled('category')) {
            $category = Category::where('slug', $request->input('category'))->first();
            if ($category) {
                $query->where('category_id', $category->id);
            }
        }

        $products = $query->paginate(12)->withQueryString();

        $articles = Article::published()->recent()->limit(3)->get();

        $siteName = setting('site_name', 'CardShop');
        $siteDescription = setting('site_description', '');
        $siteAnnouncement = setting('site_announcement', '');

        return view('front.home', compact(
            'categories',
            'products',
            'articles',
            'siteName',
            'siteDescription',
            'siteAnnouncement',
        ));
    }
}
