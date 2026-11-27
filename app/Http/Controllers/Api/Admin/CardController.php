<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Category;
use App\Models\Product;
use App\Models\OperationLog;
use App\Support\AdminListQuery;
use App\Services\CardService;
use App\Http\Resources\Admin\CardResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class CardController extends Controller
{
    public function all(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 50, [
            'status' => 'nullable|in:unsold,locked,sold,disabled',
            'content' => 'nullable|string|max:500',
            'category_id' => 'nullable|integer|min:1',
            'product_id' => 'nullable|integer|min:1',
        ]);

        $scopeQuery = $this->applyGlobalScopeFilters($request, Card::query());
        $listQuery = (clone $scopeQuery)->with([
            'order:id,order_no,status',
            'product:id,name,category_id',
            'product.category:id,name',
        ]);

        if ($request->filled('content')) {
            $listQuery->where('content_fingerprint', app(\App\Security\SecretCipher::class)->fingerprint(trim((string) $request->input('content'))));
        }

        if ($request->filled('status')) {
            $listQuery->where('status', $request->string('status')->toString());
        }

        $cards = $listQuery->orderByDesc('id')->paginate($pageSize);
        $cards->getCollection()->each(fn (Card $card) => $card->makeVisible('content'));

        return response()->json([
            'data' => CardResource::collection($cards->items())->resolve($request),
            'total' => $cards->total(),
            'stats' => $this->summarizeStats($scopeQuery),
            'global_stats' => $this->summarizeStats(Card::query()),
        ]);
    }

    public function productOptions()
    {
        $products = Product::query()
            ->with('category:id,name')
            ->withCount([
                'cards as stock_count' => fn (Builder $q) => $q->where('status', 'unsold'),
                'cards as disabled_count' => fn (Builder $q) => $q->where('status', 'disabled'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'category_id', 'is_active']);

        return response()->json([
            'data' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'category_id' => $product->category_id,
                'category_name' => $product->category?->name,
                'is_active' => (bool) $product->is_active,
                'stock_count' => (int) ($product->stock_count ?? 0),
                'disabled_count' => (int) ($product->disabled_count ?? 0),
            ])->values(),
        ]);
    }

    public function bulkAction(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|in:pause_unsold,resume_disabled,delete_unsold,delete_disabled',
            'scope' => 'required|in:all,category,products,cards',
            'category_id' => 'required_if:scope,category|nullable|integer|exists:categories,id',
            'product_ids' => 'required_if:scope,products|nullable|array|min:1|max:500',
            'product_ids.*' => 'integer|min:1',
            'card_ids' => 'required_if:scope,cards|nullable|array|min:1|max:500',
            'card_ids.*' => 'integer|min:1',
        ]);

        $action = (string) $data['action'];
        $scope = (string) $data['scope'];

        $result = DB::transaction(function () use ($action, $scope, $data) {
            $query = Card::query()
                ->whereNull('order_id')
                ->whereNull('replaced_at');

            if ($scope === 'category') {
                $categoryId = (int) $data['category_id'];
                $query->whereHas('product', fn (Builder $q) => $q->where('category_id', $categoryId));
            } elseif ($scope === 'products') {
                $productIds = array_values(array_unique(array_map('intval', $data['product_ids'])));
                $query->whereIn('product_id', $productIds);
            } elseif ($scope === 'cards') {
                $cardIds = array_values(array_unique(array_map('intval', $data['card_ids'])));
                $query->whereIn('id', $cardIds);
            }

            $sourceStatus = in_array($action, ['pause_unsold', 'delete_unsold'], true) ? 'unsold' : 'disabled';
            $query->where('status', $sourceStatus);

            $count = 0;
            $affectedProductMap = [];
            $now = now();
            $lastId = 0;

            while (true) {
                $batch = (clone $query)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit(1000)
                    ->lockForUpdate()
                    ->get(['id', 'product_id']);
                if ($batch->isEmpty()) {
                    break;
                }
                $lastId = (int) $batch->last()->id;
                $batchIds = $batch->pluck('id')->all();
                foreach ($batch as $row) {
                    $affectedProductMap[(int) $row->product_id] = true;
                }
                if ($action === 'pause_unsold') {
                    $count += Card::whereIn('id', $batchIds)->update(['status' => 'disabled', 'sold_at' => null, 'updated_at' => $now]);
                } elseif ($action === 'resume_disabled') {
                    $count += Card::whereIn('id', $batchIds)->update(['status' => 'unsold', 'sold_at' => null, 'updated_at' => $now]);
                } else {
                    $count += Card::whereIn('id', $batchIds)->delete();
                }
            }

            $affectedProductIds = array_keys($affectedProductMap);
            if ($action === 'resume_disabled' && $count > 0 && $affectedProductIds !== []) {
                $products = Product::whereIn('id', $affectedProductIds)
                    ->whereNotNull('low_stock_threshold')
                    ->where('low_stock_notified', true)
                    ->get();
                foreach ($products as $product) {
                    if ($product->stockCount() > $product->low_stock_threshold) {
                        $product->forceFill(['low_stock_notified' => false])->save();
                    }
                }
            }

            return [
                'count' => (int) $count,
                'affected_products' => $count > 0 ? count($affectedProductIds) : 0,
            ];
        }, 3);

        $scopeLabel = match ($scope) {
            'all' => '全部商品',
            'category' => '分类「' . (Category::whereKey((int) $data['category_id'])->value('name') ?? '#' . $data['category_id']) . '」',
            'products' => '所选 ' . count(array_unique($data['product_ids'])) . ' 个商品',
            'cards' => '所选卡密',
        };

        $actionMeta = [
            'pause_unsold' => ['log' => '一键暂停可售卡密', 'verb' => '暂停', 'noun' => '可售卡密'],
            'resume_disabled' => ['log' => '一键恢复停用卡密', 'verb' => '恢复', 'noun' => '停用卡密'],
            'delete_unsold' => ['log' => '一键清空未售卡密', 'verb' => '清空', 'noun' => '未售卡密'],
            'delete_disabled' => ['log' => '一键清空停用卡密', 'verb' => '清理', 'noun' => '停用卡密'],
        ][$action];

        $count = $result['count'];
        $affectedProducts = $result['affected_products'];

        if ($count > 0) {
            OperationLog::log(
                $actionMeta['log'],
                'card',
                null,
                "范围：{$scopeLabel}；成功{$actionMeta['verb']} {$count} 张{$actionMeta['noun']}（涉及 {$affectedProducts} 个商品）"
            );
            $message = "已成功{$actionMeta['verb']} {$count} 张{$actionMeta['noun']}（涉及 {$affectedProducts} 个商品）。";
        } else {
            $message = "{$scopeLabel}中没有符合条件的{$actionMeta['noun']}，未执行变更。";
        }

        return response()->json([
            'message' => $message,
            'action' => $action,
            'scope' => $scope,
            'count' => $count,
            'affected_products' => $affectedProducts,
        ]);
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'format' => 'nullable|in:csv,txt',
            'status' => 'nullable|in:unsold,locked,sold,disabled',
            'category_id' => 'nullable|integer|min:1',
            'product_id' => 'nullable|integer|min:1',
            'product_ids' => 'nullable|string|max:5000',
            'content' => 'nullable|string|max:500',
        ]);

        $format = (string) ($data['format'] ?? 'csv');
        $productIds = [];
        if (!empty($data['product_ids'])) {
            foreach (explode(',', (string) $data['product_ids']) as $rawId) {
                $trimmed = trim($rawId);
                if ($trimmed === '') {
                    continue;
                }
                if (!ctype_digit($trimmed) || (int) $trimmed < 1) {
                    return response()->json(['message' => '商品 ID 列表格式无效。'], 422);
                }
                $productIds[] = (int) $trimmed;
            }
            $productIds = array_values(array_unique($productIds));
            if (count($productIds) > 500) {
                return response()->json(['message' => '单次最多按 500 个商品导出卡密。'], 422);
            }
        }

        $query = $this->applyGlobalScopeFilters($request, Card::query());
        if ($productIds !== []) {
            $query->whereIn('product_id', $productIds);
        }
        if ($request->filled('content')) {
            $query->where('content_fingerprint', app(\App\Security\SecretCipher::class)->fingerprint(trim((string) $request->input('content'))));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $data['status']);
        }

        $query->with(['product:id,name,category_id', 'product.category:id,name', 'order:id,order_no'])
            ->orderBy('id');

        $statusLabel = match ($data['status'] ?? null) {
            'unsold' => 'unsold',
            'disabled' => 'disabled',
            'sold' => 'sold',
            'locked' => 'locked',
            default => 'all',
        };
        $filename = sprintf('cards_%s_%s.%s', $statusLabel, date('Ymd_His'), $format);

        OperationLog::log(
            '导出卡密',
            'card',
            null,
            sprintf(
                '格式：%s；状态：%s；分类：%s；商品：%s',
                strtoupper($format),
                $statusLabel,
                $request->input('category_id') ?: '全部',
                $productIds !== [] ? count($productIds) . '个指定商品' : ($request->input('product_id') ?: '全部')
            )
        );

        $headers = [
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ];

        if ($format === 'txt') {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';

            return Response::stream(function () use ($query) {
                $out = fopen('php://output', 'w');
                $query->chunkById(500, function ($cards) use ($out) {
                    foreach ($cards as $card) {
                        fwrite($out, (string) $card->content . "\n");
                    }
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                });
                fclose($out);
            }, 200, $headers);
        }

        $statusMap = [
            'unsold' => '未售',
            'locked' => '锁定中',
            'sold' => '已售',
            'disabled' => '停用',
        ];

        $cell = static function ($value): string {
            $value = (string) $value;
            if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
                $value = "'" . $value;
            }

            return '"' . str_replace('"', '""', $value) . '"';
        };

        $headers['Content-Type'] = 'text/csv; charset=UTF-8';

        return Response::stream(function () use ($query, $statusMap, $cell) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fwrite($out, "ID,所属分类,商品名称,卡密内容,库存状态,关联订单号,入库时间,售出时间\n");

            $query->chunkById(500, function ($cards) use ($out, $statusMap, $cell) {
                foreach ($cards as $card) {
                    $statusText = $card->replaced_at
                        ? '售后已换出'
                        : ($card->status === 'sold'
                            ? ($card->order_id ? '订单已售' : '线下已售')
                            : ($statusMap[$card->status] ?? $card->status));

                    fwrite($out, implode(',', array_map($cell, [
                        $card->id,
                        $card->product?->category?->name ?? '',
                        $card->product?->name ?? '',
                        $card->content,
                        $statusText,
                        $card->order?->order_no ?? '',
                        $card->created_at,
                        $card->sold_at ?? '',
                    ])) . "\n");
                }
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });
            fclose($out);
        }, 200, $headers);
    }

    private function applyGlobalScopeFilters(Request $request, Builder $query): Builder
    {
        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->input('product_id'));
        } elseif ($request->filled('category_id')) {
            $categoryId = (int) $request->input('category_id');
            $query->whereHas('product', fn (Builder $q) => $q->where('category_id', $categoryId));
        }

        return $query;
    }

    private function summarizeStats(Builder $baseQuery): array
    {
        $rows = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) AS cnt, COUNT(DISTINCT product_id) AS product_cnt')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $unsold = (int) ($rows->get('unsold')->cnt ?? 0);
        $sold = (int) ($rows->get('sold')->cnt ?? 0);
        $locked = (int) ($rows->get('locked')->cnt ?? 0);
        $disabled = (int) ($rows->get('disabled')->cnt ?? 0);

        return [
            'total' => $unsold + $sold + $locked + $disabled,
            'unsold' => $unsold,
            'sold' => $sold,
            'locked' => $locked,
            'disabled' => $disabled,
            'products_with_unsold' => (int) ($rows->get('unsold')->product_cnt ?? 0),
            'products_with_disabled' => (int) ($rows->get('disabled')->product_cnt ?? 0),
        ];
    }

    public function index(Request $request, Product $product)
    {
        $pageSize = AdminListQuery::pageSize($request, 50, [
            'status' => 'nullable|in:unsold,locked,sold,disabled',
            'content' => 'nullable|string|max:500',
        ]);
        $query = $product->cards()->with('order:id,order_no,status');

        if ($request->filled('content')) {
            $query->where('content_fingerprint', app(\App\Security\SecretCipher::class)->fingerprint(trim((string) $request->input('content'))));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $cards = $query->orderByDesc('id')->paginate($pageSize);
        $cards->getCollection()->each(fn (Card $card) => $card->makeVisible('content'));

        $total = $product->cards()->count();
        $unsold = $product->cards()->where('status', 'unsold')->count();
        $sold = $product->cards()->where('status', 'sold')->count();
        // Locked cards are neither sellable stock nor revenue, and they are the
        // ones a manual status change refuses to touch, so the operator needs to
        // see how many there are.
        $locked = $product->cards()->where('status', 'locked')->count();
        $disabled = $product->cards()->where('status', 'disabled')->count();

        return response()->json([
            'data' => CardResource::collection($cards->items())->resolve($request),
            'total' => $cards->total(),
            'stats' => compact('total', 'unsold', 'sold', 'locked', 'disabled'),
            'product' => ['id' => $product->id, 'name' => $product->name],
        ]);
    }

    public function import(Request $request, Product $product, CardService $cardService)
    {
        $request->validate([
            'content' => 'required_without:file|nullable|string',
            'file' => 'required_without:content|nullable|file|mimes:txt,csv|max:10240',
        ]);

        if ($request->hasFile('file')) {
            $content = file_get_contents($request->file('file')->getRealPath());
        } else {
            $content = $request->input('content');
        }

        if (!is_string($content)) {
            return response()->json(['message' => '无法读取导入文件。'], 422);
        }
        try {
            $result = $cardService->importCardsWithResult($product->id, $content);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if ($result['total'] === 0) {
            return response()->json(['message' => '没有可导入的卡密。'], 422);
        }
        if ($result['count'] > 0) {
            $product->loadMissing(['category', 'wholesalePrices']);
            app(\App\Services\NotificationQueue::class)->enqueueProductListed($product, 'restocked', (int) $result['count']);
        }
        $message = "成功导入 {$result['count']} 张卡密，跳过 {$result['skipped']} 条重复卡密。";
        OperationLog::log('导入卡密', 'product', $product->id, "{$message} 商品：{$product->name}");

        return response()->json(array_merge(['message' => $message], $result));
    }

    /**
     * Pause inventory or register an offline sale without altering order delivery.
     *
     * For stock the operator corrects outside the order flow: a card sold over
     * chat, or one wrongly marked sold. Cards that belong to an order are not
     * the operator's to move — see the guards below.
     */
    public function updateStatus(Request $request, Card $card)
    {
        $data = $request->validate([
            'status' => 'required|in:unsold,sold,disabled',
        ]);

        $target = $data['status'];

        // Re-read under a row lock. Checkout picks unsold rows with lockForUpdate
        // in its own transaction, so without this an operator's flip can land on
        // a card in the instant between it being chosen for an order and its
        // status becoming 'locked' — leaving the order holding a card marked
        // sold that fulfilment would then allocate a second time.
        $outcome = DB::transaction(function () use ($card, $target) {
            $fresh = Card::whereKey($card->getKey())->lockForUpdate()->first();

            if (!$fresh) {
                return '卡密不存在或已被删除。';
            }

            if ($fresh->status === $target) {
                return ['card' => $fresh, 'changed' => false];
            }

            // 'locked' means a pending order is holding this card. Selling it out
            // from under that order, or releasing it while the buyer is still at
            // the payment page, both corrupt the order; closing or confirming it
            // is the operation actually wanted here.
            if ($fresh->status === 'locked') {
                return '该卡密已被待支付订单锁定，请先关闭或确认对应订单。';
            }

            // A sold card with an order behind it was emailed to a buyer. Putting
            // it back on the shelf would put a secret that buyer already holds up
            // for sale again.
            //
            // This guard reads order_id, and cards.order_id is declared nullOnDelete.
            // Nothing deletes an order today, so the link cannot be broken — but if a
            // delete-order feature is ever added, every delivered card of a deleted
            // order becomes sold with a null order_id and passes this check. Add a
            // separate "was delivered" marker before allowing order deletion.
            if ($fresh->order_id !== null || $fresh->replaced_at !== null) {
                return '该卡密已随订单发货给买家，不能改回未售出，否则会被重复售出。';
            }

            // Never use pause/resume to erase a recorded offline sale.
            if ($target === 'disabled' && $fresh->status !== 'unsold') {
                return '仅可暂停未售卡密；线下已售记录请先核实是否需要撤销。';
            }
            if ($target === 'sold' && $fresh->status !== 'unsold') {
                return '停用卡密不能登记售出，请先核实并恢复销售。';
            }

            $fresh->update($target === 'sold'
                ? ['status' => 'sold', 'sold_at' => now()]
                : ['status' => $target, 'sold_at' => null]);

            return ['card' => $fresh, 'changed' => true];
        });

        if (is_string($outcome)) {
            return response()->json(['message' => $outcome], 422);
        }

        $label = ['sold' => '线下已售', 'unsold' => '可售', 'disabled' => '停用'][$target];

        if ($outcome['changed']) {
            if ($target === 'sold') {
                app(\App\Services\NotificationQueue::class)->enqueueOfflineCardSold($outcome['card']);
            }
            OperationLog::log('修改卡密状态', 'card', $outcome['card']->id, "卡密 #{$outcome['card']->id} 状态改为{$label}");
        }

        return response()->json([
            'message' => $outcome['changed'] ? "卡密已标记为{$label}。" : "卡密已是{$label}，无需修改。",
            'card' => (new CardResource($outcome['card']->load('order:id,order_no,status')))->resolve($request),
        ]);
    }

    public function destroy(Card $card)
    {
        // The status check is part of the DELETE, not a step before it. Reading the
        // route-bound model and then deleting is a check-then-act: checkout picks
        // unsold rows in its own transaction, so a card can become 'locked' in the
        // gap and the delete would take a card a pending order is holding — the
        // buyer's order then cannot be fulfilled. batchDestroy() below already
        // scoped its delete this way; this one had not.
        $deleted = Card::whereKey($card->getKey())
            ->where('status', 'unsold')
            ->whereNull('order_id')
            ->whereNull('replaced_at')
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => '只能删除未售出的卡密。'], 422);
        }

        OperationLog::log('删除卡密', 'card', $card->id, '删除卡密');

        return response()->json(['message' => 'ok']);
    }

    public function batchDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|min:1',
        ]);

        $count = Card::whereIn('id', $request->ids)
            ->where('status', 'unsold')
            ->whereNull('order_id')
            ->whereNull('replaced_at')
            ->delete();
        OperationLog::log('批量删除卡密', 'card', null, "批量删除 {$count} 张卡密");

        return response()->json(['message' => "成功删除 {$count} 张卡密。", 'count' => $count]);
    }
}
