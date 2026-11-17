<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $table = 'coupons';

    protected $fillable = [
        'code',
        'type',
        'value',
        'max_uses',
        'min_amount',
        'product_id',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'value' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Give back a use that was claimed at checkout but never turned into a sale.
     *
     * The claim happens when the order is CREATED — that placement is deliberate,
     * it is the concurrency gate that stops two simultaneous buyers both passing
     * isValid() on a single-use coupon. The consequence was that nothing gave the
     * use back when the order then expired or was closed, so max_uses counted
     * checkout attempts rather than sales: every abandoned cart permanently burned
     * a promotional slot, and three per IP per half hour was enough to exhaust a
     * launch coupon on purpose.
     *
     * Call this only on the branch where the status claim succeeded — the
     * conditional UPDATE guarantees exactly one caller gets there per order. The
     * used_count > 0 guard is belt and braces so no path can drive it negative.
     */
    public static function release(?int $couponId): void
    {
        if (!$couponId) {
            return;
        }

        static::where('id', $couponId)->where('used_count', '>', 0)->decrement('used_count');
    }

    /**
     * Check if the coupon is currently valid for use.
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Calculate the discount amount for a given order amount.
     * Enforces the same 0.01 payable floor as CheckoutPricingService.
     */
    public function calculateDiscount(float $amount): float
    {
        if (! is_finite($amount) || $amount < 0.01) {
            return 0.0;
        }
        $subtotal = bcadd(number_format($amount, 2, '.', ''), '0', 2);
        $minAmount = (string) ($this->min_amount ?? '0.00');
        $value = (string) ($this->value ?? '0.00');

        if (! preg_match('/^\d{1,16}\.\d{2}$/D', $minAmount)
            || ! preg_match('/^\d{1,16}\.\d{2}$/D', $value)
            || bccomp($value, '0.00', 2) <= 0
            || bccomp($subtotal, $minAmount, 2) < 0) {
            return 0.0;
        }

        if ($this->type === 'fixed') {
            $discount = $value;
        } elseif ($this->type === 'percent' && bccomp($value, '100.00', 2) <= 0) {
            $raw = bcdiv(bcmul($subtotal, $value, 4), '100', 4);
            $discount = bcadd(bcadd($raw, '0.0050', 4), '0', 2);
        } else {
            return 0.0;
        }

        $maxDiscount = bcsub($subtotal, '0.01', 2);
        if (bccomp($discount, $maxDiscount, 2) > 0) {
            $discount = $maxDiscount;
        }

        return bccomp($discount, '0.00', 2) > 0 ? (float) $discount : 0.0;
    }
}
