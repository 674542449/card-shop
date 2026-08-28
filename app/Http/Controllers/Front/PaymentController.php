<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Order;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * Handle EPay async notification callback.
     */
    public function epayNotify(Request $request): string
    {
        $params = $request->all();

        // Verify signature
        if (!$this->verifyEpaySignature($params)) {
            Log::warning('EPay notify: invalid signature', $params);
            return 'fail';
        }

        // Check trade status
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return 'fail';
        }

        // The merchant id must be ours. Cheap, and it rejects a callback replayed from
        // a different shop that happens to share the gateway.
        if ((string) ($params['pid'] ?? '') !== (string) setting('epay_merchant_id')) {
            Log::warning('EPay notify: merchant id mismatch', ['pid' => $params['pid'] ?? null]);
            return 'fail';
        }

        $orderNo = $params['out_trade_no'] ?? '';
        $tradeNo = $params['trade_no'] ?? '';

        if (!$orderNo) {
            return 'fail';
        }

        $this->processPayment($orderNo, $tradeNo, (string) ($params['money'] ?? ''), 'epay');

        return 'success';
    }

    /**
     * Handle EPay sync return callback — the buyer's own browser coming back from the
     * gateway.
     *
     * This endpoint is DISPLAY ONLY and must stay that way. The signature the site
     * mints for the outbound submit URL covers exactly the same field set this method
     * would verify, so the buyer already holds a valid signature for their own order:
     * it is handed to them in the redirect and rendered as the 前往支付 link. When this
     * method also marked orders paid, replaying that URL was enough to receive the card
     * secrets without paying. The server-to-server notify is the only authority, and it
     * is protected by a trade_status field the buyer cannot forge into the signature.
     */
    public function epayReturn(Request $request)
    {
        $params = $request->all();

        if (!$this->verifyEpaySignature($params)) {
            return redirect('/')->withErrors(['error' => '支付验证失败']);
        }

        $orderNo = $params['out_trade_no'] ?? '';

        if (!$orderNo) {
            return redirect('/');
        }

        $order = Order::where('order_no', $orderNo)->first();

        if (!$order) {
            return redirect('/')->withErrors(['error' => '订单不存在']);
        }

        // Read-only. Access is granted only for an order the server-to-server notify
        // has ALREADY marked paid, which is what makes this safe: a replayed return URL
        // for an unpaid order grants nothing, and a buyer can only ever hold a valid
        // signature for an order they created themselves.
        if ($order->isPaid()) {
            $verified = session('order_verified_ids', []);
            $verified[] = $order->id;
            session(['order_verified_ids' => array_values(array_unique($verified))]);

            return redirect('/order/detail/' . $order->order_no);
        }

        // Not paid yet — the notify may still be in flight. The payment page polls.
        return redirect('/order/pay/' . $order->order_no);
    }

    /**
     * Handle EPUSDT async notification callback.
     */
    public function epusdtNotify(Request $request): JsonResponse
    {
        $params = $request->all();

        // Verify signature
        if (!$this->verifyEpusdtSignature($params)) {
            Log::warning('EPUSDT notify: invalid signature', $params);
            return response()->json(['status' => 400, 'message' => 'invalid signature']);
        }

        // Check status
        $status = $params['status'] ?? 0;
        if ((int) $status !== 2) {
            // Status 2 = payment successful in EPUSDT
            return response()->json(['status' => 200]);
        }

        $orderNo = $params['order_id'] ?? '';
        $tradeNo = $params['trade_id'] ?? '';

        if (!$orderNo) {
            return response()->json(['status' => 400, 'message' => 'missing order_id']);
        }

        // `amount` is the fiat figure EPUSDT echoes back; `actual_amount` is the USDT
        // figure and would never match the order total.
        $this->processPayment($orderNo, $tradeNo, (string) ($params['amount'] ?? ''), 'epusdt');

        return response()->json(['status' => 200]);
    }

    /**
     * Verify EPay callback signature.
     */
    private function verifyEpaySignature(array $params): bool
    {
        $merchantKey = setting('epay_merchant_key');

        if (!$merchantKey) {
            return false;
        }

        $sign = $params['sign'] ?? '';
        unset($params['sign'], $params['sign_type']);

        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null) {
                $signStr .= $k . '=' . $v . '&';
            }
        }
        $signStr = rtrim($signStr, '&') . $merchantKey;

        return hash_equals(md5($signStr), $sign);
    }

    /**
     * Verify EPUSDT callback signature.
     */
    private function verifyEpusdtSignature(array $params): bool
    {
        $apiToken = setting('epusdt_api_token');

        if (!$apiToken) {
            return false;
        }

        $sign = $params['signature'] ?? '';
        unset($params['signature']);

        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null) {
                $signStr .= $k . '=' . $v . '&';
            }
        }
        $signStr = rtrim($signStr, '&') . $apiToken;

        return hash_equals(md5($signStr), $sign);
    }

    /**
     * Process a successful payment: mark order as paid, assign cards.
     */
    private function processPayment(string $orderNo, string $tradeNo, string $paidAmount, string $channel): void
    {
        try {
            /** @var Order|null $paidOrder */
            $paidOrder = DB::transaction(function () use ($orderNo, $tradeNo, $paidAmount, $channel) {
                $order = Order::where('order_no', $orderNo)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    return null; // Already processed or doesn't exist
                }

                // The callback must come from the gateway this order was sent to.
                $expectedChannel = str_starts_with((string) $order->payment_method, 'usdt_') ? 'epusdt' : 'epay';
                if ($channel !== $expectedChannel) {
                    Log::warning('Payment channel mismatch, refusing to deliver', [
                        'order_no' => $orderNo,
                        'callback_channel' => $channel,
                        'order_channel' => $expectedChannel,
                    ]);
                    return null;
                }

                // Verify what was actually paid. Without this a buyer who can influence
                // the amount at the gateway pays a fen and receives the cards. An amount
                // we cannot read is treated as a failure, not waved through: delivering
                // an unverifiable payment is the exact failure this guards against.
                if ($paidAmount === '' || !is_numeric($paidAmount)) {
                    Log::warning('Payment callback carried no readable amount, refusing to deliver', [
                        'order_no' => $orderNo,
                        'channel' => $channel,
                        'raw_amount' => $paidAmount,
                    ]);
                    return null;
                }

                // Tolerate a 1-fen rounding difference in the gateway's favour; reject
                // any real underpayment.
                if ((float) $paidAmount < (float) $order->total_amount - 0.011) {
                    Log::warning('Underpaid callback rejected', [
                        'order_no' => $orderNo,
                        'expected' => (string) $order->total_amount,
                        'paid' => $paidAmount,
                    ]);
                    return null;
                }

                // Mark order as paid
                $order->update([
                    'status' => 'paid',
                    'payment_no' => $tradeNo,
                    'paid_at' => now(),
                ]);

                // Mark locked cards as sold
                Card::where('order_id', $order->id)
                    ->where('status', 'locked')
                    ->update([
                        'status' => 'sold',
                        'sold_at' => now(),
                    ]);

                return $order;
            });

            // Delivery happens after the transaction commits, and only on the call that
            // actually performed the transition, so a duplicate gateway callback cannot
            // send the cards twice. Without this the buyer never receives anything.
            if ($paidOrder) {
                $paidOrder->refresh()->load(['product', 'cards']);
                $this->notifications->sendOrderEmail($paidOrder);
                $this->notifications->notifyNewOrder($paidOrder);
            }
        } catch (\Exception $e) {
            Log::error('Payment processing failed for order ' . $orderNo . ': ' . $e->getMessage());
        }
    }
}
