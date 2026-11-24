<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Signed capability token for /order/pay/{order_no}.
 *
 * Allows a buyer who opens or shares their own payment link on another device —
 * or returns from a gateway redirect_url — to view and poll an unpaid order
 * without making bare order numbers readable across unrelated sessions.
 *
 * Never authorizes paid card delivery: paid HTML pages still require a verified
 * BrowserOrderCredentialProof session bound to email + query_password.
 */
final class OrderPayAccess
{
    public const QUERY_PARAM = 'sig';

    public static function signature(Order $order): string
    {
        return substr(hash_hmac('sha256', implode("\0", [
            'order-pay-access',
            (string) $order->id,
            (string) $order->order_no,
            mb_strtolower((string) $order->email),
            (string) $order->query_password,
            (string) $order->created_at?->toIso8601String(),
        ]), (string) config('app.key')), 0, 32);
    }

    public static function verify(Order $order, mixed $signature): bool
    {
        return is_string($signature)
            && preg_match('/^[0-9a-f]{32}$/D', $signature) === 1
            && hash_equals(self::signature($order), $signature);
    }

    public static function verifyRequest(Order $order, ?Request $request = null): bool
    {
        $request ??= request();

        return self::verify($order, $request->query(self::QUERY_PARAM));
    }

    public static function path(Order $order): string
    {
        return '/order/pay/' . $order->order_no . '?' . self::QUERY_PARAM . '=' . self::signature($order);
    }

    public static function url(Order $order): string
    {
        return url(self::path($order));
    }
}
