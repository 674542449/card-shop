<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** bcrypt authenticates only its first 72 bytes, including multibyte passwords. */
class QueryPasswordBytes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > 72) {
            $fail('查询密码不能超过72字节，中文等字符会占用多个字节');
        }
    }
}
