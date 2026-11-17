<?php

namespace App\Console\Commands;

use App\Security\SecretCipher;
use Illuminate\Console\Command;

final class RotateSecrets extends Command
{
    protected $signature = 'secrets:rotate {--key-id= : New unique encryption version, e.g. k2}';

    protected $description = 'Add a new active encryption key without dropping old versions or changing the deduplication key';

    public function handle(SecretCipher $cipher): int
    {
        $path = realpath((string) config('secrets.keyring_file'));
        $stage = null;
        $lock = null;
        try {
            $cipher->metadata();
            if ($path === false || ! is_writable(dirname($path))) {
                throw new \RuntimeException;
            }
            $lock = fopen($path.'.lock', 'c+b');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                throw new \RuntimeException;
            }
            if (PHP_OS_FAMILY !== 'Windows') { chmod($path.'.lock', 0600); }
            $ring = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $version = (string) ($this->option('key-id') ?: 'k'.gmdate('YmdHis'));
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,31}$/D', $version) || isset($ring['versions'][$version]) || count($ring['versions']) >= 32) {
                $this->error('新版本须为字母开头、未使用的 1–32 位字母/数字/_/-；版本数量不得超过 32。');
                return self::FAILURE;
            }
            $ring['versions'][$version] = base64_encode(random_bytes(32));
            $ring['active'] = $version;
            $stage = tempnam(dirname($path), '.keyring-');
            if ($stage === false) { throw new \RuntimeException; }
            if (PHP_OS_FAMILY !== 'Windows' && ! chmod($stage, 0600)) { throw new \RuntimeException; }
            $encoded = json_encode($ring, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
            if (file_put_contents($stage, $encoded, LOCK_EX) !== strlen($encoded)) { throw new \RuntimeException; }
            // Validate the complete candidate before replacing the live file. Both
            // files are on the same filesystem; a failed rename preserves the old ring.
            config(['secrets.keyring_file' => $stage]);
            $cipher->metadata();
            config(['secrets.keyring_file' => $path]);
            if (! rename($stage, $path)) { throw new \RuntimeException; }
            $stage = null;
            $this->info('已启用新加密版本 '.$version.'；旧版本与查重密钥已保留。停写后运行 secrets:encrypt --rotate --force，并重新独立备份 keyring。');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('密钥轮换失败，未删除旧版本。请检查独立 keyring 目录权限。');
            return self::FAILURE;
        } finally {
            if ($path !== false) { config(['secrets.keyring_file' => $path]); }
            if ($stage && is_file($stage)) { unlink($stage); }
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}
