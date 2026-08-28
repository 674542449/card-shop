<?php

namespace App\Services;

use App\Models\Order;

/**
 * The outcome of one attempt to move an order into the paid state.
 *
 * Only `fulfilled` performed the transition. Callers use that to decide whether
 * this call — rather than a duplicate callback or a second operator click — is
 * the one that owns the side effects.
 */
class OrderFulfilmentResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?Order $order,
        public readonly ?string $reason,
    ) {
    }

    public static function fulfilled(Order $order): self
    {
        return new self('fulfilled', $order, null);
    }

    /**
     * The order was no longer pending when the row lock was taken. Nothing was
     * changed and nothing is wrong: a repeated gateway callback lands here.
     */
    public static function skipped(string $reason): self
    {
        return new self('skipped', null, $reason);
    }

    /**
     * The transition was refused. The reason is written for an operator, since
     * the manual path renders it straight into a 422.
     */
    public static function refused(string $reason): self
    {
        return new self('refused', null, $reason);
    }

    public function wasFulfilled(): bool
    {
        return $this->status === 'fulfilled';
    }
}
