<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Enums\PaymentReviewCode;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place an order becomes paid.
 *
 * Both the gateway callback and the admin's 手动确认支付 button run through
 * transition(), so status checks, card allocation and delivery cannot
 * drift apart the way the two hand-written copies did. The two entry points
 * differ in exactly one thing — what they verify before allowing the transition
 * — and that difference is written out below rather than expressed as a flag a
 * caller could pass wrongly.
 */
class OrderFulfilmentService
{
    // Receipt and order amounts are CNY cents. Extra trailing zeros are harmless;
    // nonzero fractions of a cent must never be rounded by PostgreSQL on insert.
    private const CNY_AMOUNT_PATTERN = '/^\d{1,16}(?:\.\d{1,2}0{0,6})?$/D';

    public function __construct(private readonly NotificationQueue $notifications)
    {
    }

    /**
     * Fulfil an order from a payment gateway callback.
     *
     * Channel and amount verification happens inside the same locked transaction
     * that performs the transition, and a failed check refuses it outright. This
     * is the check that stops a buyer paying one fen for a 500 yuan order, so it
     * lives on this entry point and is unreachable from the manual one.
     */
    public function fulfilFromGateway(
        string $orderNo,
        string $tradeNo,
        string $paidAmount,
        string $channel,
        array $receiptDetails = [],
    ): OrderFulfilmentResult {
        if ($tradeNo === '' || strlen($tradeNo) > 100 || preg_match('/[\x00-\x20\x7f]/', $tradeNo)) {
            return OrderFulfilmentResult::refused('网关交易号无效。');
        }
        $paidAmount = trim($paidAmount);
        if (preg_match(self::CNY_AMOUNT_PATTERN, $paidAmount)) {
            $paidAmount = bcadd($paidAmount, '0', 2);
        }
        return $this->transition(
            $orderNo,
            $channel,
            fn (Order $order) => $this->verifyGatewayPayment($order, $paidAmount, $channel),
            fn (Order $order) => [
                'status' => 'paid',
                'payment_no' => $tradeNo,
                'paid_at' => now(),
                'payment_received_amount' => $paidAmount,
                'payment_received_at' => now(),
                '_gateway_payment_type' => $receiptDetails['epay_type'] ?? null,
                '_receipt_details' => \Illuminate\Support\Arr::only($receiptDetails, ['actual_amount', 'currency', 'network', 'transaction_hash']),
            ],
            // 'expired' is here because a buyer paying at T+30:01 is ordinary, not
            // hostile. Nothing tells the gateway our 30-minute deadline, and BEpusdt
            // retries a failed callback for about two hours — so a real payment
            // routinely arrives after the expiry job (or the buyer's own pay-page
            // poll) has flipped the order and released its cards. This used to be
            // dropped in silence and acked to the gateway as handled: money taken,
            // no cards, no log line, and no admin action that could repair it.
            //
            // Fulfilling it is safe because nothing else is relaxed —
            // verifyGatewayPayment still checks the channel and the amount, and
            // allocateCards re-allocates from unsold stock, which is exactly where
            // the released cards went. 'closed' is deliberately NOT here: that
            // status is an operator's decision, and overriding it automatically is
            // not this code's call. It alerts instead.
            ['pending', 'expired'],
        );
    }

    /**
     * Fulfil an order because an operator marked it paid in the admin.
     *
     * There is no amount to verify here: money that arrived out of band — a bank
     * transfer, a manual USDT send — is precisely what the operator asserts by
     * clicking, and no field of the order can confirm or deny it. The gateway's
     * amount check is not skipped so much as inapplicable, which is why this is
     * its own method instead of a nullable $paidAmount that a later caller could
     * leave null by accident and quietly disable the check for everyone.
     */
    public function fulfilManually(Order $order): OrderFulfilmentResult
    {
        return $this->transition(
            $order->order_no,
            'manual',
            fn (Order $locked) => null,
            fn (Order $locked) => [
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => $locked->payment_method ?: 'manual',
            ],
            // Wider than the gateway's set, and deliberately so: this IS the repair
            // path. An operator confirming payment on an expired or closed order is
            // asserting that money arrived out of band, which is the one case the
            // automatic path cannot resolve. Refusing it here left a paid order with
            // no way back short of editing the database by hand.
            ['pending', 'expired', 'closed'],
        );
    }

    /**
     * @param Closure(Order): ?string $verify       Null to proceed, a refusal reason to stop.
     * @param Closure(Order): array   $attributes   Order columns to write on success.
     * @param list<string>            $fromStatuses Statuses this transition may start from.
     */
    private function transition(
        string $orderNo,
        string $source,
        Closure $verify,
        Closure $attributes,
        array $fromStatuses = ['pending'],
    ): OrderFulfilmentResult {
        /** @var OrderFulfilmentResult $result */
        $result = DB::transaction(function () use ($orderNo, $source, $verify, $attributes, $fromStatuses) {
            // The row lock is taken before the status check is decided, so a
            // gateway callback and an operator click arriving together serialise
            // here and the loser finds the order already paid.
            $order = Order::where('order_no', $orderNo)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return OrderFulfilmentResult::skipped('订单不存在。');
            }

            $refusal = $verify($order);
            if ($refusal !== null) {
                return OrderFulfilmentResult::refused($refusal);
            }

            $receipt = null;
            if ($source !== 'manual') {
                $payment = $attributes($order);
                $receipt = PaymentReceipt::firstOrCreate([
                    'channel' => $source, 'trade_no' => $payment['payment_no'],
                ], ['order_id' => $order->id, 'amount' => $payment['payment_received_amount'], 'received_at' => now()] + ($payment['_receipt_details'] ?? []));
                if ($receipt->order_id !== $order->id || bccomp($receipt->amount, $payment['payment_received_amount'], 8) !== 0) {
                    return OrderFulfilmentResult::refused('网关流水已关联其他订单或金额发生变化。');
                }
                // A signed receipt is proof of collection, but a different trade
                // or payment type cannot replace the checkout binding. An operator's
                // recorded resolution is final; a gateway retry must not reopen it.
                $bindingRefusal = null;
                if ($source === 'epusdt' && $order->gateway_trade_no
                    && $order->gateway_trade_no !== $receipt->trade_no) {
                    $bindingRefusal = 'USDT 收款交易号与创建支付时绑定的交易号不一致，请核对后人工处理。';
                } elseif ($source === 'epay' && isset($payment['_gateway_payment_type'])
                    && $payment['_gateway_payment_type'] !== ($order->payment_method === 'alipay' ? 'alipay' : 'wxpay')) {
                    $bindingRefusal = '网关收款方式与订单不符，请核对后人工处理。';
                }
                if ($bindingRefusal !== null && $receipt->review_resolved_at) {
                    return OrderFulfilmentResult::skipped('网关回执已由操作员处理。');
                }
                if (!$order->isPaid()) {
                    $order->update([
                        'payment_no' => $receipt->trade_no,
                        'payment_received_amount' => $receipt->amount,
                        'payment_received_at' => $receipt->received_at,
                    ]);
                }
                if ($bindingRefusal !== null) {
                    $this->recordReview($order, $receipt, PaymentReviewCode::BindingMismatch, $bindingRefusal);
                    return OrderFulfilmentResult::refused($bindingRefusal, $order, true);
                }
            }
            if ($order->isPaid()) {
                if ($receipt && $order->payment_no !== $receipt->trade_no && !$receipt->review_resolved_at) {
                    $this->recordReview($order, $receipt, PaymentReviewCode::DuplicatePayment, '已发货订单收到另一笔付款，请核对重复收款并处理退款。');
                }
                return OrderFulfilmentResult::skipped('订单已发货。');
            }

            // Refund requests and completion take this same order lock. A retry
            // after restocking must not deliver against refunded or reserved funds.
            $fundingReceipt = $receipt ?? ($order->payment_no
                ? $order->paymentReceipts()->where('trade_no', $order->payment_no)->first() : null);
            $refunds = $order->refunds()->where(fn ($q) => $q->whereNull('payment_receipt_id')
                ->when($fundingReceipt, fn ($q) => $q->orWhere('payment_receipt_id', $fundingReceipt->id)));
            $refundReason = null;
            $fullyRefunded = false;
            if ((clone $refunds)->whereIn('status', ['requested', 'approved'])->exists()) {
                $refundReason = '该付款退款正在处理，暂不能分配卡密；请先完成或拒绝退款申请。';
            } else {
                $refunded = (string) (clone $refunds)->where('status', 'completed')->sum('amount');
                if (bccomp($refunded, '0', 2) > 0
                    && bccomp(bcsub((string) ($fundingReceipt?->amount ?? $order->total_amount), $refunded, 2), $order->total_amount, 2) < 0) {
                    $refundReason = '该付款已退款，剩余收款不足订单金额，不能再次发货。';
                    $fullyRefunded = bccomp($refunded, (string) ($fundingReceipt?->amount ?? $order->total_amount), 2) >= 0;
                }
            }
            if ($refundReason !== null) {
                if ($receipt && $fullyRefunded) return OrderFulfilmentResult::skipped('该笔付款已全部退回，不再分配卡密。');
                if ($receipt && !$receipt->review_resolved_at) {
                    $this->recordReview($order, $receipt, PaymentReviewCode::RefundConflict, $refundReason);
                }
                return OrderFulfilmentResult::refused($refundReason, $order, $source !== 'manual');
            }
            // A second verified receipt must remain visible even if the first
            // arrived while stock was unavailable and fulfilment succeeds now.
            if ($receipt) {
                foreach ($order->paymentReceipts()->where('id', '!=', $receipt->id)->whereNull('review_resolved_at')->get() as $other) {
                    $this->recordReview($order, $other, PaymentReviewCode::DuplicatePayment, '已发货订单收到另一笔付款，请核对重复收款并处理退款。');
                }
            }
            if (!in_array($order->status, $fromStatuses, true)) {
                $reason = "订单状态为 {$order->status}，支付到达时无法自动发货。";
                if ($receipt) {
                    $this->recordReview($order, $receipt, PaymentReviewCode::OrderState, $reason);
                    return OrderFulfilmentResult::orphaned($order, $reason);
                }
                return OrderFulfilmentResult::refused($reason);
            }

            $cards = $this->allocateCards($order);

            if ($cards->count() < $order->quantity) {
                if ($receipt) {
                    $this->recordReview($order, $receipt, PaymentReviewCode::InsufficientStock, "库存不足：需要 {$order->quantity} 张，可用 {$cards->count()} 张。");
                }
                Log::warning('Refusing to fulfil order, insufficient stock', [
                    'order_no' => $orderNo,
                    'source' => $source,
                    'required' => $order->quantity,
                    'available' => $cards->count(),
                ]);

                // Flagged for an operator when it is a gateway callback: the buyer
                // has paid and there is nothing to give them. On the manual path the
                // operator is already reading the reason in the 422.
                return OrderFulfilmentResult::refused(
                    "库存不足，无法发货：需要 {$order->quantity} 张，可用 {$cards->count()} 张。",
                    $order,
                    $source !== 'manual',
                );
            }

            // A late payment must reclaim a released coupon slot. Otherwise an
            // expired one-cent checkout and a new checkout can both redeem a
            // single-use coupon. Conflicts retain the gateway reference and alert
            // the operator; manual confirmation remains the explicit repair path.
            if ($order->status !== 'pending'
                && $order->coupon_id
                && bccomp((string) $order->discount_amount, '0.00', 2) > 0) {
                $claim = Coupon::where('id', $order->coupon_id);
                if ($source !== 'manual') {
                    $claim->where(fn ($q) => $q->where('max_uses', '<=', 0)
                        ->orWhereColumn('used_count', '<', 'max_uses'));
                }
                if ($claim->increment('used_count') === 0 && $source !== 'manual') {
                    $this->recordReview($order, $receipt, PaymentReviewCode::CouponUnavailable, '迟到付款的优惠券名额已被其他订单占用，请核对网关流水后人工处理。');
                    return OrderFulfilmentResult::refused(
                        '迟到付款的优惠券名额已被其他订单占用，请核对网关流水后人工处理。',
                        $order,
                        true,
                    );
                }
            }

            Card::whereIn('id', $cards->pluck('id'))->update([
                'status' => 'sold',
                'order_id' => $order->id,
                'sold_at' => now(),
            ]);

            $order->update(\Illuminate\Support\Arr::except($attributes($order), ['_receipt_details', '_gateway_payment_type']) + ['payment_review_reason' => null, 'payment_review_code' => null]);
            $order->paymentReceipts()->unresolvedReview()
                ->whereIn('review_code', PaymentReviewCode::resolvedByDelivery())
                ->update(['review_resolved_at' => now(), 'resolution_note' => '订单已完成发货。']);
            // Durable notification records commit with the delivered cards. A process
            // crash after commit cannot lose email delivery; callbacks do no SMTP I/O.
            $this->notifications->enqueuePaid($order);

            return OrderFulfilmentResult::fulfilled($order);
        });

        // The receipt, review reason and queued alert have already committed.
        // Logging supplements the operator-visible records without blocking callbacks.
        if ($result->needsOperatorAttention && $result->order) {
            Log::error('Verified payment could not be fulfilled', [
                'order_no' => $result->order->order_no,
                'status' => $result->order->status,
                'source' => $source,
                'reason' => $result->reason,
            ]);
        }

        return $result;
    }

    private function recordReview(Order $order, PaymentReceipt $receipt, PaymentReviewCode $code, string $reason): void
    {
        if (!$receipt->review_resolved_at) { $receipt->update(['review_reason' => $reason, 'review_code' => $code->value]); }
        $order->update(['payment_review_reason' => $reason, 'payment_review_code' => $code->value]);
        $this->notifications->enqueue('payment-review:'.$receipt->id, 'payment_review', $order, [
            'message' => "<b>⚠ 付款需要核对</b>\n订单号: <code>".e($order->order_no)."</code>\n"
                .'网关流水: '.e($receipt->trade_no)."\n金额: ".e($receipt->amount)."\n原因: ".e($reason),
        ]);
    }

    /**
     * The cards this order will deliver.
     *
     * Normally these are the ones locked at checkout. The fallback used to be dead
     * code kept as defence in depth; it is now the live path for the case that
     * matters most. Both entry points accept an 'expired' order, and expiring an
     * order is precisely what releases its locked cards — so a payment arriving
     * after the deadline finds nothing held and is served from unsold stock instead.
     *
     * If that stock is gone the caller refuses and raises an operator alert rather
     * than marking the order paid with nothing allocated, which would email the
     * buyer an empty card list.
     *
     * Both branches take a row lock: without it two operators confirming two orders
     * for the same product can be handed the same rows and sell one card twice.
     */
    private function allocateCards(Order $order): Collection
    {
        $held = Card::where('order_id', $order->id)
            ->where('status', 'locked')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($held->isNotEmpty()) {
            return $held;
        }

        return Card::where('product_id', $order->product_id)
            ->where('status', 'unsold')
            ->orderBy('id')
            ->limit($order->quantity)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Channel and amount verification for a gateway callback.
     */
    private function verifyGatewayPayment(Order $order, string $paidAmount, string $channel): ?string
    {
        // Reject legacy/corrupt zero-price orders too. A signed zero callback must
        // never deliver a paid product merely because its stored total is zero.
        if (bccomp((string) $order->total_amount, '0.01', 2) < 0 || $order->quantity < 1) {
            Log::warning('Invalid payable order rejected', ['order_no' => $order->order_no]);
            return '订单应付金额配置无效。';
        }
        // The callback must come from the gateway this order was sent to.
        $expectedChannel = match ($order->payment_method) {
            'alipay', 'wechat' => 'epay',
            'usdt_trc20', 'usdt_bep20', 'usdt_polygon' => 'epusdt',
            default => null,
        };
        if ($channel !== $expectedChannel) {
            Log::warning('Payment channel mismatch, refusing to deliver', [
                'order_no' => $order->order_no,
                'callback_channel' => $channel,
                'order_channel' => $expectedChannel,
            ]);

            return '支付渠道与订单不符。';
        }

        // Verify what was actually paid. Without this a buyer who can influence
        // the amount at the gateway pays a fen and receives the cards. An amount
        // we cannot read is treated as a failure, not waved through: delivering
        // an unverifiable payment is the exact failure this guards against.
        $paidAmount = trim($paidAmount);
        if (!preg_match(self::CNY_AMOUNT_PATTERN, $paidAmount)) {
            Log::warning('Payment callback carried no readable amount, refusing to deliver', [
                'order_no' => $order->order_no,
                'channel' => $channel,
                'raw_amount' => $paidAmount,
            ]);

            return '支付回调金额无法识别。';
        }

        // Compare decimal currency exactly: even a one-fen underpayment must fail.
        if (bccomp($paidAmount, (string) $order->total_amount, 2) < 0) {
            Log::warning('Underpaid callback rejected', [
                'order_no' => $order->order_no,
                'expected' => (string) $order->total_amount,
                'paid' => $paidAmount,
            ]);

            return '支付金额低于订单金额。';
        }

        return null;
    }
}
