<?php

namespace App\Security;

final class SecretSettings
{
    public const KEYS = ['epay_merchant_key', 'epusdt_api_token', 'turnstile_secret_key',
        'telegram_bot_token', 'mail_password', 'baidu_push_token'];

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public static function decode(string $key, mixed $stored): mixed
    {
        if (is_string($stored) && SecretCipher::isEncrypted($stored) && ! self::contains($key)) {
            throw new \App\Exceptions\SecretStorageException;
        }
        return self::contains($key) && $stored !== null && $stored !== ''
            ? app(SecretCipher::class)->decrypt((string) $stored, 'setting:'.$key) : $stored;
    }

    public static function encode(string $key, mixed $value): mixed
    {
        return self::contains($key) && $value !== null && $value !== ''
            ? app(SecretCipher::class)->encrypt((string) $value, 'setting:'.$key) : $value;
    }

    /** Raw legacy credentials are replaced by a refusal marker, never cached. */
    public static function cacheMap(array $stored): array
    {
        foreach (self::KEYS as $key) {
            if (isset($stored[$key]) && $stored[$key] !== '' && ! SecretCipher::isEncrypted((string) $stored[$key])) {
                // Public settings and the conversion CLI must still bootstrap.
                // Accessing this credential invokes decrypt() and is refused until
                // secrets:encrypt seals the DB row and invalidates the cached map.
                $stored[$key] = SecretCipher::PREFIX.'conversion-required:';
            }
        }

        return $stored;
    }

    public static function forgetCaches(): void
    {
        foreach (['redis', config('cache.default')] as $store) {
            try {
                $cache = \Illuminate\Support\Facades\Cache::store($store);
                foreach (['settings:map', 'settings:all', 'settings:stored:v1', 'settings:all:stored:v1'] as $key) {
                    $cache->forget($key);
                }
                foreach (self::KEYS as $key) {
                    $cache->forget('setting:'.$key);
                }
            } catch (\Throwable) {
                // A disabled/unreachable Redis store must not block DB conversion.
            }
        }
        settings_memo(clear: true);
    }
}
