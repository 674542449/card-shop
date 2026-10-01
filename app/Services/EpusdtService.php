<?php

namespace App\Services;

use App\Models\Order;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
     * @throws RuntimeException If the API call fails.
     */
    public function createPayment(Order $order, string $chain): array
    {
        $key = 'epusdt_payment:' . $order->order_no;
        return Cache::lock('epusdt_create:' . $order->order_no, 30)->block(5, function () use ($key, $order, $chain) {
            if ($cached = Cache::get($key)) {
                if (is_array($cached) && SafeUrl::http($cached['payment_url'] ?? null) !== null) {
                    return $cached;
                }
                // An older release may already have cached an unsafe gateway URL.
                Cache::forget($key);
                Cache::forget('payment_url:' . $order->order_no);
            }
            $result = $this->createTransaction($order, $chain);
            if (empty($result['payment_url'])) {
                throw new RuntimeException('USDT支付接口未返回支付链接');
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
            throw new RuntimeException('USDT支付网关地址无效');
        }
        if (!isset(self::TRADE_TYPES[$chain]) || ($this->flavour !== 'bepusdt' && $chain !== 'trc20')) {
            throw new RuntimeException('当前 USDT 网关不支持所选网络，请使用默认 USDT 入口。');
        }
        if ($this->apiUrl === '' || $this->apiToken === '') {
            throw new RuntimeException('USDT支付尚未配置');
        }

        $params = [
            'order_id' => $order->order_no,
            // A float, not a formatted string. The gateway signs the raw JSON values
            // it received, so the type has to survive the round trip: a JSON number
            // reaches Go as float64 and stringifies the same way PHP does here, while
            // a JSON string would be rejected by a float64 field.
            'amount' => (float) $order->total_amount,
            'notify_url' => url('/payment/epusdt/notify'),
            'redirect_url' => url("/order/pay/{$order->order_no}"),
        ];

        // The three USDT options on the checkout form were decoration until now: the
        // chain was never sent, so the gateway let the payer pick whatever they liked.
        if ($this->flavour === 'bepusdt' && isset(self::TRADE_TYPES[$chain])) {
            $params['trade_type'] = self::TRADE_TYPES[$chain];
        }

        $params['signature'] = $this->generateSign($params, $this->apiToken);

        $response = Http::timeout(15)->withoutRedirecting()
            ->post("{$this->apiUrl}/api/v1/order/create-transaction", $params);

        if (!$response->successful()) {
            Log::error('EPUSDT API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'order_no' => $order->order_no,
            ]);
            throw new RuntimeException('USDT支付接口请求失败');
        }

        $data = $response->json();

        if (!isset($data['status_code']) || (int) $data['status_code'] !== 200) {
            Log::error('EPUSDT API returned error', [
                'response' => $data,
                'order_no' => $order->order_no,
            ]);
            $message = $data['message'] ?? null;
            throw new RuntimeException(is_string($message) && mb_strlen($message) <= 200 ? $message : 'USDT支付创建失败');
        }

        $paymentUrl = SafeUrl::http($data['data']['payment_url'] ?? null);
        if ($paymentUrl === null) {
            throw new RuntimeException('USDT支付接口返回的支付链接无效');
        }

        return [
            'payment_url' => $paymentUrl,
            'trade_id' => $data['data']['trade_id'] ?? '',
        ];
    }

    /**
     * Generate HMAC-MD5 signature for EPUSDT API.
     *
     * Steps:
     * 1. Remove empty values and the signature key.
     * 2. Sort parameters alphabetically by key.
     * 3. Concatenate as key=value& pairs (no trailing &).
     * 4. HMAC-MD5 with the API token.
     */
    private function generateSign(array $params, string $token): string
    {
        unset($params['signature']);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);

        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = "{$key}={$value}";
        }
        $signStr = implode('&', $parts);

        return md5($signStr . $token);
    }
}
