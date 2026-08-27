<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWholesalePrice;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('category')
            ->withCount(['cards as stock_count' => fn ($q) => $q->where('status', 'unsold')]);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        if ($request->filled('keyword')) {
            $query->where('name', 'ilike', '%' . $request->keyword . '%');
        }

        $products = $query->ordered()->paginate($request->get('pageSize', 20));
        $categories = Category::ordered()->get(['id', 'name']);

        return response()->json([
            'data' => $products->items(),
            'total' => $products->total(),
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'slug' => 'nullable|string|max:200|unique:products,slug',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0.01',
            'min_quantity' => 'nullable|integer|min:1',
            'max_quantity' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*.min_quantity' => 'required_with:wholesale_prices|integer|min:2',
            'wholesale_prices.*.price' => 'required_with:wholesale_prices|numeric|min:0.01',
        ]);

        $wholesalePrices = $data['wholesale_prices'] ?? [];
        unset($data['wholesale_prices']);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $product = Product::create($data);

        foreach ($wholesalePrices as $wp) {
            ProductWholesalePrice::create([
                'product_id' => $product->id,
                'min_quantity' => $wp['min_quantity'],
                'price' => $wp['price'],
            ]);
        }

        OperationLog::log('创建商品', 'product', $product->id, $product->name);

        return response()->json($product->load('wholesalePrices'), 201);
    }

    public function show(Product $product)
    {
        $product->load(['category', 'wholesalePrices']);
        $product->loadCount(['cards as stock_count' => fn ($q) => $q->where('status', 'unsold')]);
        return response()->json($product);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'slug' => 'nullable|string|max:200|unique:products,slug,' . $product->id,
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0.01',
            'min_quantity' => 'nullable|integer|min:1',
            'max_quantity' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*.min_quantity' => 'required_with:wholesale_prices|integer|min:2',
            'wholesale_prices.*.price' => 'required_with:wholesale_prices|numeric|min:0.01',
        ]);

        $wholesalePrices = $data['wholesale_prices'] ?? [];
        unset($data['wholesale_prices']);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $product->update($data);
        $product->wholesalePrices()->delete();

        foreach ($wholesalePrices as $wp) {
            ProductWholesalePrice::create([
                'product_id' => $product->id,
                'min_quantity' => $wp['min_quantity'],
                'price' => $wp['price'],
            ]);
        }

        OperationLog::log('更新商品', 'product', $product->id, $product->name);

        return response()->json($product->load('wholesalePrices'));
    }

    public function destroy(Product $product)
    {
        if ($product->orders()->count() > 0) {
            return response()->json(['message' => '该商品有关联订单，无法删除。'], 422);
        }

        $product->cards()->delete();
        $product->wholesalePrices()->delete();

        OperationLog::log('删除商品', 'product', $product->id, $product->name);
        $product->delete();

        return response()->json(['message' => 'ok']);
    }
}
