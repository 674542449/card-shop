<?php

namespace App\Support;

use App\Models\PaymentAttempt;

/** One bounded query for a page, with no gateway URLs or lease/response payloads. */
final class PaymentInitializationSummary
{
    public static function attach(iterable $orders): void
    {
        $orders = collect($orders);
        $attempts = PaymentAttempt::whereIn('order_id', $orders->pluck('id'))
            ->get(['order_id', 'status', 'error_code', 'updated_at'])->keyBy('order_id');
        foreach ($orders as $order) {
            $attempt = $attempts->get($order->id);
            $state = $attempt?->status;
            $order->setAttribute('payment_initialization', $state);
            $order->setAttribute('payment_initialization_detail', $attempt ? ['status' => $state,
                'error_code' => $attempt->error_code, 'updated_at' => $attempt->updated_at?->toIso8601String()] : null);
        }
    }
}
