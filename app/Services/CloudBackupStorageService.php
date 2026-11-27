<?php

namespace App\Services;

use App\Support\SafeUrl;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Multi-target cloud object storage backup uploader & connectivity tester.
 *
 * Supports AWS S3, Cloudflare R2, Alibaba Cloud OSS (native OSS4-HMAC-SHA256),
 * and Tencent Cloud COS (S3-compatible AWS4-HMAC-SHA256). Multiple targets can
 * be enabled simultaneously with per-target fault isolation and constant-memory
 * streaming uploads (up to 5 GB per archive).
 */
class CloudBackupStorageService
{
    public const MAX_SINGLE_UPLOAD_BYTES = 5 * 1024 * 1024 * 1024; // 5 GiB

    /** @var array<string, string> */
    public const PROVIDERS = [
        's3' => 'AWS S3',
        'r2' => 'Cloudflare R2',
        'oss' => '阿里云 OSS',
        'cos' => '腾讯云 COS',
    ];

    /** @return list<string> */
    public static function providerKeys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? $provider;
    }

    /** @return list<string> */
    public function enabledProviders(): array
    {
        $enabled = [];
        foreach (self::providerKeys() as $provider) {
            if (in_array((string) setting("backup_{$provider}_enabled", '0'), ['1', 'true'], true)) {
                $enabled[] = $provider;
            }
        }

        return $enabled;
    }

    public function hasEnabledProvider(): bool
    {
        return $this->enabledProviders() !== [];
    }

    /**
     * Load effective configuration for one provider, optionally merged with unsaved form overrides.
     *
     * @param  array<string, mixed>|null  $override
     * @return array{enabled: bool, endpoint: string, region: string, bucket: string, prefix: string, access_key: string, secret_key: string, path_style: bool}
     */
    public function config(string $provider, ?array $override = null): array
    {
        if (! isset(self::PROVIDERS[$provider])) {
            throw new RuntimeException('不支持的对象存储类型。');
        }

        $storedSecret = (string) setting("backup_{$provider}_secret_key", '');
        $overrideSecret = $override !== null && array_key_exists('secret_key', $override)
            ? trim((string) ($override['secret_key'] ?? ''))
            : null;
        $secretKey = ($overrideSecret === null || $overrideSecret === '' || $overrideSecret === '********')
            ? $storedSecret
            : $overrideSecret;

        $read = static function (string $field, string $default = '') use ($provider, $override): string {
            if ($override !== null && array_key_exists($field, $override)) {
                return trim((string) ($override[$field] ?? ''));
            }

            return trim((string) setting("backup_{$provider}_{$field}", $default));
        };

        $pathStyleRaw = $override !== null && array_key_exists('path_style', $override)
            ? $override['path_style']
            : setting("backup_{$provider}_path_style", '0');

        return [
            'enabled' => in_array((string) setting("backup_{$provider}_enabled", '0'), ['1', 'true'], true),
            'endpoint' => $read('endpoint'),
            'region' => $read('region', $provider === 'r2' ? 'auto' : ''),
            'bucket' => $read('bucket'),
            'prefix' => $read('prefix'),
            'access_key' => $read('access_key'),
            'secret_key' => $secretKey,
            'path_style' => $pathStyleRaw === true || in_array((string) $pathStyleRaw, ['1', 'true'], true),
        ];
    }

    /**
     * Validate and resolve request URL, host, canonical URI, and signing parameters for a provider.
     *
     * @param  array{endpoint: string, region: string, bucket: string, prefix: string, access_key: string, secret_key: string, path_style: bool}  $cfg
     * @return array{url: string, host: string, canonical_uri: string, signing_region: string, object_key: string, scheme: string, provider: string, bucket: string}
     */
    public function resolveTarget(string $provider, array $cfg, string $objectName = ''): array
    {
        if (! isset(self::PROVIDERS[$provider])) {
            throw new RuntimeException('不支持的对象存储类型。');
        }

        $label = self::label($provider);
        $bucket = trim((string) ($cfg['bucket'] ?? ''));
        $region = trim((string) ($cfg['region'] ?? ''));
        $endpoint = rtrim(trim((string) ($cfg['endpoint'] ?? '')), '/');
        $prefix = self::normalizePrefix((string) ($cfg['prefix'] ?? ''));
        $accessKey = trim((string) ($cfg['access_key'] ?? ''));
        $secretKey = (string) ($cfg['secret_key'] ?? '');

        if ($bucket === '' || ! preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) || str_contains($bucket, '..')) {
            throw new RuntimeException("{$label} 存储桶名称无效，仅允许小写字母、数字、连字符和点号。");
        }
        if ($accessKey === '' || $secretKey === '') {
            throw new RuntimeException("{$label} 未填写完整的 AccessKey / SecretKey。");
        }

        $signingRegion = $region;
        if ($provider === 'r2') {
            $signingRegion = $region !== '' ? $region : 'auto';
            if ($endpoint === '') {
                throw new RuntimeException('Cloudflare R2 必须填写 Endpoint（例如 https://<AccountID>.r2.cloudflarestorage.com）。');
            }
        } elseif ($provider === 's3') {
            if ($signingRegion === '') {
                throw new RuntimeException('AWS S3 必须填写区域 Region（例如 us-east-1、ap-southeast-1）。');
            }
            if ($endpoint === '') {
                $endpoint = "https://s3.{$signingRegion}.amazonaws.com";
            }
        } elseif ($provider === 'oss') {
            if ($signingRegion === '') {
                throw new RuntimeException('阿里云 OSS 必须填写区域 Region（例如 cn-hangzhou 或 oss-cn-hangzhou）。');
            }
            $signingRegion = preg_replace('/^oss-/i', '', $signingRegion) ?: $signingRegion;
            if ($endpoint === '') {
                $endpoint = "https://oss-{$signingRegion}.aliyuncs.com";
            }
        } elseif ($provider === 'cos') {
            if ($signingRegion === '') {
                throw new RuntimeException('腾讯云 COS 必须填写区域 Region（例如 ap-guangzhou、ap-shanghai）。');
            }
            if ($endpoint === '') {
                $endpoint = "https://cos.{$signingRegion}.myqcloud.com";
            }
        }

        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $signingRegion)) {
            throw new RuntimeException("{$label} 区域 Region 格式无效。");
        }

        $validatedBase = SafeUrl::outboundGateway($endpoint);
        if ($validatedBase === null) {
            throw new RuntimeException("{$label} Endpoint 地址无效或指向不允许的内网/元数据地址。");
        }

        $parts = parse_url($validatedBase);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $rawHost = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $isLoopback = in_array(trim($rawHost, '[]'), ['127.0.0.1', 'localhost', '::1'], true);
        if ($scheme !== 'https' && ! (app()->environment(['local', 'testing']) && $isLoopback)) {
            throw new RuntimeException("{$label} Endpoint 必须使用 HTTPS 协议。");
        }

        $objectKey = $objectName !== ''
            ? ($prefix !== '' ? $prefix . '/' . $objectName : $objectName)
            : '';
        $encodedKey = $objectKey !== '' ? self::encodePath($objectKey) : '';

        // R2 defaults to path-style; S3 allows virtual-hosted (default) or path-style;
        // Alibaba Cloud OSS & Tencent Cloud COS enforce virtual-hosted style.
        $usePathStyle = match ($provider) {
            'r2' => true,
            's3' => (bool) ($cfg['path_style'] ?? false),
            'oss', 'cos' => false,
        };

        // Never build a virtual-hosted subdomain on a raw IP address.
        if (! $usePathStyle && filter_var(trim($rawHost, '[]'), FILTER_VALIDATE_IP) !== false) {
            $usePathStyle = true;
        }

        if ($usePathStyle) {
            $hostHeader = $rawHost . $port;
            $requestPath = '/' . rawurlencode($bucket) . ($encodedKey !== '' ? '/' . $encodedKey : '');
            $canonicalUri = $requestPath;
        } else {
            $virtualHost = str_starts_with($rawHost, $bucket . '.') ? $rawHost : ($bucket . '.' . $rawHost);
            $hostHeader = $virtualHost . $port;
            $requestPath = $encodedKey !== '' ? '/' . $encodedKey : '/';
            // Alibaba Cloud OSS V4 Canonical URI always starts with /{bucket}/.
            $canonicalUri = $provider === 'oss'
                ? '/' . rawurlencode($bucket) . '/' . $encodedKey
                : $requestPath;
        }

        $url = $scheme . '://' . $hostHeader . $requestPath;
        if (SafeUrl::outboundGateway($url) === null) {
            throw new RuntimeException("{$label} 构造的目标地址安全校验未通过。");
        }

        return [
            'url' => $url,
            'host' => $hostHeader,
            'canonical_uri' => $canonicalUri,
            'signing_region' => $signingRegion,
            'object_key' => $objectKey,
            'scheme' => $scheme,
            'provider' => $provider,
            'bucket' => $bucket,
        ];
    }

    public static function normalizePrefix(string $prefix): string
    {
        $normalized = str_replace('\\', '/', trim($prefix));
        if ($normalized === '') {
            return '';
        }
        if (preg_match('/[\x00-\x1f]/', $normalized) === 1 || str_contains($normalized, '..')) {
            throw new RuntimeException('对象存储目录前缀包含不允许的字符或相对路径。');
        }

        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => $part !== ''));

        return implode('/', $segments);
    }

    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    /**
     * Build signed headers for a provider request.
     *
     * @param  array{host: string, canonical_uri: string, signing_region: string, provider: string}  $target
     * @param  array{access_key: string, secret_key: string}  $cfg
     * @param  array<string, string>  $queryParams
     * @return array<string, string>
     */
    public function signHeaders(
        string $method,
        array $target,
        array $cfg,
        string $payloadSha256,
        ?string $contentType = null,
        ?string $contentMd5 = null,
        ?int $timestamp = null,
        array $queryParams = [],
    ): array {
        $ts = $timestamp ?? time();
        $amzDate = gmdate('Ymd\THis\Z', $ts);
        $dateStamp = gmdate('Ymd', $ts);
        $canonicalQuery = self::buildCanonicalQuery($queryParams);

        if ($target['provider'] === 'oss') {
            return $this->signOssV4(
                $method,
                $target['host'],
                $target['canonical_uri'],
                $target['signing_region'],
                $cfg['access_key'],
                $cfg['secret_key'],
                $amzDate,
                $dateStamp,
                $contentType,
                $contentMd5,
                $canonicalQuery,
            );
        }

        return $this->signAwsV4(
            $method,
            $target['host'],
            $target['canonical_uri'],
            $target['signing_region'],
            $cfg['access_key'],
            $cfg['secret_key'],
            $amzDate,
            $dateStamp,
            $payloadSha256,
            $contentType,
            $contentMd5,
            $canonicalQuery,
        );
    }

    /**
     * @param  array<string, string>  $queryParams
     */
    public static function buildCanonicalQuery(array $queryParams): string
    {
        if ($queryParams === []) {
            return '';
        }
        $encoded = [];
        foreach ($queryParams as $key => $value) {
            $encoded[rawurlencode((string) $key)] = rawurlencode((string) $value);
        }
        ksort($encoded, SORT_STRING);
        $parts = [];
        foreach ($encoded as $k => $v) {
            $parts[] = $k . '=' . $v;
        }

        return implode('&', $parts);
    }

    /**
     * AWS Signature Version 4 (used by AWS S3, Cloudflare R2, and Tencent Cloud COS S3-compatible API).
     *
     * @return array<string, string>
     */
    private function signAwsV4(
        string $method,
        string $host,
        string $canonicalUri,
        string $region,
        string $accessKey,
        string $secretKey,
        string $amzDate,
        string $dateStamp,
        string $payloadSha256,
        ?string $contentType,
        ?string $contentMd5,
        string $canonicalQuery = '',
    ): array {
        $canonicalMap = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadSha256,
            'x-amz-date' => $amzDate,
        ];
        if ($contentType !== null) {
            $canonicalMap['content-type'] = $contentType;
        }
        if ($contentMd5 !== null) {
            $canonicalMap['content-md5'] = $contentMd5;
        }
        ksort($canonicalMap, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($canonicalMap as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
        }
        $signedHeaders = implode(';', array_keys($canonicalMap));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadSha256,
        ]);

        $scope = "{$dateStamp}/{$region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers = [
            'Host' => $host,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Content-Sha256' => $payloadSha256,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        if ($contentMd5 !== null) {
            $headers['Content-MD5'] = $contentMd5;
        }

        return $headers;
    }

    /**
     * Alibaba Cloud OSS Signature Version 4 (OSS4-HMAC-SHA256).
     *
     * @return array<string, string>
     */
    private function signOssV4(
        string $method,
        string $host,
        string $canonicalUri,
        string $region,
        string $accessKey,
        string $secretKey,
        string $ossDate,
        string $dateStamp,
        ?string $contentType,
        ?string $contentMd5,
        string $canonicalQuery = '',
    ): array {
        $payloadHash = 'UNSIGNED-PAYLOAD';
        $canonicalMap = [
            'host' => $host,
            'x-oss-content-sha256' => $payloadHash,
            'x-oss-date' => $ossDate,
        ];
        if ($contentType !== null) {
            $canonicalMap['content-type'] = $contentType;
        }
        if ($contentMd5 !== null) {
            $canonicalMap['content-md5'] = $contentMd5;
        }
        ksort($canonicalMap, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($canonicalMap as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
        }
        $additionalHeaders = 'host';

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $additionalHeaders,
            $payloadHash,
        ]);

        $scope = "{$dateStamp}/{$region}/oss/aliyun_v4_request";
        $stringToSign = implode("\n", [
            'OSS4-HMAC-SHA256',
            $ossDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'aliyun_v4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 'oss', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aliyun_v4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers = [
            'Host' => $host,
            'x-oss-date' => $ossDate,
            'x-oss-content-sha256' => $payloadHash,
            'Authorization' => "OSS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, AdditionalHeaders={$additionalHeaders}, Signature={$signature}",
        ];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        if ($contentMd5 !== null) {
            $headers['Content-MD5'] = $contentMd5;
        }

        return $headers;
    }

    /**
     * Test bucket connectivity and credentials via a signed HeadBucket request.
     *
     * @param  array<string, mixed>|null  $override
     * @return array{ok: bool, message: string}
     */
    public function testConnection(string $provider, ?array $override = null): array
    {
        $label = self::label($provider);
        try {
            $cfg = $this->config($provider, $override);
            $target = $this->resolveTarget($provider, $cfg);
            $httpOptions = SafeUrl::outboundHttpOptions($target['url']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $emptyHash = hash('sha256', '');
        $headers = $this->signHeaders('HEAD', $target, $cfg, $emptyHash);

        try {
            $response = Http::timeout(15)
                ->connectTimeout(10)
                ->withoutRedirecting()
                ->withOptions($httpOptions)
                ->withHeaders($headers)
                ->head($target['url']);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => "{$label} 连接失败：无法连接目标 Endpoint，请检查地址、DNS 与服务器出站网络。"];
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => "{$label} 连接测试通过（存储桶：{$target['bucket']}）。"];
        }

        return ['ok' => false, 'message' => $this->describeHttpFailure($label, $status, (string) $response->body())];
    }

    /**
     * Stream-upload a validated backup archive to one cloud storage provider.
     *
     * @param  array<string, mixed>|null  $override
     * @return array{provider: string, label: string, bucket: string, key: string}
     */
    public function uploadFile(string $provider, string $file, ?array $override = null): array
    {
        $label = self::label($provider);
        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('待上传的本地备份文件不存在或不可读。');
        }
        $size = filesize($file);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('待上传的本地备份文件为空。');
        }
        if ($size > self::MAX_SINGLE_UPLOAD_BYTES) {
            throw new RuntimeException("{$label} 单文件直传上限为 5 GB，当前备份体积超出限制。");
        }

        $cfg = $this->config($provider, $override);
        $target = $this->resolveTarget($provider, $cfg, basename($file));
        $httpOptions = SafeUrl::outboundHttpOptions($target['url']);

        $sha256 = hash_file('sha256', $file);
        $md5Raw = md5_file($file, true);
        if (! is_string($sha256) || ! is_string($md5Raw)) {
            throw new RuntimeException('无法计算本地备份校验摘要。');
        }
        $contentMd5 = base64_encode($md5Raw);
        $contentType = 'application/gzip';

        $headers = $this->signHeaders('PUT', $target, $cfg, $sha256, $contentType, $contentMd5);
        $headers['Content-Length'] = (string) $size;

        $stream = fopen($file, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('无法打开本地备份文件流。');
        }

        try {
            $response = Http::timeout(600)
                ->connectTimeout(15)
                ->withoutRedirecting()
                ->withOptions($httpOptions)
                ->withHeaders($headers)
                ->withBody($stream, $contentType)
                ->put($target['url']);
        } catch (\Throwable) {
            throw new RuntimeException("{$label} 上传失败：网络连接中断或超时。");
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->describeHttpFailure($label, $response->status(), (string) $response->body()));
        }

        return [
            'provider' => $provider,
            'label' => $label,
            'bucket' => $target['bucket'],
            'key' => $target['object_key'],
        ];
    }

    /**
     * Upload a backup archive to every enabled cloud provider independently.
     *
     * @param  (callable(string, string): void)|null  $onStart
     * @return array{succeeded: array<string, string>, failed: array<string, array{label: string, error: string}>}
     */
    public function syncAll(string $file, ?callable $onStart = null): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($this->enabledProviders() as $provider) {
            $label = self::label($provider);
            if ($onStart) {
                $onStart($provider, $label);
            }
            try {
                $this->uploadFile($provider, $file);
                $succeeded[$provider] = $label;
            } catch (\Throwable $e) {
                $failed[$provider] = [
                    'label' => $label,
                    'error' => $e->getMessage() ?: "{$label} 上传失败。",
                ];
            }
        }

        return compact('succeeded', 'failed');
    }

    /**
     * List backup archives stored in one cloud provider bucket (ListObjectsV2).
     *
     * @param  array<string, mixed>|null  $override
     * @return list<array{key: string, name: string, size: int, last_modified: string|null}>
     */
    public function listBackups(string $provider, ?array $override = null): array
    {
        $label = self::label($provider);
        $cfg = $this->config($provider, $override);
        $target = $this->resolveTarget($provider, $cfg);
        $httpOptions = SafeUrl::outboundHttpOptions($target['url']);

        $prefix = self::normalizePrefix((string) ($cfg['prefix'] ?? ''));
        $items = [];
        $continuationToken = null;
        $maxPages = 20;

        for ($page = 0; $page < $maxPages; $page++) {
            $queryParams = [
                'list-type' => '2',
                'max-keys' => '1000',
            ];
            if ($prefix !== '') {
                $queryParams['prefix'] = $prefix . '/';
            }
            if ($continuationToken !== null && $continuationToken !== '') {
                $queryParams['continuation-token'] = $continuationToken;
            }

            $canonicalQuery = self::buildCanonicalQuery($queryParams);
            $requestUrl = $target['url'] . '?' . $canonicalQuery;
            $headers = $this->signHeaders('GET', $target, $cfg, hash('sha256', ''), null, null, null, $queryParams);

            try {
                $response = Http::timeout(20)
                    ->connectTimeout(10)
                    ->withoutRedirecting()
                    ->withOptions($httpOptions)
                    ->withHeaders($headers)
                    ->get($requestUrl);
            } catch (\Throwable) {
                throw new RuntimeException("{$label} 获取备份列表失败：网络连接中断或超时。");
            }

            if (! $response->successful()) {
                throw new RuntimeException($this->describeHttpFailure($label, $response->status(), (string) $response->body()));
            }

            $body = (string) $response->body();
            $previous = libxml_use_internal_errors(true);
            try {
                $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            if ($xml === false) {
                throw new RuntimeException("{$label} 返回的列表 XML 无法解析。");
            }

            foreach ($xml->Contents ?? [] as $node) {
                $key = trim((string) ($node->Key ?? ''));
                if ($key === '' || str_contains($key, '..') || preg_match('/[\x00-\x1f]/', $key) === 1) {
                    continue;
                }
                $name = basename($key);
                if (! preg_match('/^shop-[A-Za-z0-9-]+\.tar\.gz$/D', $name)) {
                    continue;
                }
                $items[] = [
                    'key' => $key,
                    'name' => $name,
                    'size' => max(0, (int) ($node->Size ?? 0)),
                    'last_modified' => ($lm = trim((string) ($node->LastModified ?? ''))) !== '' ? $lm : null,
                ];
            }

            $isTruncated = strtolower(trim((string) ($xml->IsTruncated ?? ''))) === 'true';
            $nextToken = trim((string) ($xml->NextContinuationToken ?? ''));
            if (! $isTruncated || $nextToken === '' || $nextToken === $continuationToken) {
                break;
            }
            $continuationToken = $nextToken;
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['last_modified'] ?? $b['name']), (string) ($a['last_modified'] ?? $a['name'])));

        return array_slice($items, 0, 200);
    }

    /**
     * Stream-download one backup archive from a cloud provider into a local destination file.
     *
     * @param  array<string, mixed>|null  $override
     */
    public function downloadFile(string $provider, string $key, string $destination, ?array $override = null): string
    {
        $label = self::label($provider);
        $cleanKey = ltrim(str_replace('\\', '/', trim($key)), '/');
        if ($cleanKey === '' || str_contains($cleanKey, '..') || preg_match('/[\x00-\x1f]/', $cleanKey) === 1
            || ! preg_match('/^shop-[A-Za-z0-9-]+\.tar\.gz$/D', basename($cleanKey))) {
            throw new RuntimeException('云端备份对象键无效。');
        }

        $cfg = $this->config($provider, $override);
        $configuredPrefix = self::normalizePrefix((string) ($cfg['prefix'] ?? ''));
        if ($configuredPrefix !== '' && ! str_starts_with($cleanKey, $configuredPrefix . '/')) {
            throw new RuntimeException('请求的对象键不在已配置的备份目录前缀范围内。');
        }

        // Key already includes the prefix, so resolve with empty prefix to avoid doubling.
        $target = $this->resolveTarget($provider, array_merge($cfg, ['prefix' => '']), $cleanKey);
        $httpOptions = SafeUrl::outboundHttpOptions($target['url']);
        $headers = $this->signHeaders('GET', $target, $cfg, hash('sha256', ''));

        $sinkOptions = array_merge($httpOptions, ['sink' => $destination]);
        try {
            $response = Http::timeout(600)
                ->connectTimeout(15)
                ->withoutRedirecting()
                ->withOptions($sinkOptions)
                ->withHeaders($headers)
                ->get($target['url']);
        } catch (\Throwable) {
            if (is_file($destination)) {
                @unlink($destination);
            }
            throw new RuntimeException("{$label} 拉取备份失败：网络连接中断或超时。");
        }

        if (! $response->successful()) {
            $errBody = is_file($destination) ? (string) @file_get_contents($destination, false, null, 0, 4096) : (string) $response->body();
            if (is_file($destination)) {
                @unlink($destination);
            }
            throw new RuntimeException($this->describeHttpFailure($label, $response->status(), $errBody));
        }

        // Http::fake() in tests does not always populate Guzzle's 'sink' file directly.
        if (! is_file($destination) || filesize($destination) === 0) {
            $body = (string) $response->body();
            if ($body !== '') {
                file_put_contents($destination, $body);
            }
        }
        if (! is_file($destination) || filesize($destination) === 0) {
            if (is_file($destination)) {
                @unlink($destination);
            }
            throw new RuntimeException("{$label} 拉取的备份文件为空。");
        }
        @chmod($destination, 0600);

        return $destination;
    }

    private function describeHttpFailure(string $label, int $status, string $body): string
    {
        $code = null;
        if ($body !== '' && preg_match('/<Code>([A-Za-z0-9._-]{1,64})<\/Code>/', $body, $match) === 1) {
            $code = $match[1];
        }
        $suffix = $code !== null ? "（{$code}）" : '';

        return match (true) {
            $status === 401 || $status === 403 => "{$label} 鉴权失败（HTTP {$status}{$suffix}），请核对 AccessKey / SecretKey、存储桶权限及区域 Region。",
            $status === 404 => "{$label} 存储桶不存在或路径无效（HTTP 404{$suffix}），请核对存储桶名称与 Endpoint。",
            $status === 301 || $status === 307 => "{$label} 区域或 Endpoint 不匹配（HTTP {$status}{$suffix}），请核对存储桶所属区域。",
            $status === 400 => "{$label} 请求被拒绝（HTTP 400{$suffix}），请核对区域 Region、存储桶名称与访问方式。",
            default => "{$label} 远端返回异常（HTTP {$status}{$suffix}），请稍后重试。",
        };
    }
}
