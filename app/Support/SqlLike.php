<?php

namespace App\Support;

final class SqlLike
{
    /**
     * Escapes SQL LIKE / ILIKE wildcard metacharacters (\, %, _) so user search input matches literally.
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public static function contains(string $value): string
    {
        return '%' . self::escape($value) . '%';
    }
}
