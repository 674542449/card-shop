<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Coupon;
use App\Models\Order;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place an order becomes paid.
 *
 * Both the gateway callback and the admin's 手动确认支付 button run through
 * transition(), so the pending-only guard, card allocation and delivery cannot
 * drift apart the way the two hand-written copies did. The two entry points
 * differ in exactly one thing — what they verify before allowing the transition
 * — and that difference is written out below rather than expressed as a flag a
 * caller could pass wrongly.
 */
class OrderFulfilmentService
{
    public function __construct(private readonly NotificationService $notifications)
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
    ): OrderFulfilmentResult {
        return $this->transition(
            $orderNo,
            $channel,
            fn (Order $order) => $this->verifyGatewayPayment($order, $paidAmount, $channel),
            fn (Order $order) => [
                'status' => 'paid',
                'payment_no' => $tradeNo,
                'paid_at' => now(),
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
                ->whereIn('status', $fromStatuses)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                // Separate the benign miss from the expensive one. A repeat callback
                // on an order already paid is what this branch was written for. Any
                // OTHER status means a verified payment arrived for a sale we will
                // not complete — nothing downstream ever looks at that, so it has to
                // be raised here or it is lost entirely.
                $existing = Order::where('order_no', $orderNo)->first();

                if ($existing && $existing->status !== 'paid') {
                    return OrderFulfilmentResult::orphaned(
                        $existing,
                        "订单状态为 {$existing->status}，支付到达时无法自动发货。"
                    );
                }

                return OrderFulfilmentResult::skipped('订单不是待支付状态，无法确认支付。');
            }

            $refusal = $verify($order);
            if ($refusal !== null) {
                return OrderFulfilmentResult::refused($refusal);
            }

            $cards = $this->allocateCards($order);

            if ($cards->count() < $order->quantity) {
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

            Card::whereIn('id', $cards->pluck('id'))->update([
                'status' => 'sold',
                'order_id' => $order->id,
                'sold_at' => now(),
            ]);

            // Re-take the coupon use if this order had already been written off.
            // Expiring or closing an order gives its coupon use back, which is right
            // while the order is dead — but a late gateway payment brings it back to
            // life, and without this the buyer would keep the discount while the
            // coupon kept the slot. Deliberately not gated on max_uses: the sale is
            // already paid for, so this is restoring the count, not granting a use.
            if ($order->status !== 'pending'
                && $order->coupon_id
                && (float) $order->discount_amount > 0) {
                Coupon::where('id', $order->coupon_id)->increment('used_count');
            }

            $order->update($attributes($order));

            return OrderFulfilmentResult::fulfilled($order);
        });

        // Delivery happens after the commit and only on the call that performed
        // the transition. Inside the transaction a rollback would leave a buyer
        // holding secrets for an order that does not exist; outside it without
        // the guard, a repeated callback would mail them twice.
        if ($result->wasFulfilled()) {
            $order = $result->order;
            $order->refresh()->load(['product', 'cards']);

            // A failed send is not a failed sale — the cards are delivered and the
            // buyer can still read them at /order/query — but the operator has to
            // learn about it from something other than silence.
            if (!$this->notifications->sendOrderEmail($order)) {
                $this->notifications->sendTelegramNotification(
                    "<b>卡密邮件发送失败</b>\n订单号: <code>" . e($order->order_no) . "</code>\n"
                    . '邮箱: ' . e($order->email) . "\n请检查邮件配置，买家仍可在订单查询页自取卡密。"
                );
            }

            $this->notifications->notifyNewOrder($order);
        }

        // Money reached the gateway and no card left the shelf. Nothing else in the
        // system reports this: the callback is acked as handled (correctly — a retry
        // would land in the same state), no email is sent, and the admin order list
        // shows an ordinary unpaid order. Without this the first anyone hears of it
        // is the buyer asking where their cards are.
        if ($result->needsOperatorAttention && $result->order) {
            Log::error('Verified payment could not be fulfilled', [
                'order_no' => $result->order->order_no,
                'status' => $result->order->status,
                'source' => $source,
                'reason' => $result->reason,
            ]);

            $this->notifications->sendTelegramNotification(
                "<b>⚠ 支付已到账但未发货</b>\n订单号: <code>" . e($result->order->order_no) . "</code>\n"
                . '订单状态: ' . e($result->order->status) . "\n"
                . '支付渠道: ' . e($source) . "\n"
                . '原因: ' . e((string) $result->reason) . "\n"
                . '请核对网关流水后在后台手动确认支付。'
            );
        }

        return $result;
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
        // The callback must come from the gateway this order was sent to.
        $expectedChannel = str_starts_with((string) $order->payment_method, 'usdt_') ? 'epusdt' : 'epay';
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
        if ($paidAmount === '' || !is_numeric($paidAmount)) {
            Log::warning('Payment callback carried no readable amount, refusing to deliver', [
                'order_no' => $order->order_no,
                'channel' => $channel,
                'raw_amount' => $paidAmount,
            ]);

            return '支付回调金额无法识别。';
        }

        // Tolerate a 1-fen rounding difference in the gateway's favour; reject
        // any real underpayment.
        if ((float) $paidAmount < (float) $order->total_amount - 0.011) {
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
