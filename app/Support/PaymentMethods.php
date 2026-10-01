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
}
