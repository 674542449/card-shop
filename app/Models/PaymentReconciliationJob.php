<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReconciliationJob extends Model
{
    protected $guarded = [];
    protected $hidden = ['lease_token'];
    protected $attributes = ['status' => 'pending', 'attempts' => 0];
    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'queued_at' => 'datetime', 'reserved_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
