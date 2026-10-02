<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use App\Exceptions\CheckoutException;

class RefundService
{
    public function request(Order $order, string $amount, string $reason, ?int $receiptId = null, string $source = 'admin'): OrderRefund
    {
        if (! mb_check_encoding($reason, 'UTF-8') || str_contains($reason, "\0")) {
            throw new CheckoutException('退款原因不能包含无效编码或空字符。');
        }
        if (! preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', $amount)) {
            throw new CheckoutException('退款金额格式无效。');
        }

        return DB::transaction(function () use ($order, $amount, $reason, $receiptId, $source) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $receipt = $receiptId ? PaymentReceipt::where('order_id', $locked->id)->whereKey($receiptId)->firstOrFail()
                : ($locked->payment_no ? PaymentReceipt::where('order_id', $locked->id)->where('trade_no', $locked->payment_no)->first() : null);
            if (! $receipt && ! $locked->isPaid()) {
                throw new CheckoutException('未付款订单不能申请订单退款；异常付款请指定对应回执。');
            }
            $maximum = $receipt?->amount ?? $locked->total_amount;
            $existingQuery = $locked->refunds()->whereIn('status', ['requested', 'approved', 'completed']);
            if ($receipt && $receipt->trade_no === $locked->payment_no) {
                // Legacy/manual payments had no receipt row. Their refunds still
                // consume the primary payment's allowance after a receipt is added.
                $existingQuery->where(fn ($q) => $q->where('payment_receipt_id', $receipt->id)->orWhereNull('payment_receipt_id'));
            } else {
                $existingQuery->where('payment_receipt_id', $receipt?->id);
            }
            $existing = $existingQuery->sum('amount');
            if (bccomp($amount, '0', 2) <= 0 || bccomp(bcadd($amount, (string) $existing, 2), $maximum, 2) > 0) {
                throw new CheckoutException('退款金额超出可退款余额。');
            }

            return OrderRefund::create(['order_id' => $locked->id, 'payment_receipt_id' => $receipt?->id, 'amount' => $amount,
                'reason' => $reason, 'source' => $source, 'status' => 'requested']);
        });
    }

    public function transition(OrderRefund $refund, string $status, ?string $reference, ?string $note, int $adminId): OrderRefund
    {
        return DB::transaction(function () use ($refund, $status, $reference, $note, $adminId) {
            Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $locked = OrderRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $allowed = ['requested' => ['approved', 'rejected'], 'approved' => ['completed', 'rejected']];
            if (! in_array($status, $allowed[$locked->status] ?? [], true)) {
                throw new CheckoutException('退款状态已变化，不能重复处理。');
            }
            if ($status === 'completed' && ! $reference) {
                throw new CheckoutException('请填写实际退款流水或转账凭证编号。');
            }
            $locked->update(['status' => $status, 'reference' => $reference, 'note' => $note, 'admin_id' => $adminId,
                'completed_at' => $status === 'completed' ? now() : null]);
            if ($status === 'completed' && $locked->payment_receipt_id) {
                $receipt = PaymentReceipt::findOrFail($locked->payment_receipt_id);
                $refunded = OrderRefund::where('payment_receipt_id', $receipt->id)->where('status', 'completed')->sum('amount');
                if (bccomp((string) $refunded, $receipt->amount, 2) >= 0) {
                    $receipt->update(['review_resolved_at' => now(), 'resolution_note' => '退款完成：'.$reference]);
                    $order = Order::findOrFail($locked->order_id);
                    if (! $order->paymentReceipts()->whereNotNull('review_reason')->whereNull('review_resolved_at')->exists()) {
                        $order->update(['payment_review_reason' => null]);
                    }
                }
            }

            return $locked;
        });
    }
}
