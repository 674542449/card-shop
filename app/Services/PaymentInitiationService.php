<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Exceptions\PaymentInProgressException;
use App\Exceptions\PaymentUncertainException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentInitiationService
{
    public function __construct(private readonly EpayService $epay, private readonly EpusdtService $usdt) {}

    public function initiate(Order $order, string $method): array
    {
        $token = (string) Str::uuid();
        $cached = DB::transaction(function () use ($order, $method, $token) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->isPending() || $fresh->expires_at->isPast() || $fresh->payment_no) {
                throw new CheckoutException('订单状态不允许支付，请查询订单或等待付款核对。');
            }
            if ($method !== $fresh->payment_method) {
                throw new CheckoutException('支付方式与订单不匹配。');
            }
            $attempt = PaymentAttempt::firstOrCreate(['order_id' => $fresh->id], ['method' => $method]);
            if ($attempt->status === 'succeeded') {
                return $attempt->response_payload;
            }
            if ($attempt->status === 'uncertain') {
                throw new PaymentUncertainException('付款创建结果待核对，请查询原订单或联系客服，请勿重复付款。');
            }
            if ($attempt->status === 'processing') {
                if ($attempt->lease_expires_at?->isFuture()) {
                    throw new PaymentInProgressException('付款正在创建，请稍后刷新原订单。');
                }
                // A lost worker might already have created the remote transaction.
                $attempt->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted', 'lease_token' => null, 'lease_expires_at' => null]);
                return ['_uncertain' => true];
            }
            $attempt->update(['status' => 'processing', 'lease_token' => $token,
                'lease_expires_at' => now()->addSeconds(60), 'error_code' => null]);
            return null;
        }, 3);
        if ($cached !== null) {
            if (isset($cached['_uncertain'])) {
                throw new PaymentUncertainException('付款创建结果待核对，请联系客服，请勿重复付款。');
            }
            return $cached;
        }

        // Network I/O runs after the reservation/lease transaction has committed.
        try {
            $result = match ($method) {
                'alipay' => ['url' => $this->epay->createPayment($order, 'alipay')],
                'wechat' => ['url' => $this->epay->createPayment($order, 'wxpay')],
                'usdt_trc20' => $this->usdt->createPayment($order, 'trc20'),
                'usdt_bep20' => $this->usdt->createPayment($order, 'bep20'),
                'usdt_polygon' => $this->usdt->createPayment($order, 'polygon'),
                default => throw new CheckoutException('不支持的支付方式'),
            };
            PaymentAttempt::where('order_id', $order->id)->where('lease_token', $token)->firstOrFail()
                ->update(['status' => 'succeeded', 'response_payload' => $result, 'lease_token' => null, 'lease_expires_at' => null]);
            return $result;
        } catch (\Throwable $e) {
            $uncertain = $e instanceof PaymentUncertainException || ! $e instanceof CheckoutException;
            PaymentAttempt::where('order_id', $order->id)->where('lease_token', $token)
                ->update(['status' => $uncertain ? 'uncertain' : 'failed', 'error_code' => $uncertain ? 'gateway_uncertain' : 'gateway_rejected',
                    'lease_token' => null, 'lease_expires_at' => null]);
            if ($uncertain) {
                throw new PaymentUncertainException('付款创建结果待核对，请查询原订单或联系客服，请勿重复付款。', 0, $e);
            }
            throw $e;
        }
    }
}
