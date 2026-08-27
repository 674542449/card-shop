<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
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

        $orderNo = $params['out_trade_no'] ?? '';
        $tradeNo = $params['trade_no'] ?? '';

        if (!$orderNo) {
            return 'fail';
        }

        $this->processPayment($orderNo, $tradeNo);

        return 'success';
    }

    /**
     * Handle EPay sync return callback.
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

        $order = Order::where('order_no', $orderNo)->with('product')->first();

        if (!$order) {
            return redirect('/')->withErrors(['error' => '订单不存在']);
        }

        // Process the payment if not yet processed
        if ($order->status === 'pending') {
            $this->processPayment($orderNo, $params['trade_no'] ?? '');
            $order->refresh();
        }

        if ($order->isPaid()) {
            session(['order_verified_email' => $order->email]);
            return redirect('/order/detail/' . $order->order_no);
        }

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

        $this->processPayment($orderNo, $tradeNo);

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

        return md5($signStr) === $sign;
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

        return md5($signStr) === $sign;
    }

    /**
     * Process a successful payment: mark order as paid, assign cards.
     */
    private function processPayment(string $orderNo, string $tradeNo): void
    {
        try {
            DB::transaction(function () use ($orderNo, $tradeNo) {
                $order = Order::where('order_no', $orderNo)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    return; // Already processed or doesn't exist
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
            });
        } catch (\Exception $e) {
            Log::error('Payment processing failed for order ' . $orderNo . ': ' . $e->getMessage());
        }
    }
}
