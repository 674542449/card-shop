<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** PostgreSQL text fields cannot store invalid UTF-8 or a zero byte. */
final class Utf8Text implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! self::isValid($value)) {
            $fail('文本不能包含无效编码或空字符。');
        }
    }

    public static function isValid(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }
}
