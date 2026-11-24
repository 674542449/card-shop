<?php

namespace App\Support;

final class AdminPath
{
    public const DEFAULT_LENGTH = 24;

    private const FIRST_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const BODY_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * Storefront routes, static asset prefixes, and probe endpoints that the admin
     * console must never shadow.
     */
    public const RESERVED = [
        'api', 'css', 'js', 'storage', 'admin-assets', 'build', 'vendor',
        'order', 'product', 'products', 'category', 'categories', 'articles',
        'article', 'sitemap', 'robots', 'favicon', 'payment', 'upload',
        'health', 'up',
    ];

    private static ?string $memoized = null;

    /**
     * Generate a cryptographically random, high-strength URL path segment (~142.7 bits entropy).
     */
    public static function generate(int $length = self::DEFAULT_LENGTH): string
    {
        $length = max(16, min(32, $length));
        $firstMax = strlen(self::FIRST_ALPHABET) - 1;
        $bodyMax = strlen(self::BODY_ALPHABET) - 1;

        do {
            $segment = self::FIRST_ALPHABET[random_int(0, $firstMax)];
            for ($i = 1; $i < $length; $i++) {
                $segment .= self::BODY_ALPHABET[random_int(0, $bodyMax)];
            }
        } while (
            strtolower($segment) === 'admin'
            || in_array(strtolower($segment), self::RESERVED, true)
        );

        return $segment;
    }

    /**
     * Validate and normalize a candidate admin path segment.
     * Returns null when empty, malformed, or colliding with reserved storefront prefixes.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value, " \t\n\r\0\x0B/");
        if ($trimmed === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $trimmed)) {
            return null;
        }

        if (in_array(strtolower($trimmed), self::RESERVED, true)) {
            return null;
        }

        return $trimmed;
    }

    public static function storageFile(?string $override = null): string
    {
        return $override ?? storage_path('app/private/admin-path');
    }

    /**
     * Resolve the active admin path:
     * 1. Explicit valid ADMIN_PATH environment variable if configured.
     * 2. Persisted random high-strength installation path in storage/app/private/admin-path
     *    (atomically generated once on first boot if absent, never defaulting to "admin").
     */
    public static function resolve(?string $envOverride = null, ?string $storageFileOverride = null): string
    {
        $useMemo = $envOverride === null && $storageFileOverride === null;
        if ($useMemo && self::$memoized !== null) {
            return self::$memoized;
        }

        $configured = $envOverride ?? env('ADMIN_PATH');
        if ($configured !== null) {
            $normalized = self::normalize((string) $configured);
            if ($normalized !== null) {
                return $useMemo ? (self::$memoized = $normalized) : $normalized;
            }
        }

        $resolved = self::readOrCreatePersisted($storageFileOverride);

        return $useMemo ? (self::$memoized = $resolved) : $resolved;
    }

    /**
     * Rotate the persisted installation admin path and return the new segment.
     */
    public static function regenerate(?string $storageFileOverride = null): string
    {
        $file = self::storageFile($storageFileOverride);
        self::ensurePrivateDirectory(dirname($file));

        $generated = self::generate();
        $lockHandle = @fopen($file . '.lock', 'c+b');
        try {
            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_EX);
            }
            self::writeAtomically($file, $generated);
        } finally {
            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        }

        if ($storageFileOverride === null) {
            self::$memoized = null;
        }

        return $generated;
    }

    public static function resetCache(): void
    {
        self::$memoized = null;
    }

    private static function readOrCreatePersisted(?string $storageFileOverride = null): string
    {
        $file = self::storageFile($storageFileOverride);

        if (is_file($file) && !is_link($file)) {
            $existing = self::normalize((string) @file_get_contents($file));
            if ($existing !== null) {
                return $existing;
            }
        }

        self::ensurePrivateDirectory(dirname($file));

        $lockHandle = @fopen($file . '.lock', 'c+b');
        try {
            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_EX);
                clearstatcache(true, $file);
                if (is_file($file) && !is_link($file)) {
                    $existing = self::normalize((string) @file_get_contents($file));
                    if ($existing !== null) {
                        return $existing;
                    }
                }
            }

            $generated = self::generate();
            if (self::writeAtomically($file, $generated)) {
                return $generated;
            }
        } finally {
            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        }

        // Unwritable disk fallback: derive an unguessable 24-char segment from APP_KEY
        // so all workers still agree on one path and "/admin" is never implicitly exposed.
        return self::deriveFromAppKey();
    }

    /**
     * Create the private storage directory without restricting ancestor traversal
     * (such as storage/app, which nginx must traverse to reach storage/app/public).
     */
    private static function ensurePrivateDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $parent = dirname($directory);
        if ($parent !== $directory && !is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        @mkdir($directory, 0750, true);
        @chmod($directory, 0750);
    }

    private static function writeAtomically(string $file, string $segment): bool
    {
        $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $segment . "\n") === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        @chmod($file, 0640);

        return true;
    }

    private static function deriveFromAppKey(): string
    {
        $seed = (string) env('APP_KEY', '');
        if ($seed === '') {
            return self::generate();
        }

        $digest = hash_hmac('sha256', 'cardshop-admin-path-v1', $seed, true);
        $firstLen = strlen(self::FIRST_ALPHABET);
        $bodyLen = strlen(self::BODY_ALPHABET);

        $segment = self::FIRST_ALPHABET[ord($digest[0]) % $firstLen];
        for ($i = 1; $i < self::DEFAULT_LENGTH; $i++) {
            $segment .= self::BODY_ALPHABET[ord($digest[$i]) % $bodyLen];
        }

        return $segment;
    }
}
