<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Product;
use App\Models\OperationLog;
use Illuminate\Http\Request;

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

        return response()->json([
            'data' => $cards->items(),
            'total' => $cards->total(),
            'stats' => compact('total', 'unsold', 'sold'),
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

        $lines = array_filter(array_map('trim', explode("\n", $content)));
        $count = 0;

        foreach ($lines as $line) {
            Card::create([
                'product_id' => $product->id,
                'content' => $line,
                'status' => 'unsold',
            ]);
            $count++;
        }

        OperationLog::log('导入卡密', 'product', $product->id, "导入 {$count} 张卡密到 {$product->name}");

        return response()->json(['message' => "成功导入 {$count} 张卡密。", 'count' => $count]);
    }

    public function destroy(Card $card)
    {
        if ($card->status !== 'unsold') {
            return response()->json(['message' => '只能删除未售出的卡密。'], 422);
        }

        OperationLog::log('删除卡密', 'card', $card->id, '删除卡密');
        $card->delete();

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
