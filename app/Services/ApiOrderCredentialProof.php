<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/** Short-lived proof of credentials authenticated or accepted for a newly created API order. */
class ApiOrderCredentialProof
{
    public function has(Order $order, int $tokenId, string $email, string $password): bool
    {
        $context = $this->context($order, $tokenId, $email, $password);
        if ($context === null) {
            return false;
        }
        try {
            $proof = Cache::get($context['key']);
            return is_string($proof) && hash_equals($context['fingerprint'], $proof);
        } catch (\Throwable) {
            // Missing/unavailable proof requires the ordinary bounded bcrypt path.
            return false;
        }
    }

    /**
     * Call only after bcrypt authentication, or successful server checkout that
     * created this exact order/hash/HMAC from these accepted credentials.
     */
    public function remember(Order $order, int $tokenId, string $email, string $password): void
    {
        $context = $this->context($order, $tokenId, $email, $password);
        if ($context === null) {
            return;
        }
        try {
            // No plaintext password is cached. A password-hash change invalidates
            // the proof even if a legacy index has not yet been regenerated.
            Cache::put($context['key'], $context['fingerprint'], 600);
        } catch (\Throwable) {
            // Authentication already succeeded; caching is optional.
        }
    }

    private function context(Order $order, int $tokenId, string $email, string $password): ?array
    {
        if ($order->api_token_id !== $tokenId || strlen($password) > 72
            || str_contains($password, "\0") || ! mb_check_encoding($password, 'UTF-8')
            || mb_strtolower($order->email) !== mb_strtolower($email)
            || ! is_string($order->query_password_key) || ! is_string($order->query_password)
            || ! str_starts_with($order->query_password, '$2y$')) {
            return null;
        }
        $credentialKey = Order::passwordKey($email, $password);
        if (! hash_equals($order->query_password_key, $credentialKey)) {
            return null;
        }
        return [
            'key' => 'api-order-auth-proof:'.$tokenId.':'.$order->id.':'.$credentialKey,
            'fingerprint' => hash('sha256', $order->query_password),
        ];
    }
}
