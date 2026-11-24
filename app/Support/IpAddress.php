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

    /**
     * Split a comma/semicolon/newline-separated whitelist string into trimmed non-empty entries.
     *
     * @return list<string>
     */
    public static function parseWhitelistEntries(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,;，；]+/u', $raw) ?: [];
        $entries = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $entries[] = $trimmed;
            }
        }

        return $entries;
    }

    /**
     * Validate a single IPv4/IPv6 address or CIDR range (prefix >= 1 to prevent 0.0.0.0/0 or ::/0).
     */
    public static function isValidIpOrCidr(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (!str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        $segments = explode('/', $entry);
        if (count($segments) !== 2) {
            return false;
        }

        [$network, $prefixRaw] = $segments;
        if ($prefixRaw === '' || !ctype_digit($prefixRaw)) {
            return false;
        }

        $packed = @inet_pton(self::normalize($network));
        if ($packed === false) {
            return false;
        }

        $maxBits = strlen($packed) * 8;
        $prefix = (int) $prefixRaw;

        return $prefix >= 1 && $prefix <= $maxBits;
    }

    /**
     * Check whether an IP address matches a single IP or CIDR range entry.
     */
    public static function matchesEntry(string $ip, string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        $normalizedIp = self::normalize($ip);
        if (!str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false
                && self::normalize($entry) === $normalizedIp;
        }

        if (!self::isValidIpOrCidr($entry)) {
            return false;
        }

        [$network, $prefixRaw] = explode('/', $entry, 2);
        $ipBin = @inet_pton($normalizedIp);
        $netBin = @inet_pton(self::normalize($network));
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $prefix = (int) $prefixRaw;
        $fullBytes = intdiv($prefix, 8);
        $remBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }

        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether an IP address matches any valid IP/CIDR entry in a whitelist string.
     */
    public static function matchesWhitelist(string $ip, ?string $rawWhitelist): bool
    {
        foreach (self::parseWhitelistEntries($rawWhitelist) as $entry) {
            if (self::matchesEntry($ip, $entry)) {
                return true;
            }
        }

        return false;
    }
}
