<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    /**
     * Get a setting value by key, with Redis caching.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::store('redis')->rememberForever(
            "setting:{$key}",
            fn () => static::where('key', $key)->value('value') ?? $default
        );
    }

    /**
     * Set a setting value by key, updating the cache.
     */
    public static function set(string $key, mixed $value, string $group = 'site'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );

        Cache::store('redis')->forget("setting:{$key}");
    }
}
