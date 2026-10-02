<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use App\Exceptions\CheckoutException;

class RefundService
{
    public function enabled(): bool
    {
        return in_array((string) setting('refund_enabled', '0'), ['1', 'true'], true);
    }

    /** The primary payment allowance; pending requests also reserve refund balance. */
    public function balance(Order $order, ?PaymentReceipt $receipt = null): array
    {
        $receipt ??= $order->payment_no ? PaymentReceipt::where('order_id', $order->id)->where('trade_no', $order->payment_no)->first() : null;
        $maximum = (string) ($receipt?->amount ?? ($order->isPaid() ? $order->total_amount : '0.00'));
        $query = $order->refunds();
        if ($receipt && $receipt->trade_no === $order->payment_no) {
            $query->where(fn ($q) => $q->where('payment_receipt_id', $receipt->id)->orWhereNull('payment_receipt_id'));
        } else {
            $query->where('payment_receipt_id', $receipt?->id);
        }
        $reserved = (string) (clone $query)->whereIn('status', ['requested', 'approved'])->sum('amount');
        $completed = (string) (clone $query)->where('status', 'completed')->sum('amount');
        $available = bcsub(bcsub($maximum, $reserved, 2), $completed, 2);
        return ['maximum' => bcadd($maximum, '0', 2), 'reserved' => bcadd($reserved, '0', 2),
            'completed' => bcadd($completed, '0', 2), 'available' => bccomp($available, '0', 2) > 0 ? $available : '0.00'];
    }

    public function request(Order $order, string $amount, string $reason, ?int $receiptId = null, string $source = 'admin'): OrderRefund
    {
        if (! $this->enabled()) {
            throw new CheckoutException('店主暂未开放退款申请；已有申请仍会继续处理。');
        }
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
            $balance = $this->balance($locked, $receipt);
            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $balance['available'], 2) > 0) {
                throw new CheckoutException('退款金额超出可退款余额。');
            }

            $refund = OrderRefund::create(['order_id' => $locked->id, 'payment_receipt_id' => $receipt?->id, 'amount' => $amount,
                'reason' => $reason, 'source' => $source, 'status' => 'requested']);
            app(NotificationQueue::class)->enqueueRefund($locked, $refund);
            return $refund;
        });
    }

    public function transition(OrderRefund $refund, string $status, ?string $reference, ?string $note, int $adminId, ?string $customerNote = null): OrderRefund
    {
        if ($customerNote !== null && (! mb_check_encoding($customerNote, 'UTF-8') || str_contains($customerNote, "\0") || mb_strlen($customerNote) > 2000)) {
            throw new CheckoutException('买家处理说明格式无效或超过2000字。');
        }
        return DB::transaction(function () use ($refund, $status, $reference, $note, $adminId, $customerNote) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $locked = OrderRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $allowed = ['requested' => ['approved', 'rejected'], 'approved' => ['completed', 'rejected']];
            if (! in_array($status, $allowed[$locked->status] ?? [], true)) {
                throw new CheckoutException('退款状态已变化，不能重复处理。');
            }
            if ($status === 'completed' && ! $reference) {
                throw new CheckoutException('请填写实际退款流水或转账凭证编号。');
            }
            $locked->update(['status' => $status, 'reference' => $reference, 'note' => $note, 'admin_id' => $adminId,
                'customer_note' => $customerNote,
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
            app(NotificationQueue::class)->enqueueRefund($order, $locked);
            return $locked;
        });
    }
}
