<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

if (!function_exists('settings_all')) {
    /**
     * Load every setting as a key => value map.
     *
     * The whole table is cached under one key rather than one key per setting. That
     * keeps a page render to a single lookup, and — critically — it means the caller's
     * $default is never written into the cache, so two callers asking for the same key
     * with different fallbacks can no longer poison each other.
     *
     * Every failure path degrades to an empty map instead of throwing: a Redis outage
     * or a not-yet-migrated database must not turn every page of the site into a 500.
     *
     * @return array<string, string|null>
     */
    function settings_all(): array
    {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        $load = fn (): array => DB::table('settings')->pluck('value', 'key')->all();

        try {
            $memo = Cache::remember('settings:map', 3600, $load);
        } catch (\Throwable $e) {
            // Cache backend unavailable — read straight through to the database.
            try {
                $memo = $load();
            } catch (\Throwable $e) {
                // Database unavailable or not migrated yet.
                $memo = [];
            }
        }

        return $memo;
    }
}

if (!function_exists('setting')) {
    /**
     * Get a setting value, falling back to $default when unset or blank.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function setting(string $key, mixed $default = null): mixed
    {
        $value = settings_all()[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }
}

if (!function_exists('settings_forget')) {
    /**
     * Drop the cached settings map. Call after any write to the settings table.
     */
    function settings_forget(): void
    {
        try {
            Cache::forget('settings:map');
        } catch (\Throwable $e) {
            // Nothing to do — a cache we cannot reach is already effectively cleared.
        }
    }
}

if (!function_exists('format_price')) {
    /**
     * Format a price value as Chinese Yuan.
     *
     * @param float $price
     * @return string
     */
    function format_price(float $price): string
    {
        return '¥' . number_format($price, 2, '.', '');
    }
}

if (!function_exists('generate_order_no')) {
    /**
     * Generate a unique order number.
     *
     * Format: YYYYMMDDHHmmss + 5 random digits
     * Example: 2026082814305212345
     *
     * @return string
     */
    function generate_order_no(): string
    {
        return date('YmdHis') . str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }
}
