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
