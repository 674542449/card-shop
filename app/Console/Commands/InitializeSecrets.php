<?php

namespace App\Console\Commands;

use App\Security\SecretCipher;
use Illuminate\Console\Command;

final class InitializeSecrets extends Command
{
    protected $signature = 'secrets:init {--path= : Absolute path outside the application tree}';

    protected $description = 'Create an independent encryption keyring without overwriting existing keys';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: config('secrets.keyring_file'));
        if (! preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
            $this->error('Keyring 必须使用应用目录以外的绝对路径。');
            return self::FAILURE;
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true)) {
            $this->error('无法创建独立 keyring 目录。');
            return self::FAILURE;
        }
        $resolved = realpath($directory);
        if ($resolved === false || ! SecretCipher::isOutsideApplication($resolved)) {
            $this->error('Keyring 不得保存在源码、public 或商城备份目录内。');
            return self::FAILURE;
        }
        $path = $resolved.DIRECTORY_SEPARATOR.basename($path);
        $previous = config('secrets.keyring_file');
        config(['secrets.keyring_file' => $path]);
        try {
            if (file_exists($path)) {
                app(SecretCipher::class)->metadata();
                $this->info('现有 keyring 有效，保留原密钥。');
                return self::SUCCESS;
            }
            $ring = ['format' => 1, 'active' => 'k1', 'versions' => ['k1' => base64_encode(random_bytes(32))],
                'fingerprint' => base64_encode(random_bytes(32))];
            // Exclusive creation refuses an existing file, including another init
            // process winning the race. Never overwrite a released keyring.
            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                throw new \RuntimeException;
            }
            try {
                if (PHP_OS_FAMILY !== 'Windows' && ! chmod($path, 0600)) {
                    throw new \RuntimeException;
                }
                $json = json_encode($ring, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
                if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new \RuntimeException;
                }
            } finally {
                fclose($handle);
            }
            app(SecretCipher::class)->metadata();
            $this->info('独立 keyring 已创建。请将文件与商城备份分开保存；密钥丢失将无法恢复卡密。');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Keyring 初始化或校验失败，未覆盖已有文件。请检查路径和访问权限。');
            return self::FAILURE;
        } finally {
            config(['secrets.keyring_file' => $previous]);
        }
    }
}
