<?php

namespace App\Support;

class PaymentMethods
{
    /** The original gateway has no API parameter for selecting a network. */
    public static function supported(): array
    {
        $methods = ['alipay', 'wechat', 'usdt_trc20'];
        return setting('usdt_gateway', 'epusdt') === 'bepusdt'
            ? [...$methods, 'usdt_bep20', 'usdt_polygon'] : $methods;
    }

    /** Payment options exposed on storefront checkout forms, filtered by configured gateways. */
    public static function availableForCheckout(): array
    {
        $methods = [];
        if (setting('epay_api_url') && setting('epay_merchant_id') && setting('epay_merchant_key')) {
            $methods['alipay'] = '支付宝';
            $methods['wechat'] = '微信支付';
        }
        if (setting('epusdt_api_url') && setting('epusdt_api_token')) {
            $methods['usdt_trc20'] = 'USDT · TRC20';
            $methods['usdt_bep20'] = 'USDT · BEP20';
            $methods['usdt_polygon'] = 'USDT · Polygon';
        }
        if (empty($methods)) {
            $methods = ['alipay' => '支付宝', 'wechat' => '微信支付'];
        }
        $supported = self::supported();
        foreach (array_keys($methods) as $method) {
            if (!in_array($method, $supported, true)) {
                unset($methods[$method]);
            }
        }
        if (isset($methods['usdt_trc20']) && setting('usdt_gateway', 'epusdt') !== 'bepusdt') {
            $methods['usdt_trc20'] = 'USDT · 网关默认网络';
        }

        return $methods;
    }

    /** Human-readable payment channel label for receipts and cashier views. */
    public static function label(?string $method): string
    {
        if ($method === null || $method === '') {
            return '—';
        }
        $labels = [
            'alipay' => '支付宝',
            'wechat' => '微信支付',
            'usdt_trc20' => setting('usdt_gateway', 'epusdt') === 'bepusdt' ? 'USDT (TRC20)' : 'USDT',
            'usdt_bep20' => 'USDT (BEP20)',
            'usdt_polygon' => 'USDT (Polygon)',
            'manual' => '人工确认',
        ];

        return $labels[$method] ?? $method;
    }
}
