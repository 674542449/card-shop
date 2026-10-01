<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\RefundService;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => 'nullable|in:requested,approved,completed,rejected', 'page' => 'nullable|integer|min:1']);
        $q = OrderRefund::with('order:id,order_no,product_name,email');
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        return response()->json($q->orderByDesc('id')->paginate(20));
    }

    public function store(Request $request, Order $order, RefundService $service)
    {
        abort_unless($request->attributes->get('admin')->allows('refunds', 'write'), 403);
        $data = $request->validate(['amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999999999', 'reason' => 'required|string|max:2000', 'payment_receipt_id' => 'nullable|integer']);
        try {
            $refund = $service->request($order, (string) $data['amount'], $data['reason'], $data['payment_receipt_id'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        OperationLog::log('登记退款申请', 'refund', $refund->id, $order->order_no.' '.$refund->amount.' CNY');

        return response()->json($refund, 201);
    }

    public function update(Request $request, OrderRefund $refund, RefundService $service)
    {
        $data = $request->validate(['status' => 'required|in:approved,completed,rejected', 'reference' => 'nullable|string|max:255', 'note' => 'nullable|string|max:2000']);
        try {
            $result = $service->transition($refund, $data['status'], $data['reference'] ?? null, $data['note'] ?? null, $request->attributes->get('admin')->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        OperationLog::log('处理退款', 'refund', $refund->id, $data['status']);

        return response()->json($result);
    }
}
