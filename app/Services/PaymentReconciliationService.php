<?php

namespace App\Services;

use App\Models\Order;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaymentReconciliationService
{
    public function sync(Order $order): array
    {
        return Cache::lock('reconcile:'.$order->id, 45)->block(1, function () use ($order) {
            try {
                if (in_array($order->payment_method, ['alipay', 'wechat'], true)) {
                    $base = $this->secureBase('epay_api_url');
                    $pid = (string) setting('epay_merchant_id');
                    $secret = (string) setting('epay_merchant_key');
                    if (! $pid || ! $secret) {
                        throw new \DomainException('易支付查询凭证未配置。');
                    }
                    // Compatibility API returns no signature; use only the configured
                    // HTTPS gateway, and validate merchant/order/amount/type again.
                    $response = Http::timeout(15)->withoutRedirecting()->get($base.'/api.php', [
                        'act' => 'order', 'pid' => $pid, 'key' => $secret, 'out_trade_no' => $order->order_no,
                    ]);
                    $data = $response->json();
                    if (! $response->successful() || ! is_array($data) || !in_array($data['code'] ?? null, [1, '1'], true)) {
                        throw new \DomainException('网关订单查询失败。');
                    }
                    if (($data['out_trade_no'] ?? '') !== $order->order_no || (string) ($data['pid'] ?? '') !== $pid
                        || ($data['type'] ?? '') !== ($order->payment_method === 'alipay' ? 'alipay' : 'wxpay')
                        || (array_key_exists('fiat', $data) && $data['fiat'] !== 'CNY')) {
                        throw new \DomainException('网关返回订单或商户不匹配，未确认支付。');
                    }
                    $paid = in_array($data['status'] ?? null, [1, '1'], true);
                    $amount = $data['money'] ?? null;
                    $trade = $data['trade_no'] ?? null;
                    $channel = 'epay';
                    $details = [];
                } elseif (str_starts_with($order->payment_method, 'usdt_')) {
                    $base = $this->secureBase('epusdt_api_url');
                    $cached = Cache::get('epusdt_payment:'.$order->order_no, []);
                    $trade = $order->gateway_trade_no ?: $order->payment_no ?: ($cached['trade_id'] ?? null);
                    if (!is_string($trade) || $trade === '' || strlen($trade) > 100 || preg_match('/[\x00-\x20\x7f]/', $trade)) {
                        throw new \DomainException('缺少网关交易号，请使用有效回调或人工核对。');
                    }
                    if (setting('usdt_gateway', 'epusdt') === 'bepusdt') {
                        $response = Http::timeout(15)->withoutRedirecting()->post($base.'/api/v1/pay/info', ['trade_id' => $trade]);
                        $data = $response->json('data');
                        if (! $response->successful() || $response->json('status_code') !== 200 || ! is_array($data)
                            || ($data['order_id'] ?? '') !== $order->order_no || ($data['trade_id'] ?? '') !== $trade
                            || ($data['fiat'] ?? '') !== 'CNY') {
                            throw new \DomainException('USDT 查询订单、币种或商户订单号不匹配。');
                        }
                        $paid = in_array($data['status'] ?? null, [2, '2'], true);
                        $amount = $data['money'] ?? null;
                    } else {
                        $response = Http::timeout(15)->withoutRedirecting()->get($base.'/pay/check-status/'.rawurlencode($trade));
                        $info = Http::timeout(15)->withoutRedirecting()->get($base.'/pay/checkout-counter-resp/'.rawurlencode($trade));
                        $data = $info->json('data');
                        $statusData = $response->json('data');
                        if (! $response->successful() || ! $info->successful() || !in_array($response->json('status_code'), [200, '200'], true)
                            || !in_array($info->json('status_code'), [200, '200'], true) || ! is_array($data) || !is_array($statusData)
                            || ($data['trade_id'] ?? '') !== $trade
                            || (array_key_exists('trade_id', $statusData) && $statusData['trade_id'] !== $trade)
                            || (array_key_exists('order_id', $data) && $data['order_id'] !== $order->order_no)
                            || (array_key_exists('fiat', $data) && $data['fiat'] !== 'CNY')) {
                            throw new \DomainException('当前 EPUSDT 版本不支持安全查询，请使用回调或人工核对。');
                        }
                        $paid = in_array($statusData['status'] ?? null, [2, '2'], true);
                        $amount = $data['amount'] ?? null;
                    }
                    $channel = 'epusdt';
                    $details = ['currency' => 'USDT'];
                    if (isset($data['actual_amount']) && is_scalar($data['actual_amount']) && preg_match('/^\d{1,16}(?:\.\d{1,8})?$/D', (string) $data['actual_amount'])) {
                        $details['actual_amount'] = (string) $data['actual_amount'];
                    }
                    if (isset($data['network']) && is_string($data['network']) && strlen($data['network']) <= 40) {
                        $details['network'] = $data['network'];
                    }
                } else {
                    throw new \DomainException('人工支付订单无需查询网关。');
                }
                if ($paid) {
                    if (! is_scalar($amount) || ! is_string($trade) || strlen($trade) > 100 || $trade === '') {
                        throw new \DomainException('网关查询缺少有效金额或交易号。');
                    }
                    $result = app(OrderFulfilmentService::class)->fulfilFromGateway($order->order_no, $trade, (string) $amount, $channel, $details);
                    if ($result->status === 'refused' && ! $result->needsOperatorAttention) {
                        throw new \DomainException('网关付款金额或渠道不匹配，未确认支付。');
                    }
                }
                $order->update(['reconciled_at' => now(), 'reconciliation_error' => null]);

                return ['message' => $paid ? '网关已付款，系统已核对并更新订单。' : '网关尚未确认付款。', 'paid' => $paid];
            } catch (\Throwable $e) {
                // Transport exceptions may contain merchant secrets in the URL.
                $message = $e instanceof \DomainException ? $e->getMessage() : '网关查询失败，请检查接口和配置。';
                $order->update(['reconciled_at' => now(), 'reconciliation_error' => $message]);
                throw new RuntimeException($message);
            }
        });
    }

    private function secureBase(string $key): string
    {
        $url = rtrim((string) setting($key, ''), '/');
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if (SafeUrl::http($url) === null || !$host
            || (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true))) {
            throw new \DomainException('自动对账须使用 HTTPS 网关，本地开发地址除外。');
        }

        return $url;
    }
}
