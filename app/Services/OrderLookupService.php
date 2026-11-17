<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class OrderLookupService
{
    public const MAX_BCRYPT_CHECKS = 8;

    public function search(string $email, string $password, ?string $orderNo = null, int $beforeId = PHP_INT_MAX): array
    {
        // Keep the service safe for callers outside the HTTP validators too.
        // Otherwise bcrypt accepts a suffix after byte 72, and an exact lookup
        // overwrites the indexed HMAC with credentials the buyer never chose.
        if (strlen($password) > 72 || str_contains($password, "\0") || ! mb_check_encoding($password, 'UTF-8')
            || ! mb_check_encoding($email, 'UTF-8') || str_contains($email, "\0")
            || ($orderNo !== null && (! mb_check_encoding($orderNo, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $orderNo)))) {
            return ['orders' => new Collection, 'has_more' => false, 'cursor' => null];
        }
        $key = Order::passwordKey($email, $password);
        $query = Order::whereRaw('lower(email) = ?', [mb_strtolower($email)]);
        $hashChecks = 0;
        if ($orderNo) {
            $candidate = (clone $query)->where('order_no', $orderNo)->first();
            $matches = new Collection;
            if ($candidate && $this->matches($password, $candidate->query_password, $hashChecks)) {
                $candidate->update(['query_password_key' => $key]);
                $matches->push($candidate);
            } elseif (! $candidate) {
                $this->pad($password);
            }

            return ['orders' => $matches, 'has_more' => false, 'cursor' => null];
        }
        // Opaque keyed lookup for new orders; legacy hashes are checked in bounded
        // batches and upgraded when matched. An order number always finds any age.
        $candidates = $query->where(fn ($q) => $q->where('query_password_key', $key)->orWhereNull('query_password_key'))
            ->where('id', '<', $beforeId)->orderByDesc('id')->limit(61)->get();
        $hasMore = $candidates->count() > 60;
        $authenticatedHashes = [];
        $matches = new Collection;
        $cursor = null;
        foreach ($candidates->take(60) as $order) {
            // The indexed HMAC selects candidates; it is not an authorization
            // result. A password can have changed while a legacy index remains.
            // Checking one order and trusting all others with that index grants
            // the old password access to the order whose hash was revoked.
            $hash = (string) $order->query_password;
            if (! array_key_exists($hash, $authenticatedHashes)) {
                $authenticated = $this->matches($password, $order->query_password, $hashChecks);
                if ($authenticated === null) {
                    // Leave this candidate for the next page. A cursor at the end
                    // of the fetched batch would silently skip unverified orders.
                    $hasMore = true;
                    break;
                }
                $authenticatedHashes[$hash] = $authenticated;
            }
            $cursor = $order->id;
            if (! $authenticatedHashes[$hash]) {
                continue;
            }
            if (! $order->query_password_key) {
                $order->update(['query_password_key' => $key]);
            }

            $matches->push($order);
        }
        if ($candidates->isEmpty()) {
            $this->pad($password);
        }

        return ['orders' => $matches, 'has_more' => $hasMore, 'cursor' => $cursor];
    }

    private function matches(string $password, ?string $hash, int &$hashChecks): ?bool
    {
        if (! $hash || ! str_starts_with($hash, '$2y$') || (password_get_info($hash)['algoName'] ?? null) !== 'bcrypt') {
            if ($hashChecks >= self::MAX_BCRYPT_CHECKS) {
                return null;
            }
            $hashChecks++;
            $this->pad($password);

            return false;
        }

        // A proof is bound to this exact bcrypt hash and this exact submitted
        // password. It never lets one order's successful hash stand in for another
        // order, and password changes select a different cache key immediately.
        // Cache only success: wrong guesses still pay the ordinary bcrypt cost.
        $proofKey = 'order-bcrypt-proof:'.hash_hmac('sha256', $hash."\0".$password, (string) config('app.key'));
        try {
            if (Cache::get($proofKey) === true) {
                return true;
            }
        } catch (\Throwable) {
            // Cache is an optional optimization, never a substitute for checking.
        }
        if ($hashChecks >= self::MAX_BCRYPT_CHECKS) {
            return null;
        }
        $hashChecks++;
        try {
            $matches = Hash::check($password, $hash);
        } catch (\Throwable) {
            $this->pad($password);
            return false;
        }
        if ($matches) {
            try {
                Cache::put($proofKey, true, 600);
            } catch (\Throwable) {
                // The real bcrypt verification already succeeded.
            }
        }
        return $matches;
    }

    private function pad(string $password): void
    {
        static $hash;
        try {
            $hash ??= Cache::rememberForever('order-auth-timing-padding', fn () => Hash::make(bin2hex(random_bytes(32))));
        } catch (\Throwable) {
            $hash ??= Hash::make(bin2hex(random_bytes(32)));
        }
        Hash::check($password, $hash);
    }
}
