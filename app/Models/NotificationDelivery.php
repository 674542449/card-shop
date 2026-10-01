<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['payload', 'lease_token', 'dedupe_key'];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'reserved_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer'];
    }
}
