<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EpusdtService
{
    private string $apiUrl;
    private string $apiToken;

    public function __construct()
    {
        $this->apiUrl = rtrim((string) setting('epusdt_api_url', ''), '/');
        $this->apiToken = (string) setting('epusdt_api_token', '');
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
        $params = [
            'order_id' => $order->order_no,
            'amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'notify_url' => url('/payment/epusdt/notify'),
            'redirect_url' => url("/order/detail/{$order->order_no}"),
        ];

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
     * Verify the HMAC signature from an EPUSDT payment callback.
     */
    public function verifyNotify(array $params): bool
    {
        if (empty($params['signature'])) {
            return false;
        }

        // Status 2 means the transaction is completed
        if (!isset($params['status']) || (int) $params['status'] !== 2) {
            return false;
        }

        $signature = $params['signature'];
        unset($params['signature']);

        $expectedSign = $this->generateSign($params, $this->apiToken);

        return hash_equals($expectedSign, $signature);
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

        return hash_hmac('md5', $signStr, $token);
    }
}
