<?php

namespace App\Security;

use App\Exceptions\SecretStorageException;

/** Versioned authenticated encryption; the independent keyring is never serialized. */
final class SecretCipher
{
    public const PREFIX = 'csenc:v1:';

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function encrypt(string $plaintext, string $purpose): string
    {
        $ring = $this->keyring();
        $version = $ring['active'];
        $iv = random_bytes(12);
        $tag = '';
        $aad = "cardshop-secret/v1\0".$version."\0".$purpose;
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $ring['versions'][$version],
            OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new SecretStorageException;
        }

        return self::PREFIX.$version.':'.base64_encode($iv.$tag.$ciphertext);
    }

    public function decrypt(string $stored, string $purpose): string
    {
        if (! self::isEncrypted($stored)) {
            // Legacy conversion is explicit. Missing keys or unfinished conversion
            // must never quietly turn production delivery into plaintext fallback.
            throw new SecretStorageException;
        }
        $parts = explode(':', substr($stored, strlen(self::PREFIX)), 2);
        $ring = $this->keyring();
        $key = $ring['versions'][$parts[0] ?? ''] ?? null;
        $bytes = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        if ($key === null || $bytes === false || strlen($bytes) < 28) {
            throw new SecretStorageException;
        }
        $aad = "cardshop-secret/v1\0".$parts[0]."\0".$purpose;
        $plaintext = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($bytes, 0, 12), substr($bytes, 12, 16), $aad);
        if ($plaintext === false) {
            throw new SecretStorageException;
        }

        return $plaintext;
    }

    public function fingerprint(string $plaintext): string
    {
        return hash_hmac('sha256', "card-content\0".$plaintext, $this->keyring()['fingerprint']);
    }

    /** Public identifiers only; useful for checking that a restore has its keyring. */
    public function metadata(): array
    {
        $ring = $this->keyring();

        $fingerprints = [];
        foreach ($ring['versions'] as $id => $key) {
            $fingerprints[$id] = hash('sha256', "cardshop-key-version\0".$id."\0".$key);
        }

        return ['format' => 1, 'keyring_id' => hash('sha256', $ring['fingerprint']),
            'active' => $ring['active'], 'version_ids' => array_keys($ring['versions']),
            'version_fingerprints' => $fingerprints];
    }

    public function version(string $stored): ?string
    {
        return self::isEncrypted($stored) ? explode(':', substr($stored, strlen(self::PREFIX)), 2)[0] : null;
    }

    /** @return array{active:string,versions:array<string,string>,fingerprint:string} */
    private function keyring(): array
    {
        $path = (string) config('secrets.keyring_file');
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)
            || ! self::isOutsideApplication($resolved) || filesize($resolved) > 65536
            || ! extension_loaded('openssl')) {
            throw new SecretStorageException;
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($resolved) & 0007) !== 0) {
            throw new SecretStorageException;
        }
        try {
            $ring = json_decode((string) file_get_contents($resolved), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($ring) || ($ring['format'] ?? null) !== 1
                || ! is_string($ring['active'] ?? null) || ! is_array($ring['versions'] ?? null)
                || count($ring['versions']) < 1 || count($ring['versions']) > 32
                || ! isset($ring['versions'][$ring['active']]) || ! is_string($ring['fingerprint'] ?? null)) {
                throw new SecretStorageException;
            }
            $versions = [];
            foreach ($ring['versions'] as $id => $encoded) {
                if (! is_string($id) || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,31}$/D', $id) || ! is_string($encoded)) {
                    throw new SecretStorageException;
                }
                $key = base64_decode($encoded, true);
                if ($key === false || strlen($key) !== 32) {
                    throw new SecretStorageException;
                }
                $versions[$id] = $key;
            }
            $fingerprint = base64_decode($ring['fingerprint'], true);
            if ($fingerprint === false || strlen($fingerprint) !== 32 || in_array($fingerprint, $versions, true)) {
                throw new SecretStorageException;
            }

            return ['active' => $ring['active'], 'versions' => $versions, 'fingerprint' => $fingerprint];
        } catch (\Throwable) {
            throw new SecretStorageException;
        }
    }

    public static function isOutsideApplication(string $path): bool
    {
        $root = strtolower(str_replace('\\', '/', realpath(base_path()) ?: base_path()));
        $candidate = strtolower(str_replace('\\', '/', $path));

        return $candidate !== $root && ! str_starts_with($candidate, rtrim($root, '/').'/');
    }
}
