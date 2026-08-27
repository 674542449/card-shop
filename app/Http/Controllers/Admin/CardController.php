<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Product;
use App\Models\OperationLog;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function index(Request $request, Product $product)
    {
        $query = $product->cards();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $cards = $query->orderByDesc('id')->paginate(50)->appends($request->query());

        $total = $product->cards()->count();
        $unsold = $product->cards()->where('status', 'unsold')->count();
        $sold = $product->cards()->where('status', 'sold')->count();
        $locked = $product->cards()->where('status', 'locked')->count();

        return view('admin.cards.index', compact('product', 'cards', 'total', 'unsold', 'sold', 'locked'));
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

        return redirect()->back()->with('success', "成功导入 {$count} 张卡密。");
    }

    public function destroy(Card $card)
    {
        if ($card->status !== 'unsold') {
            return redirect()->back()->with('error', '只能删除未售出的卡密。');
        }

        $card->delete();

        OperationLog::log('删除卡密', 'card', $card->id, '删除卡密');

        return redirect()->back()->with('success', '卡密删除成功。');
    }

    public function batchDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $count = Card::whereIn('id', $request->ids)->where('status', 'unsold')->delete();

        OperationLog::log('批量删除卡密', 'card', null, "批量删除 {$count} 张卡密");

        return redirect()->back()->with('success', "成功删除 {$count} 张卡密。");
    }
}
