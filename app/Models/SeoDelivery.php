<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoDelivery extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['lease_token', 'dedupe_key'];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'reserved_at' => 'datetime', 'sent_at' => 'datetime', 'health_acknowledged_at' => 'datetime'];
    }
}
