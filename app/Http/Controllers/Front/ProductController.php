<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use League\CommonMark\CommonMarkConverter;

class ProductController extends Controller
{
    public function category(string $slug)
    {
        $category = Category::active()->where('slug', $slug)->firstOrFail();

        $products = Product::active()
            ->ordered()
            ->where('category_id', $category->id)
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

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $descriptionHtml = $product->description
            ? $converter->convert($product->description)->getContent()
            : '';

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
