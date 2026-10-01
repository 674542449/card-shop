<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'review_resolved_at' => 'datetime', 'order_id' => 'integer', 'amount' => 'decimal:2', 'actual_amount' => 'decimal:8'];
    }
}
