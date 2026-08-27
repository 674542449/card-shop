<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $perPage = min((int) $request->input('per_page', 15), 100);

        $products = Product::with('category')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
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
                'stock' => $this->cardService->getStockCount($product->id),
                'created_at' => $product->created_at->toIso8601String(),
            ];
        });

        return response()->json($products);
    }

    /**
     * Return a single product with wholesale prices and stock count.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::with(['category', 'wholesalePrices'])
            ->where('id', $id)
            ->where('is_active', true)
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
                'stock' => $this->cardService->getStockCount($product->id),
                'created_at' => $product->created_at->toIso8601String(),
                'updated_at' => $product->updated_at->toIso8601String(),
            ],
        ]);
    }
}
