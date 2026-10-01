<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'reserved_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
