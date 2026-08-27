<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    private const CACHE_KEY = 'settings:all';
    private const CACHE_TTL = 3600;

    /**
     * Get all settings grouped by group name, with Redis cache.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAll(): array
    {
        return Cache::store('redis')->remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()
                ->groupBy('group')
                ->map(fn ($items) => $items->pluck('value', 'key')->toArray())
                ->toArray();
        });
    }

    /**
     * Get a single setting value with cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::store('redis')->remember(
            "setting:{$key}",
            self::CACHE_TTL,
            fn () => Setting::where('key', $key)->value('value') ?? $default
        );
    }

    /**
     * Update a single setting and clear cache.
     */
    public function set(string $key, mixed $value, string $group = 'site'): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            // `group` is NOT NULL in the schema, so it must be supplied or inserting a
            // brand new key raises a not-null violation.
            ['value' => is_array($value) ? json_encode($value) : (string) $value, 'group' => $group]
        );

        $this->clearCache();
    }

    /**
     * Update multiple settings at once and clear cache.
     *
     * @param array<string, mixed> $data Key-value pairs to update.
     */
    public function setMany(array $data, string $group = 'site'): void
    {
        foreach ($data as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => is_array($value) ? json_encode($value) : (string) $value, 'group' => $group]
            );
        }

        $this->clearCache();
    }

    /**
     * Clear all setting caches.
     */
    private function clearCache(): void
    {
        try {
            $cache = Cache::store('redis');
            $cache->forget(self::CACHE_KEY);

            // Clear individual setting caches
            $keys = Setting::pluck('key');
            foreach ($keys as $key) {
                $cache->forget("setting:{$key}");
            }
        } catch (\Throwable $e) {
            // Unreachable cache is already effectively cleared.
        }

        // Also drop the flat map used by the setting() helper in views.
        settings_forget();
    }
}
