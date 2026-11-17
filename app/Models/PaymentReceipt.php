<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PaymentReceipt extends Model
{
    protected $guarded = ['id'];

    public function scopeUnresolvedReview(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNotNull('review_code')->orWhereNotNull('review_reason'))->whereNull('review_resolved_at');
    }

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'review_resolved_at' => 'datetime', 'order_id' => 'integer', 'amount' => 'decimal:2', 'actual_amount' => 'decimal:8'];
    }
}
