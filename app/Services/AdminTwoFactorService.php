<?php

namespace App\Services;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;

class AdminTwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) { $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT); }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) { $secret .= self::ALPHABET[bindec($chunk)]; }
        return $secret;
    }

    /** RFC 6238 / RFC 4226, SHA-1, 30 seconds; digits parameter also supports official test vectors. */
    public function code(string $secret, int $timestamp, int $digits = 6): string
    {
        $bits = '';
        foreach (str_split($secret) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) { throw new \InvalidArgumentException('Invalid TOTP secret'); }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) { if (strlen($chunk) === 8) { $key .= chr(bindec($chunk)); } }
        $counter = intdiv($timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function matchingCounter(string $secret, string $code, ?int $last = null): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) { return null; }
        $current = intdiv(now()->timestamp, 30);
        foreach ([$current, $current - 1, $current + 1] as $counter) {
            if ($counter >= 0 && ($last === null || $counter > $last) && hash_equals($this->code($secret, $counter * 30), $code)) { return $counter; }
        }
        return null;
    }

    public function recoveryHash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(str_replace('-', '', $code)), (string) config('app.key'));
    }

    public function recoveryCodes(): array
    {
        return array_map(fn () => implode('-', str_split(strtoupper(bin2hex(random_bytes(10))), 5)), range(1, 8));
    }

    /** Row locking makes authenticator counters and recovery codes single use even across concurrent requests. */
    public function consume(Admin $admin, string $code, ?\Closure $after = null): bool
    {
        return DB::transaction(function () use ($admin, $code, $after) {
            $locked = Admin::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            if (!$locked->is_active || !$locked->two_factor_confirmed_at || !$locked->two_factor_secret || !hash_equals($admin->password, $locked->password) ||
                !hash_equals((string) $admin->two_factor_revision, (string) $locked->two_factor_revision)) { return false; }
            $counter = $this->matchingCounter($locked->two_factor_secret, $code, $locked->two_factor_last_counter);
            if ($counter !== null) {
                $locked->forceFill(['two_factor_last_counter' => $counter])->save();
                if ($after) { $after($locked); }
                return true;
            }
            if (!preg_match('/^[A-Fa-f0-9-]{20,23}$/D', $code)) { return false; }
            $hashes = $locked->two_factor_recovery_codes ?? [];
            foreach ($hashes as $index => $hash) {
                if (hash_equals($hash, $this->recoveryHash($code))) {
                    unset($hashes[$index]);
                    $locked->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                    if ($after) { $after($locked); }
                    return true;
                }
            }
            return false;
        });
    }
}
