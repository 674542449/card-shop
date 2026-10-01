<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function __construct(
        private readonly CardService $cardService,
    ) {}

    /**
     * Return paginated list of active products with category and stock count.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $perPage = (int) $request->input('per_page', 15);

        $products = Product::active()->withStock()->with('category')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);

        $products->getCollection()->transform(function (Product $product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'price' => $product->price,
                'min_quantity' => $product->min_quantity,
                'max_quantity' => $product->max_quantity,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
                'stock' => $product->stockCount(),
                'created_at' => $product->created_at->toIso8601String(),
            ];
        });

        return response()->json($products);
    }

    /**
     * Return a single product with wholesale prices and stock count.
     */
    public function show(string $id): JsonResponse
    {
        // whereNumber rejects nonnumeric paths; validate the integer range before
        // any PHP coercion or database query so huge digit strings cannot throw.
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:'.PHP_INT_MAX])->validate();
        $product = Product::active()->withStock()->with(['category', 'wholesalePrices'])
            ->where('id', (int) $id)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => '商品不存在或已下架',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'price' => $product->price,
                'min_quantity' => $product->min_quantity,
                'max_quantity' => $product->max_quantity,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
                'wholesale_prices' => $product->wholesalePrices->map(fn ($wp) => [
                    'min_quantity' => $wp->min_quantity,
                    'price' => $wp->price,
                ])->toArray(),
                'stock' => $product->stockCount(),
                'created_at' => $product->created_at->toIso8601String(),
                'updated_at' => $product->updated_at->toIso8601String(),
            ],
        ]);
    }
}
