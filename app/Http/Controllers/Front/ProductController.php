<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Middleware\NoIndex;
use App\Models\Category;
use App\Models\Product;
use App\Support\ContentRenderer;
use App\Support\Seo;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function category(Request $request, string $slug)
    {
        $request->validate(['q' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1']);
        $category = Category::active()->where('slug', $slug)->firstOrFail();

        $products = Product::active()
            ->ordered()
            ->where('category_id', $category->id)
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'ilike', '%'.$request->q.'%'))
            ->withStock()
            ->with(['category', 'wholesalePrices'])
            ->paginate(12)->withQueryString();

        if ($request->filled('q') || ($products->currentPage() > 1 && $products->isEmpty())) {
            NoIndex::mark($request, NoIndex::SEARCH_DIRECTIVE);
        }

        $seoTitle = ($request->filled('q')
            ? ('搜索「' . $request->string('q') . '」 - ' . $category->name)
            : Seo::paginationTitle($category->name, $products))
            . ' - ' . setting('site_name', 'CardShop');
        $seoDescription = $category->description
            ?: setting('seo_default_description', '')
            ?: setting('site_description', '');
        $seoKeywords = setting('seo_default_keywords', '');
        $seoCanonical = Seo::paginationCanonical(Seo::url('/category/' . $category->slug), $products);

        return theme_view('product.list', compact(
            'category',
            'products',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
            'seoCanonical',
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
        $seoDescription = $product->seo_description
            ?: Seo::excerpt($descriptionHtml, 160)
            ?: setting('seo_default_description', '')
            ?: setting('site_description', '');
        $seoKeywords = $product->seo_keywords ?: setting('seo_default_keywords', '');

        return theme_view('product.show', compact(
            'product',
            'stockCount',
            'descriptionHtml',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
        ));
    }
}
