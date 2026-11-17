<?php

namespace App\Support;

/** BEpusdt signs JSON float64 values using Go's fmt.Sprintf("%v", value). */
final class UsdtSignatureValue
{
    public static function canonical(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (!is_float($value)) return (string) $value;
        $decimal = self::decimal($value);
        $negative = str_starts_with($decimal, '-');
        $unsigned = ltrim($decimal, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $all = $integer.$fraction;
        $digits = ltrim($all, '0');
        if ($digits === '') return $negative ? '-0' : '0';
        $exponent = strlen($integer) - strspn($all, '0') - 1;
        if ($exponent >= -4 && $exponent < 6) return $decimal;
        $digits = rtrim($digits, '0');
        return ($negative ? '-' : '').$digits[0].(strlen($digits) > 1 ? '.'.substr($digits, 1) : '')
            .'e'.($exponent < 0 ? '-' : '+').str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
    }

    public static function decimal(mixed $value): string
    {
        if (!is_float($value)) return (string) $value;
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        if (stripos($encoded, 'e') === false) return $encoded;
        [$mantissa, $exponent] = preg_split('/[eE]/', $encoded);
        $negative = str_starts_with($mantissa, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($mantissa, '-'), 2), 2, '');
        $digits = $integer.$fraction;
        $position = strlen($integer) + (int) $exponent;
        $decimal = $position <= 0 ? '0.'.str_repeat('0', -$position).$digits
            : ($position >= strlen($digits) ? $digits.str_repeat('0', $position - strlen($digits))
                : substr($digits, 0, $position).'.'.substr($digits, $position));
        if (str_contains($decimal, '.')) $decimal = rtrim(rtrim($decimal, '0'), '.');
        return ($negative ? '-' : '').$decimal;
    }
}
