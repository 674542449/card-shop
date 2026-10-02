<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** bcrypt authenticates only its first 72 bytes, including multibyte passwords. */
class QueryPasswordBytes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            $fail('查询密码不能包含无效编码或空字符。');
        } elseif (strlen($value) > 72) {
            $fail('查询密码不能超过72字节，中文等字符会占用多个字节');
        }
    }
}
