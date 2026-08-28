<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
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

        $response = Http::timeout(15)
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
            throw new RuntimeException($data['message'] ?? 'USDT支付创建失败');
        }

        return [
            'payment_url' => $data['data']['payment_url'] ?? '',
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
