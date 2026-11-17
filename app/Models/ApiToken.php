<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ApiToken extends Model
{
    protected $table = 'api_tokens';

    protected $fillable = [
        'name',
        'token',
        'is_active',
        'last_used_at',
        'requests_per_minute',
        'orders_per_minute',
        'max_pending_orders',
        'max_pending_quantity',
        'expires_at', 'scopes', 'allowed_ips',
    ];

    // The token column holds a hash, but it still has no place in list/detail JSON.
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime', 'scopes' => 'array', 'allowed_ips' => 'array',
            'requests_per_minute' => 'integer',
            'orders_per_minute' => 'integer',
            'max_pending_orders' => 'integer',
            'max_pending_quantity' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
