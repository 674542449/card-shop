<?php

namespace App\Services;

use App\Models\Order;
use App\Support\SafeUrl;

class EpayService
{
    /**
     * Create a payment URL for the given order.
     *
     * @param Order  $order   The order to create payment for.
     * @param string $payType Payment type: 'alipay' or 'wxpay'.
     * @return string The redirect URL for the payment page.
     */
    public function createPayment(Order $order, string $payType): string
    {
        $apiUrl = rtrim((string) setting('epay_api_url', ''), '/');
        $merchantId = (string) setting('epay_merchant_id', '');
        $merchantKey = (string) setting('epay_merchant_key', '');

        if ($apiUrl === '' || $merchantId === '' || $merchantKey === '') {
            throw new \App\Exceptions\CheckoutException('支付宝/微信支付尚未配置');
        }
        if (SafeUrl::outboundGateway($apiUrl) === null) {
            throw new \App\Exceptions\CheckoutException('支付宝/微信支付网关地址无效');
        }
        if ($order->quantity < 1
            || ! preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', (string) $order->total_amount)
            || bccomp((string) $order->total_amount, '0.01', 2) < 0) {
            throw new \App\Exceptions\CheckoutException('订单应付金额无效，无法发起支付。');
        }
        $params = [
            'pid' => $merchantId,
            'type' => $payType,
            'out_trade_no' => $order->order_no,
            'notify_url' => url('/payment/epay/notify'),
            'return_url' => url('/payment/epay/return'),
            'name' => mb_substr($order->displayName(), 0, 50),
            'money' => bcadd((string) $order->total_amount, '0', 2),
        ];

        $params['sign'] = $this->generateSign($params, $merchantKey);
        $params['sign_type'] = 'MD5';

        return $apiUrl . '/submit.php?' . http_build_query($params);
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
    private function generateSign(array $params, string $merchantKey): string
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
        $signStr = implode('&', $parts) . $merchantKey;

        return md5($signStr);
    }
}
