<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWholesalePrice;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use App\Support\SlugGenerator;
use App\Support\AdminListQuery;
use Illuminate\Support\Facades\DB;
use App\Policies\AdminPolicy;

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

    /**
     * Enforces wholesale pricing invariants so tier prices can never invert above
     * the base unit price or charge more for larger quantities.
     */
    private function validateWholesalePricing(array $data, bool $replaceTiers = true, ?Product $existing = null): void
    {
        $basePrice = bcadd((string) $data['price'], '0', 2);

        if (! $replaceTiers) {
            if ($existing) {
                foreach ($existing->wholesalePrices()->get(['price']) as $tier) {
                    if (bccomp(bcadd((string) $tier->price, '0', 2), $basePrice, 2) > 0) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'price' => '商品售价不能低于现有批发阶梯价，请同步调整或清空批发价。',
                        ]);
                    }
                }
            }

            return;
        }

        $tiers = $data['wholesale_prices'] ?? [];
        if ($tiers === []) {
            return;
        }

        $errors = [];
        $indexed = [];
        foreach ($tiers as $index => $tier) {
            $tierPrice = bcadd((string) $tier['price'], '0', 2);
            if (bccomp($tierPrice, $basePrice, 2) > 0) {
                $errors["wholesale_prices.{$index}.price"][] = '批发阶梯单价不能高于商品基础售价。';
            }
            $indexed[] = [
                'index' => $index,
                'min_quantity' => (int) $tier['min_quantity'],
                'price' => $tierPrice,
            ];
        }

        usort($indexed, fn (array $a, array $b) => $a['min_quantity'] <=> $b['min_quantity']);
        $previousPrice = null;
        foreach ($indexed as $item) {
            if ($previousPrice !== null && bccomp($item['price'], $previousPrice, 2) > 0) {
                $errors["wholesale_prices.{$item['index']}.price"][] = '高数量阶梯单价不能高于低数量阶梯单价。';
            }
            $previousPrice = $item['price'];
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'category_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'keyword' => 'nullable|string|max:200',
            'name' => 'nullable|string|max:200',
            'low_stock' => 'nullable|boolean',
        ]);
        $query = Product::with('category')
            ->withCount(['cards as stock_count' => fn ($q) => $q->where('status', 'unsold')]);

        if ($request->boolean('low_stock')) {
            $query->where('is_active', true)->whereNotNull('low_stock_threshold')
                ->whereRaw('(select count(*) from cards where cards.product_id = products.id and cards.status = ?) <= products.low_stock_threshold', ['unsold']);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // The table's search form submits the column name (`name`); accept both.
        $keyword = $request->input('keyword', $request->input('name'));
        if (filled($keyword)) {
            $query->where('name', 'ilike', \App\Support\SqlLike::contains((string) $keyword));
        }

        $products = $query->ordered()->paginate($pageSize);
        $categories = Category::ordered()->get(['id', 'name']);

        return response()->json([
            'data' => $products->items(),
            'total' => $products->total(),
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'min_quantity' => $request->input('min_quantity') ?? 1,
            'max_quantity' => $request->input('max_quantity') ?? 10,
            'sort_order' => $request->input('sort_order') ?? 0,
        ]);
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'slug' => 'nullable|string|max:200|regex:/^[\pL\pN_-]+$/u|unique:products,slug',
            'category_id' => 'required|integer|min:1|exists:categories,id',
            'description' => 'nullable|string|max:1000000',
            'image' => ['nullable', 'string', 'max:500', \App\Support\SafeUrl::assetRule()],
            'price' => 'required|numeric|min:0.01|decimal:0,2|max:99999999.99',
            'low_stock_threshold' => 'sometimes|nullable|integer|min:0|max:100000',
            'min_quantity' => 'nullable|integer|min:1|max:2147483647',
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:2147483647', $this->maxNotBelowMin($request)],
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:-2147483648|max:2147483647',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*.min_quantity' => 'required_with:wholesale_prices|integer|min:2|max:2147483647|distinct',
            'wholesale_prices.*.price' => 'required_with:wholesale_prices|numeric|min:0.01|decimal:0,2|max:99999999.99',
        ]);

        $this->validateWholesalePricing($data);
        $wholesalePrices = $data['wholesale_prices'] ?? [];
        unset($data['wholesale_prices']);

        $product = SlugGenerator::persist('products', $data, $data['name'], function ($values) use ($wholesalePrices) {
            if (! Category::whereKey($values['category_id'])->sharedLock()->first(['id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['category_id' => '商品分类已删除，请重新选择。']);
            }
            $product = Product::create($values);
            foreach ($wholesalePrices as $wp) {
                $product->wholesalePrices()->create([
                    'min_quantity' => $wp['min_quantity'],
                    'price' => $wp['price'],
                ]);
            }
            return $product;
        });

        $product->load(['category', 'wholesalePrices']);
        app(\App\Services\NotificationQueue::class)->enqueueProductListed($product, 'created');

        OperationLog::log('创建商品', 'product', $product->id, $product->name);

        return response()->json($product, 201);
    }

    public function show(Product $product)
    {
        $product->load(['category', 'wholesalePrices']);
        $product->loadCount(['cards as stock_count' => fn ($q) => $q->where('status', 'unsold')]);
        return response()->json($product);
    }

    public function update(Request $request, Product $product)
    {
        $request->merge([
            'min_quantity' => $request->input('min_quantity') ?? $product->min_quantity,
            'max_quantity' => $request->input('max_quantity') ?? $product->max_quantity,
            'sort_order' => $request->input('sort_order') ?? $product->sort_order,
        ]);
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'slug' => 'nullable|string|max:200|regex:/^[\pL\pN_-]+$/u|unique:products,slug,' . $product->id,
            'category_id' => 'required|integer|min:1|exists:categories,id',
            'description' => 'nullable|string|max:1000000',
            'image' => ['nullable', 'string', 'max:500', \App\Support\SafeUrl::assetRule()],
            'price' => 'required|numeric|min:0.01|decimal:0,2|max:99999999.99',
            'low_stock_threshold' => 'sometimes|nullable|integer|min:0|max:100000',
            'min_quantity' => 'nullable|integer|min:1|max:2147483647',
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:2147483647', $this->maxNotBelowMin($request)],
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:-2147483648|max:2147483647',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*.min_quantity' => 'required_with:wholesale_prices|integer|min:2|max:2147483647|distinct',
            'wholesale_prices.*.price' => 'required_with:wholesale_prices|numeric|min:0.01|decimal:0,2|max:99999999.99',
        ]);

        // Only touch the tiers when the client actually submitted the field. Deleting
        // unconditionally means any edit from a form without a wholesale section
        // silently wipes every tier the product had.
        $replaceTiers = $request->has('wholesale_prices');
        $this->validateWholesalePricing($data, $replaceTiers, $product);
        $wholesalePrices = $data['wholesale_prices'] ?? [];
        unset($data['wholesale_prices']);

        $product->loadMissing('category');
        $wasListed = $product->is_active && (!$product->category || $product->category->is_active);

        SlugGenerator::persist('products', $data, $data['name'], function ($values) use ($product, $replaceTiers, $wholesalePrices) {
            if (! Category::whereKey($values['category_id'])->sharedLock()->first(['id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['category_id' => '商品分类已删除，请重新选择。']);
            }
            if (! $replaceTiers) {
                $this->validateWholesalePricing($values, false, $product);
            }
            $product->fill($values);
            if ($product->isDirty(['low_stock_threshold', 'is_active'])) {
                $product->forceFill(['low_stock_notified' => false]);
            }
            $product->save();

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
        }, $product->id);

        $product->unsetRelation('category')->load(['category', 'wholesalePrices']);
        $isNowListed = $product->is_active && (!$product->category || $product->category->is_active);
        if (!$wasListed && $isNowListed) {
            app(\App\Services\NotificationQueue::class)->enqueueProductListed($product, 'activated');
        }

        OperationLog::log('更新商品', 'product', $product->id, $product->name);

        return response()->json($product);
    }

    public function batchUpdateDescription(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|integer|min:1|distinct',
            'description' => 'nullable|string|max:1000000',
        ]);

        $description = $data['description'] ?? null;
        if (is_string($description) && trim($description) === '') {
            $description = null;
        }

        $updatedIds = DB::transaction(function () use ($data, $description) {
            $products = Product::whereIn('id', $data['ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($products->isEmpty()) {
                return [];
            }

            $ids = [];
            foreach ($products as $product) {
                $product->description = $description;
                $product->save();
                $ids[] = $product->id;
            }

            return $ids;
        });

        if ($updatedIds === []) {
            return response()->json(['message' => '未找到可更新的商品，请刷新列表后重试。'], 422);
        }

        $count = count($updatedIds);
        $previewIds = implode(', ', array_slice($updatedIds, 0, 20)) . ($count > 20 ? '…' : '');
        OperationLog::log('批量修改商品介绍', 'product', null, "批量统一更新 {$count} 个商品介绍（ID: {$previewIds}）");

        return response()->json([
            'message' => "已成功统一修改 {$count} 个商品的商品介绍。",
            'count' => $count,
        ]);
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

            // Lock all inventory before checking it. A simultaneous offline sale
            // must not slip between the check and the product's cascading delete.
            $protectedInventory = false;
            $locked->cards()->select(['id', 'status', 'order_id', 'replaced_at'])->lockForUpdate()
                ->chunkById(500, function ($inventory) use (&$protectedInventory) {
                    AdminPolicy::authorize(request()->attributes->get('admin'), 'cards:write');
                    $protectedInventory = $inventory->contains(fn ($card) => $card->status !== 'unsold' || $card->order_id !== null || $card->replaced_at !== null);
                    return !$protectedInventory;
                });
            if ($protectedInventory) return false;

            // Scoped to unsold. A locked or sold card belongs to an order, and an
            // order is exactly what the guard above has just ruled out.
            $locked->cards()->where('status', 'unsold')->delete();
            $locked->wholesalePrices()->delete();
            $locked->delete();

            return true;
        });

        if (!$deleted) {
            return response()->json(['message' => '该商品有关联订单或停用、锁定、已售卡密，无法删除；请先核对库存或将商品下架。'], 422);
        }

        OperationLog::log('删除商品', 'product', $product->id, $product->name);

        return response()->json(['message' => 'ok']);
    }
}
