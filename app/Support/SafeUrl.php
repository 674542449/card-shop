<?php

namespace App\Support;

/** URL attributes need a scheme check as well as HTML escaping. */
class SafeUrl
{
    public static function http(mixed $value): ?string
    {
        if (! self::clean($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    public static function asset(mixed $value): ?string
    {
        if (! self::clean($value)) {
            return null;
        }

        // A single leading slash is local. Protocol-relative URLs and backslashes
        // have browser-specific host parsing and are deliberately excluded.
        return str_starts_with($value, '/') && ! str_starts_with($value, '//')
            ? $value : self::http($value);
    }

    public static function contact(mixed $value): ?string
    {
        if ($url = self::asset($value)) {
            return $url;
        }
        if (! self::clean($value)) {
            return null;
        }
        if (str_starts_with(strtolower($value), 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL)) {
            return $value;
        }

        return preg_match('/^tel:\+?[0-9().-]+$/iD', $value) ? $value : null;
    }

    private static function clean(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 2048
            && ! preg_match('/[\x00-\x20\x7f]/', $value) && ! str_contains($value, '\\');
    }
}
