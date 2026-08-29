<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Product;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CardController extends Controller
{
    public function index(Request $request, Product $product)
    {
        $query = $product->cards()->with('order');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $cards = $query->orderByDesc('id')->paginate($request->get('pageSize', 50));

        $total = $product->cards()->count();
        $unsold = $product->cards()->where('status', 'unsold')->count();
        $sold = $product->cards()->where('status', 'sold')->count();
        // Locked cards are neither sellable stock nor revenue, and they are the
        // ones a manual status change refuses to touch, so the operator needs to
        // see how many there are.
        $locked = $product->cards()->where('status', 'locked')->count();

        return response()->json([
            'data' => $cards->items(),
            'total' => $cards->total(),
            'stats' => compact('total', 'unsold', 'sold', 'locked'),
            'product' => ['id' => $product->id, 'name' => $product->name],
        ]);
    }

    public function import(Request $request, Product $product)
    {
        $request->validate([
            'content' => 'required_without:file',
            'file' => 'required_without:content|file|mimes:txt,csv',
        ]);

        if ($request->hasFile('file')) {
            $content = file_get_contents($request->file('file')->getRealPath());
        } else {
            $content = $request->input('content');
        }

        // \r stripped too: a file saved on Windows arrives with CRLF, and trim() alone
        // left a trailing \r on every secret — invisible in the admin, and delivered
        // to the buyer inside the card content.
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $content))));
        $count = count($lines);

        if ($count === 0) {
            return response()->json(['message' => '没有可导入的卡密。'], 422);
        }

        $now = now();
        $rows = array_map(fn (string $line) => [
            'product_id' => $product->id,
            'content' => $line,
            'status' => 'unsold',
            'created_at' => $now,
            'updated_at' => $now,
        ], $lines);

        // One transaction, chunked inserts. It used to issue one INSERT per line with
        // no transaction, so a 5,000-card import was 5,000 round trips: slow enough to
        // hit the request timeout, and every row before the cut was already committed.
        // The operator saw a failure, imported the same file again, and the shop ended
        // up with duplicates of every secret that had made it in — two buyers sold the
        // same card.
        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Card::insert($chunk);
            }
        });

        OperationLog::log('导入卡密', 'product', $product->id, "导入 {$count} 张卡密到 {$product->name}");

        return response()->json(['message' => "成功导入 {$count} 张卡密。", 'count' => $count]);
    }

    /**
     * Flip a single card between 已售出 and 未售出 by hand.
     *
     * For stock the operator corrects outside the order flow: a card sold over
     * chat, or one wrongly marked sold. Cards that belong to an order are not
     * the operator's to move — see the guards below.
     */
    public function updateStatus(Request $request, Card $card)
    {
        $data = $request->validate([
            'status' => 'required|in:unsold,sold',
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
            if ($target === 'unsold' && $fresh->order_id !== null) {
                return '该卡密已随订单发货给买家，不能改回未售出，否则会被重复售出。';
            }

            $fresh->update($target === 'sold'
                ? ['status' => 'sold', 'sold_at' => now()]
                : ['status' => 'unsold', 'sold_at' => null]);

            return ['card' => $fresh, 'changed' => true];
        });

        if (is_string($outcome)) {
            return response()->json(['message' => $outcome], 422);
        }

        $label = $target === 'sold' ? '已售出' : '未售出';

        if ($outcome['changed']) {
            OperationLog::log('修改卡密状态', 'card', $outcome['card']->id, "卡密 #{$outcome['card']->id} 状态改为{$label}");
        }

        return response()->json([
            'message' => $outcome['changed'] ? "卡密已标记为{$label}。" : "卡密已是{$label}，无需修改。",
            'card' => $outcome['card']->load('order'),
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
        $deleted = Card::whereKey($card->getKey())->where('status', 'unsold')->delete();

        if ($deleted === 0) {
            return response()->json(['message' => '只能删除未售出的卡密。'], 422);
        }

        OperationLog::log('删除卡密', 'card', $card->id, '删除卡密');

        return response()->json(['message' => 'ok']);
    }

    public function batchDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $count = Card::whereIn('id', $request->ids)->where('status', 'unsold')->delete();
        OperationLog::log('批量删除卡密', 'card', null, "批量删除 {$count} 张卡密");

        return response()->json(['message' => "成功删除 {$count} 张卡密。", 'count' => $count]);
    }
}
