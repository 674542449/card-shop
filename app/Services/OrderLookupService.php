<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

class OrderLookupService
{
    public function search(string $email, string $password, ?string $orderNo = null, int $beforeId = PHP_INT_MAX): array
    {
        // Keep the service safe for callers outside the HTTP validators too.
        // Otherwise bcrypt accepts a suffix after byte 72, and an exact lookup
        // overwrites the indexed HMAC with credentials the buyer never chose.
        if (strlen($password) > 72) {
            return ['orders' => new Collection, 'has_more' => false, 'cursor' => null];
        }
        $key = Order::passwordKey($email, $password);
        $query = Order::whereRaw('lower(email) = ?', [mb_strtolower($email)]);
        if ($orderNo) {
            $candidate = (clone $query)->where('order_no', $orderNo)->first();
            $matches = new Collection;
            if ($candidate && $this->matches($password, $candidate->query_password)) {
                $candidate->update(['query_password_key' => $key]);
                $matches->push($candidate);
            } else {
                $this->pad($password);
            }

            return ['orders' => $matches, 'has_more' => false, 'cursor' => null];
        }
        // Opaque keyed lookup for new orders; legacy hashes are checked in bounded
        // batches and upgraded when matched. An order number always finds any age.
        $candidates = $query->where(fn ($q) => $q->where('query_password_key', $key)->orWhereNull('query_password_key'))
            ->where('id', '<', $beforeId)->orderByDesc('id')->limit(61)->get();
        $hasMore = $candidates->count() > 60;
        $batch = $candidates->take(60);
        $keyAuthenticated = null;
        $matches = $batch->filter(function (Order $order) use ($password, $key, &$keyAuthenticated) {
            if ($order->query_password_key === $key) {
                $keyAuthenticated ??= $this->matches($password, $order->query_password);
                if (! $keyAuthenticated) {
                    return false;
                }
            } elseif (! $this->matches($password, $order->query_password)) {
                return false;
            }
            if (! $order->query_password_key) {
                $order->update(['query_password_key' => $key]);
            }

            return true;
        })->values();
        if ($batch->isEmpty()) {
            $this->pad($password);
        }

        return ['orders' => $matches, 'has_more' => $hasMore, 'cursor' => $batch->last()?->id];
    }

    private function matches(string $password, ?string $hash): bool
    {
        if (! $hash || ! str_starts_with($hash, '$2y$')) {
            $this->pad($password);

            return false;
        }

        return Hash::check($password, $hash);
    }

    private function pad(string $password): void
    {
        static $hash;
        $hash ??= Hash::make(bin2hex(random_bytes(32)));
        Hash::check($password, $hash);
    }
}
