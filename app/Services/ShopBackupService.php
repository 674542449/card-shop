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

    protected function databaseProcess(array $command, ?string $database = null, ?callable $progress = null): void
    {
        $db = config('database.connections.pgsql');
        $process = new Process($command, base_path(), ['PGHOST' => $db['host'], 'PGPORT' => (string) $db['port'],
            'PGUSER' => $db['username'], 'PGPASSWORD' => $db['password'], 'PGDATABASE' => $database ?: $db['database']]);
        $process->setTimeout(1800);
        $process->start();
        try {
            $lastBeat = 0;
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($progress && time() - $lastBeat >= 10) {
                    $progress(10, '导出数据库');
                    $lastBeat = time();
                }
                usleep(250000);
            }
        } catch (\Throwable $e) {
            $process->stop(1);
            throw $e;
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('数据库备份/恢复命令失败，请检查 PostgreSQL 客户端、连接和磁盘空间。');
        }
    }

    public function create(?callable $progress = null): string
    {
        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0700, true);
        }
        $name = 'shop-'.now()->format('Ymd-His').'-'.Str::uuid();
        $stage = $this->directory().'/'.$name;
        mkdir($stage, 0700);
        // Never expose a half-written archive as a downloadable complete backup.
        $file = $stage.'/archive.tar';
        $output = $this->directory().'/'.$name.'.tar.gz';
        try {
            $progress && $progress(5, '准备数据库导出');
            $this->databaseProcess([(string) env('PG_DUMP_BINARY', 'pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--file='.$stage.'/database.dump'], null, $progress);
            $archive = new \PharData($file);
            $manifest = ['version' => 1, 'created_at' => now()->toIso8601String(), 'database' => config('database.connections.pgsql.database'), 'files' => []];
            $inputs = $this->backupInputs($stage);
            $total = 0;
            foreach ($inputs as $full) {
                $size = filesize($full);
                if ($size === false || ($total += $size) > 10 * 1024 * 1024 * 1024) {
                    throw new RuntimeException('完整备份超过支持的 10 GB，或源文件不可读。');
                }
            }
            if (($free = disk_free_space($this->directory())) !== false && $free < $total * 2 + 64 * 1024 * 1024) {
                throw new RuntimeException('磁盘空间不足以完成安全备份。');
            }
            $index = 0;
            foreach ($inputs as $relative => $full) {
                $archive->addFile($full, $relative);
                // Hash the actual archived bytes, rather than a source that can change mid-backup.
                $manifest['files'][$relative] = ['sha256' => hash_file('sha256', $archive[$relative]->getPathname()), 'size' => $archive[$relative]->getSize()];
                $progress && $progress(20 + (int) (++$index / count($inputs) * 50), '打包文件');
            }
            $archive->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $progress && $progress(75, '压缩备份');
            $archive->compress(\Phar::GZ);
            unset($archive);
            unlink($file);
            chmod($file.'.gz', 0600);
            // PHP's in-process Phar cache can return empty entry contents for the
            // exact pathname produced by compress(), despite a correct entry size.
            // Reopen it under a fresh private pathname before validation.
            $compressed = $stage.'/verified.tar.gz';
            if (! rename($file.'.gz', $compressed)) {
                throw new RuntimeException('无法准备备份校验文件。');
            }
            $progress && $progress(85, '校验完整备份');
            $this->validate($compressed);
            if (! rename($compressed, $output)) {
                throw new RuntimeException('无法完成备份文件。');
            }
            return $output;
        } finally {
            unset($archive);
            foreach (['database.dump', 'archive.tar', 'archive.tar.gz', 'verified.tar.gz'] as $temporary) {
                if (is_file($stage.'/'.$temporary)) {
                    unlink($stage.'/'.$temporary);
                }
            }
            if (is_dir($stage)) {
                rmdir($stage);
            }
        }
    }

    protected function backupInputs(string $stage): array
    {
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
        return $inputs;
    }

    /** Only a pre-mounted absolute directory explicitly configured by the owner is accepted. */
    public function syncDirectory(): ?string
    {
        $configured = trim((string) setting('backup_sync_directory', ''));
        if ($configured === '') {
            return null;
        }
        $normalized = str_replace('\\', '/', $configured);
        if (str_contains($configured, '://') || preg_match('/[\x00-\x1f]/', $configured)
            || (! str_starts_with($normalized, '/') && ! preg_match('/^[A-Za-z]:\//', $normalized))) {
            throw new RuntimeException('备份同步目录必须是已挂载的绝对文件路径。');
        }
        $resolved = realpath($configured);
        if (! $resolved || ! is_dir($resolved) || ! is_writable($resolved) || is_link($configured)) {
            throw new RuntimeException('备份同步目录不存在、不可写或是符号链接。');
        }
        $path = strtolower(str_replace('\\', '/', $resolved));
        $root = strtolower(str_replace('\\', '/', realpath(base_path()) ?: base_path()));
        // Copies contain secrets; never allow the app tree, the web root or a filesystem root.
        if (trim($path, '/') === '' || preg_match('/^[a-z]:\/?$/', $path)
            || $path === $root || str_starts_with($path.'/', rtrim($root, '/').'/')) {
            throw new RuntimeException('同步目录必须位于应用目录之外。');
        }
        return $resolved;
    }

    public function sync(string $file): void
    {
        $directory = $this->syncDirectory();
        if (! $directory) {
            return;
        }
        $source = realpath($file);
        $root = realpath($this->directory());
        if (! $source || ! $root || dirname($source) !== $root
            || ! preg_match('/^shop-[A-Za-z0-9-]+\.tar\.gz$/D', basename($source))) {
            throw new RuntimeException('备份来源路径不允许。');
        }
        $target = $directory.DIRECTORY_SEPARATOR.basename($source);
        // An existing remote copy is immutable, including symlinks.
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('同步目标已经存在。');
        }
        $temporary = $directory.DIRECTORY_SEPARATOR.'.'.basename($source).'.'.Str::uuid().'.partial';
        $output = fopen($temporary, 'xb');
        $input = fopen($source, 'rb');
        try {
            if (! $input || ! $output || stream_copy_to_stream($input, $output) !== filesize($source)) {
                throw new RuntimeException('同步复制失败。');
            }
            fflush($output);
            fclose($output);
            $output = null;
            chmod($temporary, 0600);
            // Hard-link publication cannot overwrite a destination created meanwhile.
            // Both files are in the same mounted directory; unsupported filesystems
            // report a safe sync failure and retain the original local archive.
            if (! hash_equals(hash_file('sha256', $source), hash_file('sha256', $temporary)) || ! link($temporary, $target)) {
                throw new RuntimeException('同步校验失败。');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function prune(?string $preserve = null): void
    {
        $root = realpath($this->directory());
        if (! $root) {
            return;
        }
        $files = glob($root.'/shop-*.tar.gz') ?: [];
        rsort($files);
        $keep = max(1, min(365, (int) setting('backup_retention_count', 14)));
        $before = now()->subDays(max(1, min(3650, (int) setting('backup_retention_days', 30))))->timestamp;
        foreach ($files as $index => $file) {
            // Always preserve the newest validated full backup.
            if ($file === $preserve || $index === 0 || ($index < $keep && filemtime($file) >= $before)) {
                continue;
            }
            $resolved = realpath($file);
            if ($resolved && dirname($resolved) === $root && ! is_link($file)
                && preg_match('/^shop-[A-Za-z0-9-]+\.tar\.gz$/D', basename($resolved))) {
                if (! unlink($resolved)) {
                    throw new RuntimeException('旧备份清理失败。');
                }
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
