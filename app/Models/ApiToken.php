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
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
