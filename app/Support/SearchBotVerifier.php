<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies official search engine crawlers via Forward-Confirmed Reverse DNS (FCrDNS).
 *
 * User-Agent alone is trivial to spoof and is only used as a zero-cost pre-filter so
 * normal requests never touch DNS. A crawler is exempted from honeypot IP banning ONLY
 * when its source IP reverse-resolves to an official crawler domain AND that hostname
 * forward-resolves back to the exact same IP address.
 */
class SearchBotVerifier
{
    /**
     * @var array<string, array{pattern: string, suffixes: list<string>}>
     */
    private const BOTS = [
        'google' => [
            'pattern' => '/(Googlebot|Google-InspectionTool|Storebot-Google|GoogleOther|AdsBot-Google|Mediapartners-Google)/i',
            'suffixes' => ['.googlebot.com', '.google.com', '.googleusercontent.com'],
        ],
        'bing' => [
            'pattern' => '/(bingbot|adidxbot|MicrosoftPreview)/i',
            'suffixes' => ['.search.msn.com'],
        ],
        'baidu' => [
            'pattern' => '/Baiduspider/i',
            'suffixes' => ['.crawl.baidu.com', '.crawl.baidu.jp'],
        ],
        'apple' => [
            'pattern' => '/Applebot/i',
            'suffixes' => ['.applebot.apple.com'],
        ],
    ];

    /** @var Closure(string): ?string */
    private Closure $reverseResolver;

    /** @var Closure(string): list<string> */
    private Closure $forwardResolver;

    /**
     * @param  (Closure(string): ?string)|null  $reverseResolver
     * @param  (Closure(string): list<string>)|null  $forwardResolver
     */
    public function __construct(?Closure $reverseResolver = null, ?Closure $forwardResolver = null)
    {
        $this->reverseResolver = $reverseResolver ?? static function (string $ip): ?string {
            $host = @gethostbyaddr($ip);

            return is_string($host) && $host !== '' && $host !== $ip ? $host : null;
        };

        $this->forwardResolver = $forwardResolver ?? static function (string $host): array {
            $ips = [];
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip']) && is_string($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (!empty($record['ipv6']) && is_string($record['ipv6'])) {
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
    }

    public function isVerifiedCrawler(string $ip, string $userAgent): bool
    {
        if ($userAgent === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $matchedFamily = null;
        $allowedSuffixes = [];
        foreach (self::BOTS as $family => $rule) {
            if (preg_match($rule['pattern'], $userAgent) === 1) {
                $matchedFamily = $family;
                $allowedSuffixes = $rule['suffixes'];
                break;
            }
        }

        if ($matchedFamily === null) {
            return false;
        }

        $normalizedIp = IpAddress::normalize($ip);
        $cacheKey = 'search_bot_fcrdns:' . $matchedFamily . ':' . md5($normalizedIp);

        try {
            return (bool) Cache::remember(
                $cacheKey,
                86400,
                fn () => $this->verifyDns($normalizedIp, $allowedSuffixes),
            );
        } catch (\Throwable) {
            return $this->verifyDns($normalizedIp, $allowedSuffixes);
        }
    }

    /**
     * @param  list<string>  $allowedSuffixes
     */
    private function verifyDns(string $normalizedIp, array $allowedSuffixes): bool
    {
        $host = ($this->reverseResolver)($normalizedIp);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $normalizedHost = strtolower(rtrim(trim($host), '.'));
        $suffixMatched = false;
        foreach ($allowedSuffixes as $suffix) {
            if (str_ends_with($normalizedHost, $suffix)) {
                $suffixMatched = true;
                break;
            }
        }

        if (!$suffixMatched) {
            return false;
        }

        $forwardIps = ($this->forwardResolver)($normalizedHost);
        foreach ($forwardIps as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                if (IpAddress::normalize($candidate) === $normalizedIp) {
                    return true;
                }
            }
        }

        return false;
    }
}
