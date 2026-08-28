<?php

namespace App\Services;

use App\Models\Card;
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
        );
    }

    /**
     * @param Closure(Order): ?string $verify     Null to proceed, a refusal reason to stop.
     * @param Closure(Order): array   $attributes Order columns to write on success.
     */
    private function transition(
        string $orderNo,
        string $source,
        Closure $verify,
        Closure $attributes,
    ): OrderFulfilmentResult {
        /** @var OrderFulfilmentResult $result */
        $result = DB::transaction(function () use ($orderNo, $source, $verify, $attributes) {
            // The row lock is taken before the pending check is decided, so a
            // gateway callback and an operator click arriving together serialise
            // here and the loser finds the order already paid.
            $order = Order::where('order_no', $orderNo)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$order) {
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

                return OrderFulfilmentResult::refused(
                    "库存不足，无法发货：需要 {$order->quantity} 张，可用 {$cards->count()} 张。"
                );
            }

            Card::whereIn('id', $cards->pluck('id'))->update([
                'status' => 'sold',
                'order_id' => $order->id,
                'sold_at' => now(),
            ]);

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
            $this->notifications->sendOrderEmail($order);
            $this->notifications->notifyNewOrder($order);
        }

        return $result;
    }

    /**
     * The cards this order will deliver.
     *
     * Normally these are the ones locked at checkout, and today that is always the
     * case: both entry points require the order to still be pending, and every path
     * that releases a pending order's locked cards moves the order out of pending in
     * the same transaction — the expiry job, the buyer-facing expiry in
     * Front\OrderController::pay, and the admin's close. So the fallback below is
     * unreachable as the code stands. It is kept as defence in depth, because the
     * failure it prevents — marking an order paid while allocating nothing, and
     * emailing the buyer an empty card list — is worse than a redundant query.
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
