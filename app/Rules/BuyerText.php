<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** PostgreSQL text and buyer identifiers require valid, printable UTF-8. */
class BuyerText implements ValidationRule
{
    public function __construct(private readonly bool $allowWhitespace = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (! mb_check_encoding($value, 'UTF-8')
            || preg_match($this->allowWhitespace ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value))) {
            $fail('字段不能包含无效编码或控制字符。');
        }
    }
}
