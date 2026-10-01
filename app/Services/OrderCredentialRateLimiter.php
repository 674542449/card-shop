<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

/** Buyer credentials have one guessing budget across browser and API endpoints. */
class OrderCredentialRateLimiter
{
    /** Return retry seconds when this attempt exceeds the budget. */
    public function attempt(string $email, string $ip): ?int
    {
        $emailHash = sha1(mb_strtolower($email));
        $limits = [
            'order-auth-pair|'.$emailHash.'|'.$ip => 5,
            'order-auth-email|'.$emailHash => 50,
            'order-auth-ip|'.$ip => 20,
        ];

        foreach ($limits as $key => $maximum) {
            // Increment atomically before deciding. A check-then-hit allows
            // simultaneous requests to all pass the final available attempt.
            if (RateLimiter::hit($key, 900) > $maximum) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return null;
    }
}
