<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** bcrypt must receive a valid string without a NUL terminator or ignored suffix. */
final class BcryptPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            $fail('密码不能包含无效编码或空字符。');
        } elseif (strlen($value) > 72) {
            $fail('密码最多 72 字节，请缩短密码。');
        }
    }
}
