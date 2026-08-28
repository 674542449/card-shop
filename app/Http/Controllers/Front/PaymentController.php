<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderFulfilmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(private readonly OrderFulfilmentService $fulfilment)
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
            Log::warning('EPay notify: invalid signature', $this->logContext($params, [
                'out_trade_no', 'trade_no', 'pid', 'trade_status', 'money', 'sign',
            ]));
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

        // Answering 'success' is an acknowledgement that we have taken responsibility
        // for this payment. If fulfilment threw — a deadlock, a lost connection — the
        // buyer has paid, the order is still pending, and the gateway's retry is the
        // only thing that would recover it. Do not switch that off with a false ack.
        return $this->processPayment($orderNo, $tradeNo, (string) ($params['money'] ?? ''), 'epay')
            ? 'success'
            : 'fail';
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
     * Handle the EPUSDT / BEpusdt async notification callback.
     *
     * Both send the same JSON body and sign it the same way, so one handler serves
     * either gateway. status is 1 pending, 2 paid, 3 expired; only 2 delivers.
     */
    public function epusdtNotify(Request $request): Response
    {
        $params = $request->all();

        // Verify signature
        if (!$this->verifyEpusdtSignature($params)) {
            // Answered 200 deliberately: a bad signature will never become good, so
            // asking for a retry would just repeat a rejected callback ten times. The
            // log line is the alert — a genuine one here means a misconfigured token.
            Log::warning('EPUSDT notify: invalid signature', $this->logContext($params, [
                'order_id', 'trade_id', 'status', 'amount', 'actual_amount', 'signature',
            ]));

            return response('invalid signature', 200)->header('Content-Type', 'text/plain');
        }

        $status = (int) ($params['status'] ?? 0);
        if ($status !== 2) {
            // 1 (pending) arrives every minute until the order resolves, and 3
            // (expired) once. Neither is retried by the gateway; acknowledge and
            // let the scheduled expiry job release the cards.
            return response('ok', 200)->header('Content-Type', 'text/plain');
        }

        $orderNo = $params['order_id'] ?? '';
        $tradeNo = $params['trade_id'] ?? '';

        if (!$orderNo) {
            Log::warning('EPUSDT notify: missing order_id', $this->logContext($params, [
                'trade_id', 'status', 'amount',
            ]));

            return response('missing order_id', 200)->header('Content-Type', 'text/plain');
        }

        // `amount` is the fiat figure EPUSDT echoes back; `actual_amount` is the USDT
        // figure and would never match the order total.
        // BEpusdt wants a 200 carrying the body "ok" to treat a success callback as
        // delivered, and retries with backoff otherwise — up to ten times over about
        // two hours. Original epusdt only checks the status code, so "ok" satisfies
        // both. A non-200 asks for that retry rather than leaving a paid order
        // stranded.
        return $this->processPayment($orderNo, $tradeNo, (string) ($params['amount'] ?? ''), 'epusdt')
            ? response('ok', 200)->header('Content-Type', 'text/plain')
            : response('fulfilment failed, please retry', 500)->header('Content-Type', 'text/plain');
    }

    /**
     * A bounded summary of an unauthenticated callback body, for logging.
     *
     * Both notify routes are CSRF-exempt and reachable by anyone, and both used to
     * pass the whole of $request->all() as log context BEFORE any verification. The
     * framework default writes one never-rotated storage/logs/laravel.log on the same
     * disk as everything else, so that was an open invitation to append megabytes per
     * request — and to bury the genuine signature-failure alert while doing it.
     *
     * Named keys only, each truncated: an attacker controls the key names too, so an
     * allowlist is the bound, not a length cap on whatever arrives.
     */
    private function logContext(array $params, array $keys): array
    {
        $context = ['ip' => request()->ip()];

        foreach ($keys as $key) {
            if (isset($params[$key]) && is_scalar($params[$key])) {
                $context[$key] = mb_substr((string) $params[$key], 0, 100);
            }
        }

        return $context;
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

        // Everything here is attacker-controlled. ?sign[]=1 makes this an array, and
        // hash_equals() throws a TypeError on a non-string — an uncaught 500 on an
        // unauthenticated endpoint, reachable by anyone who knows the URL.
        $sign = $params['sign'] ?? '';
        if (!is_string($sign)) {
            return false;
        }

        unset($params['sign'], $params['sign_type']);

        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            // Same reason: a nested array here would raise "Array to string conversion"
            // and silently sign the literal "Array".
            if (is_scalar($v) && $v !== '' && $v !== null) {
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

        // See verifyEpaySignature: a non-string signature would throw out of
        // hash_equals(), and a non-scalar value would be signed as the word "Array".
        $sign = $params['signature'] ?? '';
        if (!is_string($sign)) {
            return false;
        }

        unset($params['signature']);

        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if (is_scalar($v) && $v !== '' && $v !== null) {
                $signStr .= $k . '=' . $v . '&';
            }
        }
        $signStr = rtrim($signStr, '&') . $apiToken;

        return hash_equals(md5($signStr), $sign);
    }

    /**
     * Process a successful payment: mark order as paid, assign cards, deliver.
     *
     * The work itself lives in OrderFulfilmentService, which the admin's manual
     * confirmation also calls, so the amount check, the card allocation and the
     * delivery are the same code on both routes.
     *
     * Returns whether this callback was handled. The distinction the callers rely on:
     * a REFUSAL — wrong channel, underpayment, no stock — is permanent, is logged
     * inside the service, and counts as handled, so the gateway stops retrying
     * something we will never accept. A THROWN failure is transient, and reporting it
     * as handled would strand a paid order with nothing left to recover it.
     */
    private function processPayment(string $orderNo, string $tradeNo, string $paidAmount, string $channel): bool
    {
        try {
            $this->fulfilment->fulfilFromGateway($orderNo, $tradeNo, $paidAmount, $channel);

            return true;
        } catch (\Throwable $e) {
            Log::error('Payment processing failed for order ' . $orderNo . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return false;
        }
    }
}
