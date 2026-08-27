<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

if (!function_exists('setting')) {
    /**
     * Get a setting value from cache (Redis), falling back to DB.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting:{$key}", 3600, function () use ($key, $default) {
            $setting = DB::table('settings')->where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
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
