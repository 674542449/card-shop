<?php

namespace App\Support;

final class IpAddress
{
    public static function normalize(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) { return $address; }
        // Dual-stack servers can report an IPv4 peer as an IPv4-mapped IPv6 address.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }
        return inet_ntop($packed);
    }

    /**
     * Canonical /64 prefix for non-loopback IPv6 addresses so rotating addresses
     * within one /64 allocation cannot bypass browser reservation limits.
     */
    public static function subnet64(string $address): ?string
    {
        $normalized = self::normalize($address);
        if ($normalized === '::1') {
            return null;
        }
        $packed = @inet_pton($normalized);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
