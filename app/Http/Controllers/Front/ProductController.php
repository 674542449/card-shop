<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\ContentRenderer;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function category(string $slug)
    {
        $category = Category::active()->where('slug', $slug)->firstOrFail();

        $products = Product::active()
            ->ordered()
            ->where('category_id', $category->id)
            ->withStock()
            ->with(['category', 'wholesalePrices'])
            ->paginate(12);

        $seoTitle = $category->name . ' - ' . setting('site_name', 'CardShop');
        $seoDescription = $category->description ?: setting('seo_default_description', '');
        $seoKeywords = setting('seo_default_keywords', '');

        return view('front.product.list', compact(
            'category',
            'products',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
        ));
    }

    public function show(string $slug)
    {
        $product = Product::active()
            ->where('slug', $slug)
            ->with(['category', 'wholesalePrices' => function ($query) {
                $query->orderBy('min_quantity');
            }])
            ->firstOrFail();

        $stockCount = $product->stockCount();

        $descriptionHtml = ContentRenderer::toHtml($product->description);

        $seoTitle = ($product->seo_title ?: $product->name) . ' - ' . setting('site_name', 'CardShop');
        $seoDescription = $product->seo_description ?: setting('seo_default_description', '');
        $seoKeywords = $product->seo_keywords ?: setting('seo_default_keywords', '');

        return view('front.product.show', compact(
            'product',
            'stockCount',
            'descriptionHtml',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
        ));
    }
}
