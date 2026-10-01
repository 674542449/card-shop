<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Collection;

/** Browser access belongs to a verified order and its current credentials. */
class BrowserOrderCredentialProof
{
    public const LIFETIME_SECONDS = 1800;

    /** Call only after checkout accepted the credentials, or bcrypt verification. */
    public function grant(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        // Anonymous sessions become able to read the purchased secrets here.
        // Destroy the old ID so a planted/pre-authentication cookie cannot inherit
        // ownership. Keep the CSRF token for forms already open in another tab.
        session()->migrate(true);
        $proofs = session('order_buyer_proofs', []);
        $proofs = is_array($proofs) ? $proofs : [];
        $proofs = array_filter($proofs, fn ($proof) => is_array($proof)
            && is_int($proof['expires_at'] ?? null) && $proof['expires_at'] > now()->timestamp);

        foreach ($orders as $order) {
            $proofs[$order->id] = [
                'fingerprint' => self::fingerprint($order),
                'expires_at' => now()->timestamp + self::LIFETIME_SECONDS,
            ];
        }

        session(['order_buyer_proofs' => $proofs, 'order_verified_ids' => array_map('intval', array_keys($proofs))]);
    }

    public function has(Order $order): bool
    {
        $proofs = session('order_buyer_proofs', []);
        $proof = is_array($proofs) ? ($proofs[$order->id] ?? null) : null;
        if (is_array($proof) && is_string($proof['fingerprint'] ?? null)
            && is_int($proof['expires_at'] ?? null) && $proof['expires_at'] > now()->timestamp
            && hash_equals(self::fingerprint($order), $proof['fingerprint'])) {
            return true;
        }

        if (is_array($proofs)) {
            unset($proofs[$order->id]);
            session(['order_buyer_proofs' => $proofs, 'order_verified_ids' => array_map('intval', array_keys($proofs))]);
        }

        return false;
    }

    public static function fingerprint(Order $order): string
    {
        // Include the order identity as well as the actual bcrypt hash. Changing a
        // password/email or restoring a different order under the same database ID
        // must not keep an old buyer session authorized.
        return hash_hmac('sha256', implode("\0", [
            (string) $order->id, (string) $order->order_no, mb_strtolower($order->email),
            (string) $order->query_password, (string) $order->created_at?->toIso8601String(),
        ]), (string) config('app.key'));
    }
}
