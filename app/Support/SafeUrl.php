<?php

namespace App\Support;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/** URL attributes and outbound gateway endpoints need scheme and host checks as well as HTML escaping. */
class SafeUrl
{
    /**
     * Cloud metadata and link-local/unspecified/multicast ranges that must never be contacted in any environment.
     */
    private const ALWAYS_BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '169.254.0.0/16',
        '100.100.100.200/32',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        'fe80::/10',
        'fd00:ec2::254/128',
        'ff00::/8',
    ];

    /**
     * Loopback, RFC1918 private, carrier-grade NAT, and IPv6 ULA ranges blocked in production outbound gateways.
     */
    private const PRIVATE_OR_LOOPBACK_CIDRS = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        '::1/128',
        'fc00::/7',
    ];

    private const ALWAYS_BLOCKED_HOSTS = [
        'metadata',
        'metadata.google.internal',
        'instance-data',
    ];

    public static function http(mixed $value): ?string
    {
        if (! self::clean($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    public static function asset(mixed $value): ?string
    {
        if (! self::clean($value)) {
            return null;
        }

        // A single leading slash is local. Protocol-relative URLs and backslashes
        // have browser-specific host parsing and are deliberately excluded.
        return str_starts_with($value, '/') && ! str_starts_with($value, '//')
            ? $value : self::http($value);
    }

    public static function assetRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && $value !== '' && self::asset($value) === null) {
                $fail('图片地址必须是站内路径或有效的 HTTP/HTTPS URL。');
            }
        };
    }

    /**
     * Validates an outbound gateway base URL against SSRF / cloud metadata / internal network targets.
     */
    public static function outboundGateway(mixed $value, ?bool $allowLoopbackAndPrivate = null): ?string
    {
        $url = self::http($value);
        if ($url === null) {
            return null;
        }

        $allowLocal = $allowLoopbackAndPrivate ?? app()->environment(['local', 'testing']);
        $rawHost = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $host = rtrim($rawHost, '.');
        if ($host === '' || in_array($host, self::ALWAYS_BLOCKED_HOSTS, true) || str_ends_with($host, '.google.internal')) {
            return null;
        }

        if (! $allowLocal && ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal'))) {
            return null;
        }

        if (filter_var($rawHost, FILTER_VALIDATE_IP) !== false) {
            if (! self::isAllowedOutboundIp($rawHost, $allowLocal)) {
                return null;
            }
        } elseif (preg_match('/^(?:0x[0-9a-f]+|0[0-7]+|\d+)$/i', $host) === 1) {
            // Reject integer / hex / octal IPv4 encodings (e.g. 2852039166 for 169.254.169.254).
            return null;
        }

        return $url;
    }

    public static function isAllowedOutboundIp(string $ip, bool $allowLoopbackAndPrivate = false): bool
    {
        $normalized = IpAddress::normalize($ip);
        if (filter_var($normalized, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (IpUtils::checkIp($normalized, self::ALWAYS_BLOCKED_CIDRS)) {
            return false;
        }

        if (! $allowLoopbackAndPrivate && IpUtils::checkIp($normalized, self::PRIVATE_OR_LOOPBACK_CIDRS)) {
            return false;
        }

        return true;
    }

    /**
     * Resolves and pins DNS in production so a domain cannot resolve or rebind to metadata/private IPs.
     *
     * @param  (Closure(string): list<string>)|null  $resolver
     * @return array<string, mixed> Guzzle request options
     */
    public static function outboundHttpOptions(string $url, ?bool $allowLoopbackAndPrivate = null, ?Closure $resolver = null): array
    {
        $allowLocal = $allowLoopbackAndPrivate ?? app()->environment(['local', 'testing']);
        if (self::outboundGateway($url, $allowLocal) === null) {
            throw new \InvalidArgumentException('网关目标地址不允许连接内网或云元数据地址。');
        }

        $options = [];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options['curl'][CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            if (defined('CURLOPT_REDIR_PROTOCOLS')) {
                $options['curl'][CURLOPT_REDIR_PROTOCOLS] = 0;
            }
        }

        $rawHost = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($allowLocal && $resolver === null) {
            return $options;
        }
        if (filter_var($rawHost, FILTER_VALIDATE_IP) !== false) {
            return $options;
        }

        $resolve = $resolver ?? static function (string $host): array {
            $ips = [];
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (! empty($record['ip']) && is_string($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (! empty($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
            if ($ips === []) {
                $v4 = @gethostbynamel($host);
                if (is_array($v4)) {
                    $ips = $v4;
                }
            }

            return $ips;
        };

        $ips = $resolve(rtrim($rawHost, '.'));
        if ($ips === []) {
            throw new \InvalidArgumentException('无法解析网关域名。');
        }

        $verifiedIp = null;
        foreach ($ips as $candidate) {
            if (! is_string($candidate) || ! self::isAllowedOutboundIp($candidate, $allowLocal)) {
                throw new \InvalidArgumentException('网关域名解析到了不允许的内网或元数据地址。');
            }
            $verifiedIp ??= IpAddress::normalize($candidate);
        }

        if ($verifiedIp !== null && defined('CURLOPT_RESOLVE')) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
            $formattedIp = str_contains($verifiedIp, ':') ? "[{$verifiedIp}]" : $verifiedIp;
            $options['curl'][CURLOPT_RESOLVE] = [rtrim($rawHost, '.') . ":{$port}:{$formattedIp}"];
        }

        return $options;
    }

    public static function contact(mixed $value): ?string
    {
        if ($url = self::asset($value)) {
            return $url;
        }
        if (! self::clean($value)) {
            return null;
        }
        if (str_starts_with(strtolower($value), 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL)) {
            return $value;
        }

        return preg_match('/^tel:\+?[0-9().-]+$/iD', $value) ? $value : null;
    }

    private static function clean(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 2048
            && ! preg_match('/[\x00-\x20\x7f]/', $value) && ! str_contains($value, '\\');
    }
}
