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
        $memo = settings_memo();

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

        settings_memo($memo);

        return $memo;
    }
}

if (!function_exists('settings_memo')) {
    /**
     * The per-request copy of the settings map.
     *
     * It lives in its own function purely so settings_forget() can reach it. As a
     * `static` inside settings_all() it was unreachable, which made settings_forget()
     * a half-truth: it dropped the shared cache but left this copy in place, so any
     * setting read after a write in the SAME request still returned the old value.
     * Harmless under PHP-FPM, where statics die with the request — but the function
     * is documented as "call after any write", and it should mean it.
     *
     * @param array<string, string|null>|null $set   Store this map.
     * @param bool                            $clear Forget the stored map.
     * @return array<string, string|null>|null       Null when nothing is stored.
     */
    function settings_memo(?array $set = null, bool $clear = false): ?array
    {
        static $memo = null;

        if ($clear) {
            $memo = null;
        } elseif ($set !== null) {
            $memo = $set;
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

if (!function_exists('asset_versioned')) {
    /**
     * asset() with a cache-busting version derived from the file's mtime.
     *
     * nginx serves everything under public/ with `Cache-Control: public, immutable`
     * and a 30-day expiry. Without a version in the URL that is a trap rather than an
     * optimisation: a returning visitor keeps the stylesheet they cached weeks ago and
     * never sees a deploy. Changing the file changes its mtime, which changes the URL,
     * which is exactly the condition `immutable` is safe under.
     */
    function asset_versioned(string $path): string
    {
        static $stamps = [];

        if (!array_key_exists($path, $stamps)) {
            $full = public_path($path);
            $stamps[$path] = is_file($full) ? (string) filemtime($full) : null;
        }

        $url = asset($path);

        return $stamps[$path] ? $url . '?v=' . $stamps[$path] : $url;
    }
}

if (!function_exists('settings_forget')) {
    /**
     * Drop the cached settings map. Call after any write to the settings table.
     */
    function settings_forget(): void
    {
        // Both copies, or the name is a lie: the shared cache AND this request's own.
        settings_memo(clear: true);

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
