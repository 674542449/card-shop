<?php

namespace App\Services;

use App\Models\Order;

class EpayService
{
    private string $apiUrl;
    private string $merchantId;
    private string $merchantKey;

    public function __construct()
    {
        $this->apiUrl = rtrim((string) setting('epay_api_url', ''), '/');
        $this->merchantId = (string) setting('epay_merchant_id', '');
        $this->merchantKey = (string) setting('epay_merchant_key', '');
    }

    /**
     * Create a payment URL for the given order.
     *
     * @param Order  $order   The order to create payment for.
     * @param string $payType Payment type: 'alipay' or 'wxpay'.
     * @return string The redirect URL for the payment page.
     */
    public function createPayment(Order $order, string $payType): string
    {
        $params = [
            'pid' => $this->merchantId,
            'type' => $payType,
            'out_trade_no' => $order->order_no,
            'notify_url' => url('/payment/epay/notify'),
            'return_url' => url('/payment/epay/return'),
            'name' => mb_substr($order->product->name, 0, 50),
            'money' => number_format((float) $order->total_amount, 2, '.', ''),
        ];

        $params['sign'] = $this->generateSign($params);
        $params['sign_type'] = 'MD5';

        return $this->apiUrl . '/submit.php?' . http_build_query($params);
    }

    /**
     * Generate an MD5 signature for the given parameters.
     *
     * Steps:
     * 1. Remove empty values and the sign/sign_type keys.
     * 2. Sort parameters alphabetically by key.
     * 3. Concatenate as key=value& pairs.
     * 4. Append merchant key.
     * 5. MD5 hash.
     */
    private function generateSign(array $params): string
    {
        // Remove empty values and reserved keys
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);

        // Sort by key alphabetically
        ksort($params);

        // Build query string
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = "{$key}={$value}";
        }
        $signStr = implode('&', $parts) . $this->merchantKey;

        return md5($signStr);
    }
}
