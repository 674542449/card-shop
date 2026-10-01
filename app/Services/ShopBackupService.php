<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ShopBackupService
{
    public function directory(): string
    {
        return storage_path('app/private/shop-backups');
    }

    private function databaseProcess(array $command, ?string $database = null): void
    {
        $db = config('database.connections.pgsql');
        $process = new Process($command, base_path(), ['PGHOST' => $db['host'], 'PGPORT' => (string) $db['port'],
            'PGUSER' => $db['username'], 'PGPASSWORD' => $db['password'], 'PGDATABASE' => $database ?: $db['database']]);
        $process->setTimeout(1800);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('数据库备份/恢复命令失败，请检查 PostgreSQL 客户端、连接和磁盘空间。');
        }
    }

    public function create(): string
    {
        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0700, true);
        }
        $name = 'shop-'.now()->format('Ymd-His').'-'.Str::uuid();
        $stage = $this->directory().'/'.$name;
        mkdir($stage, 0700);
        $file = $stage.'.tar';
        try {
            $this->databaseProcess([(string) env('PG_DUMP_BINARY', 'pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--file='.$stage.'/database.dump']);
            $archive = new \PharData($file);
            $manifest = ['version' => 1, 'created_at' => now()->toIso8601String(), 'database' => config('database.connections.pgsql.database'), 'files' => []];
            $inputs = ['database.dump' => $stage.'/database.dump'];
            if (is_file(base_path('.env'))) {
                $inputs['config.env'] = base_path('.env');
            }
            foreach (Storage::disk('public')->allFiles() as $path) {
                $full = Storage::disk('public')->path($path);
                if (! is_link($full)) {
                    $inputs['uploads/'.$path] = $full;
                }
            }
            foreach (['asset-quarantine', 'record-archives'] as $folder) {
                foreach (Storage::disk('local')->allFiles($folder) as $path) {
                    $full = Storage::disk('local')->path($path);
                    if (! is_link($full)) {
                        $inputs['private/'.$path] = $full;
                    }
                }
            }
            foreach ($inputs as $relative => $full) {
                $archive->addFile($full, $relative);
                $manifest['files'][$relative] = ['sha256' => hash_file('sha256', $full), 'size' => filesize($full)];
            }
            $archive->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $archive->compress(\Phar::GZ);
            unset($archive);
            unlink($file);
            chmod($file.'.gz', 0600);

            return $file.'.gz';
        } finally {
            if (is_file($stage.'/database.dump')) {
                unlink($stage.'/database.dump');
            } if (is_dir($stage)) {
                rmdir($stage);
            }
        }
    }

    public function validate(string $file): array
    {
        $file = str_replace('\\', '/', realpath($file) ?: $file);
        $archive = new \PharData($file);
        if (! isset($archive['manifest.json']) || $archive['manifest.json']->getSize() > 20 * 1024 * 1024) {
            throw new RuntimeException('备份清单不存在或过大。');
        }
        $manifest = json_decode($archive['manifest.json']->getContent(), true);
        if (! is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ! isset($manifest['files']['database.dump'])) {
            throw new RuntimeException('备份清单无效。');
        }
        $total = 0;
        foreach (new \RecursiveIteratorIterator($archive) as $entry) {
            $relative = substr(str_replace('\\', '/', $entry->getPathname()), strlen('phar://'.$file.'/'));
            if ($entry->isLink() || str_contains($relative, '..') || str_contains($relative, ':') || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
                throw new RuntimeException('备份含有不安全路径。');
            }
            if ($relative === 'manifest.json') {
                continue;
            }
            if (! isset($manifest['files'][$relative]) || ! in_array($relative, ['database.dump', 'config.env'], true) && ! str_starts_with($relative, 'uploads/') && ! str_starts_with($relative, 'private/asset-quarantine/') && ! str_starts_with($relative, 'private/record-archives/')) {
                throw new RuntimeException('备份含有未登记的文件。');
            }
            $meta = $manifest['files'][$relative];
            $total += $entry->getSize();
            if ($total > 10 * 1024 * 1024 * 1024 || ! is_array($meta) || ! isset($meta['size'], $meta['sha256']) || ! is_string($meta['sha256']) || $entry->getSize() !== $meta['size'] || ! hash_equals($meta['sha256'], hash_file('sha256', $entry->getPathname()))) {
                throw new RuntimeException('备份校验失败。');
            }
        }
        foreach ($manifest['files'] as $relative => $meta) {
            if (! isset($archive[$relative])) {
                throw new RuntimeException('备份缺少文件。');
            }
        }

        return $manifest;
    }

    public function restore(string $file, string $database, bool $includeConfig = false): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,63}$/D', $database)) {
            throw new RuntimeException('目标数据库名称不允许。');
        }
        $manifest = $this->validate($file);
        $archive = new \PharData($file);
        $stage = $this->directory().'/restore-'.Str::uuid();
        mkdir($stage, 0700, true);
        try {
            copy($archive['database.dump']->getPathname(), $stage.'/database.dump');
            $this->databaseProcess([(string) env('PG_RESTORE_BINARY', 'pg_restore'), '--clean', '--if-exists', '--no-owner', '--no-privileges', '--exit-on-error', '--dbname='.$database, $stage.'/database.dump'], $database);
            // Restore validation into a separate database must never touch live files.
            if ($database === config('database.connections.pgsql.database')) {
                foreach ($manifest['files'] as $path => $meta) {
                    if (str_starts_with($path, 'uploads/') || str_starts_with($path, 'private/')) {
                        $stream = fopen($archive[$path]->getPathname(), 'rb');
                        $disk = str_starts_with($path, 'uploads/') ? 'public' : 'local';
                        Storage::disk($disk)->put(substr($path, 8), $stream);
                        fclose($stream);
                    }
                }
                if ($includeConfig && isset($archive['config.env'])) {
                    file_put_contents(base_path('.env'), $archive['config.env']->getContent());
                }
            }
        } finally {
            if (is_file($stage.'/database.dump')) {
                unlink($stage.'/database.dump');
            } rmdir($stage);
        }
    }
}
