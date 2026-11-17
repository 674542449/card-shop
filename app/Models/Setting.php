<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Models\Builders\SettingQueryBuilder;
use App\Security\SecretCipher;
use App\Security\SecretSettings;

class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    // Settings endpoints build an explicit masked projection. Generic model JSON
    // must never serialize a credential through the value accessor.
    protected $hidden = ['value'];

    public function newEloquentBuilder($query): SettingQueryBuilder
    {
        return new SettingQueryBuilder($query);
    }

    protected static function booted(): void
    {
        static::saving(function (Setting $setting): void {
            $raw = $setting->attributes['value'] ?? null;
            if (SecretSettings::contains((string) $setting->key) && $raw !== null && $raw !== ''
                && ! SecretCipher::isEncrypted((string) $raw)) {
                $setting->attributes['value'] = SecretSettings::encode($setting->key, $raw);
            }
        });
        static::saved(fn () => SecretSettings::forgetCaches());
        static::deleted(fn () => SecretSettings::forgetCaches());
    }

    public function getValueAttribute(mixed $value): mixed
    {
        return SecretSettings::decode((string) ($this->attributes['key'] ?? ''), $value);
    }

    public function setValueAttribute(mixed $value): void
    {
        $this->attributes['value'] = SecretSettings::encode((string) ($this->attributes['key'] ?? ''), $value);
    }

    /**
     * Get a setting value by key, with Redis caching.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $stored = settings_all()[$key] ?? null;

        return $stored === null ? $default : SecretSettings::decode($key, $stored);
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

        SecretSettings::forgetCaches();
    }
}
