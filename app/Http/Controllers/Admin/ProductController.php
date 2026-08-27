<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductWholesalePrice;
use App\Models\OperationLog;
use App\Http\Requests\Admin\ProductRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('category')
            ->withCount(['cards as stock_count' => fn($q) => $q->where('status', 'unsold')]);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status == 1);
        }

        $products = $query->ordered()->paginate(20)->appends($request->query());
        $categories = Category::ordered()->get();

        return view('admin.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        $product = null;
        $categories = Category::ordered()->get();

        return view('admin.products.form', compact('categories', 'product'));
    }

    public function store(ProductRequest $request)
    {
        $data = $request->validated();
        $wholesalePrices = $data['wholesale_prices'] ?? null;
        unset($data['wholesale_prices']);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $product = Product::create($data);

        if ($request->has('wholesale_prices') && is_array($wholesalePrices)) {
            foreach ($wholesalePrices as $wp) {
                ProductWholesalePrice::create([
                    'product_id' => $product->id,
                    'min_quantity' => $wp['min_quantity'],
                    'price' => $wp['price'],
                ]);
            }
        }

        OperationLog::log('创建商品', 'product', $product->id, $product->name);

        return redirect()->route('admin.products.index')->with('success', '商品创建成功。');
    }

    public function edit(Product $product)
    {
        $product->load('wholesalePrices');
        $categories = Category::ordered()->get();

        return view('admin.products.form', compact('product', 'categories'));
    }

    public function update(ProductRequest $request, Product $product)
    {
        $data = $request->validated();
        $wholesalePrices = $data['wholesale_prices'] ?? null;
        unset($data['wholesale_prices']);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $product->update($data);

        $product->wholesalePrices()->delete();

        if ($request->has('wholesale_prices') && is_array($wholesalePrices)) {
            foreach ($wholesalePrices as $wp) {
                ProductWholesalePrice::create([
                    'product_id' => $product->id,
                    'min_quantity' => $wp['min_quantity'],
                    'price' => $wp['price'],
                ]);
            }
        }

        OperationLog::log('更新商品', 'product', $product->id, $product->name);

        return redirect()->route('admin.products.index')->with('success', '商品更新成功。');
    }

    public function destroy(Product $product)
    {
        $orderCount = $product->orders()->count();

        if ($orderCount > 0) {
            return redirect()->back()->with('error', "该商品有 {$orderCount} 个关联订单，无法删除。");
        }

        $product->cards()->delete();
        $product->wholesalePrices()->delete();
        $product->delete();

        OperationLog::log('删除商品', 'product', $product->id, $product->name);

        return redirect()->back()->with('success', '商品删除成功。');
    }
}
