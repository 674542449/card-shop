<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\OperationLog;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with('product');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->email . '%');
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $sortBy = $request->get('sort', 'created_at');
        $sortDir = $request->get('dir', 'desc');

        if (!in_array($sortBy, ['created_at', 'total_amount'])) {
            $sortBy = 'created_at';
        }

        $query->orderBy($sortBy, $sortDir);

        $orders = $query->paginate(20)->appends($request->query());

        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load(['product', 'cards', 'coupon']);

        return view('admin.orders.show', compact('order'));
    }

    public function resend(Order $order)
    {
        if ($order->status !== 'paid') {
            return redirect()->back()->with('error', '只能对已支付订单补发卡密。');
        }

        OperationLog::log('补发卡密', 'order', $order->id, "订单 {$order->order_no} 补发卡密");

        return redirect()->back()->with('success', "卡密已重新发送至 {$order->email}。");
    }

    public function close(Order $order)
    {
        if ($order->status !== 'pending') {
            return redirect()->back()->with('error', '只能关闭待支付订单。');
        }

        DB::transaction(function () use ($order) {
            Card::where('order_id', $order->id)
                ->where('status', 'locked')
                ->update([
                    'status'    => 'unsold',
                    'order_id'  => null,
                    'locked_at' => null,
                ]);

            $order->update(['status' => 'closed']);
        });

        OperationLog::log('关闭订单', 'order', $order->id, "关闭订单 {$order->order_no}");

        return redirect()->back()->with('success', '订单已关闭。');
    }

    public function markPaid(Order $order)
    {
        if ($order->status !== 'pending') {
            return redirect()->back()->with('error', '只能确认待支付订单。');
        }

        DB::transaction(function () use ($order) {
            $cards = Card::where('order_id', $order->id)
                ->where('status', 'locked')
                ->get();

            if ($cards->isEmpty()) {
                $cards = Card::where('product_id', $order->product_id)
                    ->where('status', 'unsold')
                    ->take($order->quantity)
                    ->lockForUpdate()
                    ->get();
            }

            if ($cards->count() < $order->quantity) {
                throw new \RuntimeException('库存不足');
            }

            foreach ($cards as $card) {
                $card->update([
                    'status'   => 'sold',
                    'order_id' => $order->id,
                    'sold_at'  => now(),
                ]);
            }

            $order->update([
                'status'         => 'paid',
                'paid_at'        => now(),
                'payment_method' => $order->payment_method ?: 'manual',
            ]);
        });

        OperationLog::log('手动确认支付', 'order', $order->id, "手动确认订单 {$order->order_no}");

        return redirect()->back()->with('success', '订单已确认支付。');
    }

    public function export(Request $request)
    {
        $query = Order::with('product');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->email . '%');
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $sortBy = $request->get('sort', 'created_at');
        $sortDir = $request->get('dir', 'desc');

        if (!in_array($sortBy, ['created_at', 'total_amount'])) {
            $sortBy = 'created_at';
        }

        $query->orderBy($sortBy, $sortDir);

        $orders = $query->get();

        $statusMap = [
            'pending' => '待支付',
            'paid'    => '已支付',
            'expired' => '已过期',
            'closed'  => '已关闭',
        ];

        $csv = "\xEF\xBB\xBF"; // BOM for Excel
        $csv .= "订单号,商品名称,邮箱,数量,总金额,支付方式,状态,创建时间,支付时间\n";

        foreach ($orders as $order) {
            $csv .= implode(',', [
                $order->order_no,
                '"' . str_replace('"', '""', $order->product->name ?? '') . '"',
                $order->email,
                $order->quantity,
                $order->total_amount,
                $order->payment_method ?? '',
                $statusMap[$order->status] ?? $order->status,
                $order->created_at,
                $order->paid_at ?? '',
            ]) . "\n";
        }

        $filename = 'orders_' . date('Ymd') . '.csv';

        return Response::make($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }
}
