<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWholesalePrice;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Rejects max_quantity < min_quantity, which leaves the product with an empty
     * valid quantity range — the storefront then refuses every order for it with a
     * validation error the buyer can do nothing about.
     *
     * Written as a closure rather than `gte:min_quantity` on purpose: Laravel's gte
     * rule throws InvalidArgumentException (a 500, not a 422) when the compared field
     * is absent, and the admin form omits min_quantity whenever it is left blank.
     */
    private function maxNotBelowMin(Request $request): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($request) {
            $min = $request->input('min_quantity');

            if (is_numeric($min) && is_numeric($value) && (int) $value < (int) $min) {
                $fail('每单最大数量不能小于最小数量。');
            }
        };
    }

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

        // The table's search form submits the column name (`name`); accept both.
        $keyword = $request->input('keyword', $request->input('name'));
        if (filled($keyword)) {
            $query->where('name', 'ilike', '%' . $keyword . '%');
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
            'image' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0.01',
            'min_quantity' => 'nullable|integer|min:1',
            'max_quantity' => ['nullable', 'integer', 'min:1', $this->maxNotBelowMin($request)],
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
            $data['slug'] = SlugGenerator::unique($data['name'], 'products');
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
            'image' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0.01',
            'min_quantity' => 'nullable|integer|min:1',
            'max_quantity' => ['nullable', 'integer', 'min:1', $this->maxNotBelowMin($request)],
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*.min_quantity' => 'required_with:wholesale_prices|integer|min:2',
            'wholesale_prices.*.price' => 'required_with:wholesale_prices|numeric|min:0.01',
        ]);

        // Only touch the tiers when the client actually submitted the field. Deleting
        // unconditionally means any edit from a form without a wholesale section
        // silently wipes every tier the product had.
        $replaceTiers = $request->has('wholesale_prices');
        $wholesalePrices = $data['wholesale_prices'] ?? [];
        unset($data['wholesale_prices']);

        if (empty($data['slug'])) {
            $data['slug'] = SlugGenerator::unique($data['name'], 'products', $product->id);
        }

        DB::transaction(function () use ($product, $data, $replaceTiers, $wholesalePrices) {
            $product->update($data);

            if ($replaceTiers) {
                $product->wholesalePrices()->delete();

                foreach ($wholesalePrices as $wp) {
                    ProductWholesalePrice::create([
                        'product_id' => $product->id,
                        'min_quantity' => $wp['min_quantity'],
                        'price' => $wp['price'],
                    ]);
                }
            }
        });

        OperationLog::log('更新商品', 'product', $product->id, $product->name);

        return response()->json($product->load('wholesalePrices'));
    }

    public function destroy(Product $product)
    {
        $deleted = DB::transaction(function () use ($product) {
            // Re-check under a row lock. The count used to be read outside any
            // transaction and the cards deleted on their own: an order created in the
            // gap had its cards deleted out from under it, and then orders.product_id
            // (restrictOnDelete) aborted the product delete — leaving a live pending
            // order holding rows that no longer exist.
            $locked = Product::whereKey($product->id)->lockForUpdate()->first();

            if (!$locked || $locked->orders()->count() > 0) {
                return false;
            }

            // Scoped to unsold. A locked or sold card belongs to an order, and an
            // order is exactly what the guard above has just ruled out.
            $locked->cards()->where('status', 'unsold')->delete();
            $locked->wholesalePrices()->delete();
            $locked->delete();

            return true;
        });

        if (!$deleted) {
            return response()->json(['message' => '该商品有关联订单，无法删除。'], 422);
        }

        OperationLog::log('删除商品', 'product', $product->id, $product->name);

        return response()->json(['message' => 'ok']);
    }
}
