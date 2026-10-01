<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\SeoDelivery;
use App\Services\HeartbeatService;
use App\Services\PaymentReconciliationService;
use App\Services\SeoQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function sync(Order $order, PaymentReconciliationService $service)
    {
        try {
            $result = $service->sync($order);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        OperationLog::log('网关对账', 'order', $order->id, $order->order_no);

        return response()->json($result);
    }

    public function resolve(Request $request, Order $order, PaymentReceipt $receipt)
    {
        abort_unless($receipt->order_id === $order->id, 404);
        $data = $request->validate(['note' => 'required|string|max:2000']);
        DB::transaction(function () use ($order, $receipt, $data) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedReceipt = PaymentReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            abort_if(! $lockedReceipt->review_reason || $lockedReceipt->review_resolved_at, 422, '该回执已处理或无需核对。');
            $lockedReceipt->update(['review_resolved_at' => now(), 'resolution_note' => $data['note']]);
            if (! $lockedOrder->paymentReceipts()->whereNotNull('review_reason')->whereNull('review_resolved_at')->exists()) {
                $lockedOrder->update(['payment_review_reason' => null]);
            }
        });
        OperationLog::log('完成付款核对', 'order', $order->id, $data['note']);

        return response()->json(['message' => '核对结果已保存。']);
    }

    public function health(HeartbeatService $service)
    {
        return response()->json($service->health());
    }

    public function seo(Request $request)
    {
        $request->validate(['status' => 'nullable|in:pending,processing,sent,failed', 'page' => 'nullable|integer|min:1']);

        return response()->json(SeoDelivery::when($request->filled('status'), fn ($q) => $q->where('status', $request->status))->orderByDesc('id')->paginate(20));
    }

    public function retrySeo(SeoDelivery $delivery)
    {
        $updated = SeoDelivery::whereKey($delivery->id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'last_error' => null]);
        abort_unless($updated, 422, '仅失败的推送可重试。');
        OperationLog::log('重试 SEO 推送', 'seo_delivery', $delivery->id, $delivery->provider);

        return response()->json(['message' => '已重新加入推送队列。'], 202);
    }

    public function enqueueSeo(SeoQueue $queue)
    {
        $count = $queue->enqueueSite();
        OperationLog::log('提交现有页面', 'seo_delivery', null, '新增任务 '.$count);

        return response()->json(['message' => $count ? '已加入 '.$count.' 条推送任务。' : '尚未配置推送密钥，请先在系统设置中配置。'], 202);
    }
}
