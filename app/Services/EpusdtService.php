<?php

namespace App\Services;

use App\Models\Order;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Exceptions\CheckoutException;

class EpusdtService
{
    /**
     * Chain identifier as the gateway names it, keyed by the suffix of our own
     * payment_method values (usdt_trc20 -> trc20 -> usdt.trc20).
     *
     * Only BEpusdt understands these. Original epusdt has no trade_type field on
     * its create-transaction request, and because it verifies the signature against
     * the parameters it parsed rather than the raw body, sending one there makes
     * every payment fail signature verification. That is why the gateway flavour is
     * a setting rather than something inferred.
     *
     * Source: https://github.com/v03413/BEpusdt/blob/main/docs/trade-type.md
     */
    private const TRADE_TYPES = [
        'trc20' => 'usdt.trc20',
        'bep20' => 'usdt.bep20',
        'polygon' => 'usdt.polygon',
    ];

    private string $apiUrl;
    private string $apiToken;
    private string $flavour;

    public function __construct()
    {
        $this->apiUrl = rtrim((string) setting('epusdt_api_url', ''), '/');
        $this->apiToken = (string) setting('epusdt_api_token', '');
        $this->flavour = (string) setting('usdt_gateway', 'epusdt');
    }

    /**
     * Create a USDT payment transaction.
     *
     * @param Order  $order The order to create payment for.
     * @param string $chain Network chain: 'trc20', 'bep20', or 'polygon'.
     * @return array{payment_url: string, trade_id: string}
     *
     * @throws CheckoutException If the API call fails.
     */
    public function createPayment(Order $order, string $chain): array
    {
        $key = 'epusdt_payment:' . $order->order_no;
        return Cache::lock('epusdt_create:' . $order->order_no, 30)->block(5, function () use ($key, $order, $chain) {
            if ($cached = Cache::get($key)) {
                if (is_array($cached) && SafeUrl::http($cached['payment_url'] ?? null) !== null
                    && $this->validTradeId($cached['trade_id'] ?? null)
                    && (!$order->gateway_trade_no || $order->gateway_trade_no === $cached['trade_id'])) {
                    return $cached;
                }
                // An older release may already have cached an unsafe gateway URL.
                Cache::forget($key);
                Cache::forget('payment_url:' . $order->order_no);
            }
            $result = $this->createTransaction($order, $chain);
            if (empty($result['payment_url'])) {
                throw new CheckoutException('USDT支付接口未返回支付链接');
            }
            if (is_string($result['trade_id']) && strlen($result['trade_id']) <= 100 && $result['trade_id'] !== '') {
                $order->update(['gateway_trade_no' => $result['trade_id']]);
            }
            $ttl = max(1, (int) now()->diffInSeconds($order->expires_at, false));
            Cache::put($key, $result, $ttl);
            Cache::put('payment_url:' . $order->order_no, $result['payment_url'], $ttl);
            return $result;
        });
    }

    private function createTransaction(Order $order, string $chain): array
    {
        if (SafeUrl::http($this->apiUrl) === null) {
            throw new CheckoutException('USDT支付网关地址无效');
        }
        // Create responses have no protocol signature. A plaintext intermediary
        // could substitute the transaction/payment address before it is persisted.
        $scheme = strtolower((string) parse_url($this->apiUrl, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($this->apiUrl, PHP_URL_HOST), '[]'));
        if ($scheme !== 'https' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new CheckoutException('USDT支付须使用 HTTPS 网关，本地开发地址除外。');
        }
        if (!isset(self::TRADE_TYPES[$chain]) || ($this->flavour !== 'bepusdt' && $chain !== 'trc20')) {
            throw new CheckoutException('当前 USDT 网关不支持所选网络，请使用默认 USDT 入口。');
        }
        if ($this->apiUrl === '' || $this->apiToken === '') {
            throw new CheckoutException('USDT支付尚未配置');
        }

        $params = [
            'order_id' => $order->order_no,
            // A float, not a formatted string. The gateway signs the raw JSON values
            // it received, so the type has to survive the round trip: a JSON number
            // reaches Go as float64; BEpusdt's signature also needs Go's numeric
            // notation (scientific from 1e6), while
            // a JSON string would be rejected by a float64 field.
            'amount' => (float) $order->total_amount,
            'notify_url' => url('/payment/epusdt/notify'),
            'redirect_url' => url("/order/pay/{$order->order_no}"),
        ];

        // The three USDT options on the checkout form were decoration until now: the
        // chain was never sent, so the gateway let the payer pick whatever they liked.
        if ($this->flavour === 'bepusdt' && isset(self::TRADE_TYPES[$chain])) {
            $params['trade_type'] = self::TRADE_TYPES[$chain];
            $params['fiat'] = 'CNY';
        }

        $params['signature'] = $this->generateSign($params, $this->apiToken);

        $response = Http::timeout(15)->withoutRedirecting()
            ->post("{$this->apiUrl}/api/v1/order/create-transaction", $params);

        if (!$response->successful()) {
            Log::error('EPUSDT API request failed', [
                'status' => $response->status(),
                'order_no' => $order->order_no,
            ]);
            if ($response->serverError() || $response->redirect() || $response->status() === 408) {
                throw new \App\Exceptions\PaymentUncertainException('USDT付款创建结果待核对，请勿重复付款。');
            }
            throw new CheckoutException('USDT支付接口请求失败');
        }

        $data = $response->json();

        if (!is_array($data) || !array_key_exists('status_code', $data)) {
            throw new \App\Exceptions\PaymentUncertainException('USDT付款创建响应不完整，请勿重复付款。');
        }
        if (!is_int($data['status_code']) && !(is_string($data['status_code']) && preg_match('/^\d{1,6}$/D', $data['status_code']))) {
            Log::error('EPUSDT API returned invalid status', ['status' => $response->status(), 'order_no' => $order->order_no]);
            throw new \App\Exceptions\PaymentUncertainException('USDT付款创建状态无效，请勿重复付款。');
        }

        if (!is_array($data) || !in_array($data['status_code'] ?? null, [200, '200'], true)) {
            $statusCode = is_array($data) ? ($data['status_code'] ?? null) : null;
            $safeStatusCode = (is_int($statusCode) && $statusCode >= 0 && $statusCode <= 999999)
                || (is_string($statusCode) && preg_match('/^\d{1,6}$/D', $statusCode))
                ? (int) $statusCode : null;
            Log::error('EPUSDT API returned error', [
                'status' => $response->status(),
                'status_code' => $safeStatusCode,
                'order_no' => $order->order_no,
            ]);
            // Gateway failures can echo request signatures, private tokens or
            // internal details. Neither the buyer nor audit logs should receive them.
            throw new CheckoutException('USDT支付创建失败，请稍后重试或联系客服。');
        }

        $transaction = $data['data'] ?? null;
        $paymentUrl = SafeUrl::http(is_array($transaction) ? ($transaction['payment_url'] ?? null) : null);
        if ($paymentUrl === null) {
            throw new \App\Exceptions\PaymentUncertainException('USDT支付接口返回的支付链接无效，请先核对原付款。');
        }
        if (!$this->validTradeId($transaction['trade_id'] ?? null)) {
            throw new \App\Exceptions\PaymentUncertainException('USDT支付接口未返回有效交易号，请先核对原付款。');
        }
        // Old EPUSDT releases omit some fields. If the gateway does return them,
        // none may contradict the signed creation request or the local order.
        if ((array_key_exists('order_id', $transaction) && $transaction['order_id'] !== $order->order_no)
            || (array_key_exists('fiat', $transaction) && $transaction['fiat'] !== 'CNY')
            || (array_key_exists('amount', $transaction) && !$this->matchingAmount($transaction['amount'], (string) $order->total_amount))
            || (array_key_exists('status', $transaction) && !in_array($transaction['status'], [1, 2, '1', '2'], true))) {
            throw new \App\Exceptions\PaymentUncertainException('USDT支付接口返回的订单、金额或币种不匹配，请先核对原付款。');
        }

        return [
            'payment_url' => $paymentUrl,
            'trade_id' => $transaction['trade_id'],
        ];
    }

    private function validTradeId(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 100
            && !preg_match('/[\x00-\x20\x7f]/', $value);
    }

    private function matchingAmount(mixed $value, string $expected): bool
    {
        return (is_string($value) || is_int($value) || is_float($value))
            && preg_match('/^\d{1,18}(?:\.\d{1,8})?$/D', (string) $value)
            && bccomp((string) $value, $expected, 8) === 0;
    }

    /**
     * Generate the gateway's MD5-with-token signature for EPUSDT API.
     *
     * Steps:
     * 1. Remove empty values and the signature key.
     * 2. Sort parameters alphabetically by key.
     * 3. Concatenate as key=value& pairs (no trailing &).
     * 4. Append the API token and take MD5 (the protocol is not HMAC).
     */
    private function generateSign(array $params, string $token): string
    {
        unset($params['signature']);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);

        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            $encoded = $this->flavour === 'bepusdt' ? \App\Support\UsdtSignatureValue::canonical($value) : \App\Support\UsdtSignatureValue::decimal($value);
            $parts[] = "{$key}={$encoded}";
        }
        $signStr = implode('&', $parts);

        return md5($signStr . $token);
    }
}
