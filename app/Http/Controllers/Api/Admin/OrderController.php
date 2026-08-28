<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\OperationLog;
use App\Models\Order;
use App\Services\NotificationService;
use App\Services\OrderFulfilmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly OrderFulfilmentService $fulfilment,
    ) {
    }

    public function index(Request $request)
    {
        $query = Order::with('product');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
        // The table's search form submits column names; accept those as well as the
        // combined `keyword` so the 订单号 and 邮箱 search boxes actually filter.
        if (filled($request->input('order_no'))) {
            $query->where('order_no', 'ilike', '%' . $request->input('order_no') . '%');
        }
        if (filled($request->input('email'))) {
            $query->where('email', 'ilike', '%' . $request->input('email') . '%');
        }
        if ($request->filled('keyword')) {
            $kw = $request->keyword;
            $query->where(function ($q) use ($kw) {
                $q->where('order_no', 'ilike', "%{$kw}%")
                  ->orWhere('email', 'ilike', "%{$kw}%");
            });
        }

        $dateFrom = $request->input('date_from', $request->input('start_date'));
        $dateTo = $request->input('date_to', $request->input('end_date'));
        if (filled($dateFrom)) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if (filled($dateTo)) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $sortBy = $request->get('sort', 'created_at');
        $sortDir = strtolower((string) $request->get('dir', 'desc'));
        if (!in_array($sortBy, ['created_at', 'total_amount'], true)) {
            $sortBy = 'created_at';
        }
        // orderBy() throws on anything other than asc/desc, which would surface as a 500.
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        $orders = $query->orderBy($sortBy, $sortDir)
            ->paginate($request->get('pageSize', 20));

        return response()->json([
            'data' => $orders->items(),
            'total' => $orders->total(),
        ]);
    }

    public function show(Order $order)
    {
        $order->load(['product', 'cards', 'coupon']);

        return response()->json($order);
    }

    public function close(Order $order)
    {
        if ($order->status !== 'pending') {
            return response()->json(['message' => '只能关闭待支付订单。'], 422);
        }

        DB::transaction(function () use ($order) {
            Card::where('order_id', $order->id)
                ->where('status', 'locked')
                ->update(['status' => 'unsold', 'order_id' => null, 'locked_at' => null]);

            $order->update(['status' => 'closed']);
        });

        OperationLog::log('关闭订单', 'order', $order->id, "关闭订单 {$order->order_no}");

        return response()->json(['message' => '订单已关闭。']);
    }

    public function markPaid(Order $order)
    {
        if ($order->status !== 'pending') {
            return response()->json(['message' => '只能确认待支付订单。'], 422);
        }

        // Confirming payment runs the same fulfilment the gateway callback runs —
        // card allocation, the status flip and the delivery email — so the two
        // paths cannot drift. The service re-checks the pending status under a
        // row lock, which is what makes a click racing a real callback safe.
        $result = $this->fulfilment->fulfilManually($order);

        if (!$result->wasFulfilled()) {
            // Either no stock to allocate, or the order stopped being pending
            // between the check above and the lock — a callback landing first.
            return response()->json(['message' => $result->reason], 422);
        }

        OperationLog::log('手动确认支付', 'order', $order->id, "手动确认订单 {$order->order_no}");

        return response()->json(['message' => '订单已确认支付，卡密已发送。']);
    }

    public function resend(Order $order)
    {
        if ($order->status !== 'paid') {
            return response()->json(['message' => '只能对已支付订单补发卡密。'], 422);
        }

        $order->load(['product', 'cards']);

        if ($order->cards->isEmpty()) {
            return response()->json(['message' => '该订单没有已发放的卡密，无法补发。'], 422);
        }

        // Previously this only wrote a log line and reported success without sending
        // anything.
        $this->notifications->sendOrderEmail($order);

        OperationLog::log('补发卡密', 'order', $order->id, "订单 {$order->order_no} 补发卡密");

        return response()->json(['message' => "卡密已重新发送至 {$order->email}。"]);
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

        $orders = $query->orderByDesc('created_at')->get();

        $statusMap = [
            'pending' => '待支付', 'paid' => '已支付',
            'expired' => '已过期', 'closed' => '已关闭',
        ];

        $csv = "\xEF\xBB\xBF";
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

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=orders_' . date('Ymd') . '.csv',
        ]);
    }
}
