<?php

namespace App\Services;

use App\Models\Order;

/**
 * The outcome of one attempt to move an order into the paid state.
 *
 * Only `fulfilled` performed the transition. Callers use that to decide whether
 * this call — rather than a duplicate callback or a second operator click — is
 * the one that owns the side effects.
 *
 * `needsOperatorAttention` is the separate axis: it marks the outcomes where
 * money has reached the gateway and no card was delivered. Those cannot be left
 * to a return value nobody reads, because nothing downstream will ever notice
 * them — see the alert in OrderFulfilmentService::transition().
 */
class OrderFulfilmentResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?Order $order,
        public readonly ?string $reason,
        public readonly bool $needsOperatorAttention = false,
    ) {
    }

    public static function fulfilled(Order $order): self
    {
        return new self('fulfilled', $order, null);
    }

    /**
     * The order was no longer in a fulfillable state when the row lock was taken.
     * Nothing was changed and nothing is wrong: a repeated gateway callback for an
     * order already marked paid lands here.
     */
    public static function skipped(string $reason): self
    {
        return new self('skipped', null, $reason);
    }

    /**
     * The transition was refused. The reason is written for an operator, since
     * the manual path renders it straight into a 422.
     *
     * $needsOperatorAttention separates the two kinds of refusal. A wrong channel
     * or an underpayment is the check doing its job and nothing is owed. Refusing
     * a correctly paid order because the stock is gone means the buyer's money is
     * at the gateway with nothing to show for it, and somebody has to be told.
     */
    public static function refused(
        string $reason,
        ?Order $order = null,
        bool $needsOperatorAttention = false,
    ): self {
        return new self('refused', $order, $reason, $needsOperatorAttention);
    }

    /**
     * A verified payment arrived for an order that can no longer be fulfilled and
     * was never paid — an order an operator closed, most likely.
     *
     * Nothing was delivered, the money is at the gateway, and no scheduled job or
     * admin screen will surface it on its own. This exists so that case is loud
     * instead of being acknowledged to the gateway as handled and forgotten.
     */
    public static function orphaned(Order $order, string $reason): self
    {
        return new self('orphaned', $order, $reason, true);
    }

    public function wasFulfilled(): bool
    {
        return $this->status === 'fulfilled';
    }
}
