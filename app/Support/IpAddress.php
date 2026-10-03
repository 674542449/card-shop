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
}
