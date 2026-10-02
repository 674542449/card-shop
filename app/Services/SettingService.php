<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Security\SecretSettings;

class SettingService
{
    private const CACHE_KEY = 'settings:all:stored:v1';
    private const CACHE_TTL = 3600;

    /**
     * Get all settings grouped by group name, with Redis cache.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stored = Cache::store('redis')->remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return DB::table('settings')->get()->groupBy('group')
                ->map(fn ($items) => SecretSettings::cacheMap($items->pluck('value', 'key')->all()))->all();
        });
        foreach ($stored as &$group) {
            foreach ($group as $key => &$value) {
                $value = SecretSettings::decode($key, $value);
            }
            unset($value);
        }
        unset($group);

        return $stored;
    }

    /**
     * Get a single setting value with cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
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
        SecretSettings::forgetCaches();
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
