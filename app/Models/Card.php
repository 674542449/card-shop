<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    protected $table = 'cards';

    protected $fillable = [
        'product_id',
        'order_id',
        'content',
        'status',
        'locked_at',
        'sold_at',
    ];

    // A card is the sold secret, not ordinary catalog metadata. Serialization
    // must opt in at an explicitly authorized delivery/admin boundary; merely
    // eager-loading a relation must never disclose stock to a lower-trust caller.
    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeUnsold(Builder $query): Builder
    {
        return $query->where('status', 'unsold');
    }

    public function scopeSold(Builder $query): Builder
    {
        return $query->where('status', 'sold');
    }

    public function scopeLocked(Builder $query): Builder
    {
        return $query->where('status', 'locked');
    }
}
