<?php

namespace App\Services;

use App\Security\SecretSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * File-backed one-click restore coordinator.
 *
 * State is stored on the shared private storage volume rather than PostgreSQL
 * because pg_restore --clean replaces every database table mid-flight. Executed
 * asynchronously by shop:backup-work outside LOCK_SH so the live restore never
 * deadlocks against an in-flight PHP-FPM request.
 */
class RestoreQueue
{
    private const STALE_RUNNING_SECONDS = 7200;

    public function __construct(
        private readonly ShopBackupService $backups,
    ) {}

    public function statePath(): string
    {
        return $this->backups->directory() . DIRECTORY_SEPARATOR . '.restore-state.json';
    }

    private function lockPath(): string
    {
        return $this->backups->directory() . DIRECTORY_SEPARATOR . '.restore-queue.lock';
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        $path = $this->statePath();
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public function hasPending(): bool
    {
        $state = $this->status();

        return is_array($state) && ($state['status'] ?? null) === 'pending';
    }

    /**
     * Pre-validate the archive and keyring match, then queue one-click restoration.
     *
     * @return array<string, mixed>
     */
    public function enqueue(string $archivePath, string $source = 'local', ?int $adminId = null): array
    {
        $resolved = realpath($archivePath);
        $root = realpath($this->backups->directory());
        if (! $resolved || ! $root || dirname($resolved) !== $root
            || ! preg_match('/^shop-[A-Za-z0-9-]+\.tar\.gz$/D', basename($resolved))) {
            throw new RuntimeException('待恢复的备份文件路径无效。');
        }

        // Pre-validate manifest, SHA-256 checksums, and keyring compatibility BEFORE
        // touching maintenance mode so bad archives fail fast with zero downtime.
        $manifest = $this->backups->validate($resolved);
        $this->backups->verifyKeyringCompatibility($manifest);

        return $this->withQueueLock(function () use ($resolved, $source, $adminId): array {
            $current = $this->status();
            if (is_array($current) && in_array($current['status'] ?? null, ['pending', 'running'], true)) {
                $started = isset($current['started_at']) ? strtotime((string) $current['started_at']) : 0;
                $requested = isset($current['requested_at']) ? strtotime((string) $current['requested_at']) : 0;
                $anchor = max($started ?: 0, $requested ?: 0);
                if ($anchor === 0 || (time() - $anchor) < self::STALE_RUNNING_SECONDS) {
                    throw new RuntimeException('已有恢复任务正在等待或执行中，请等待其完成。');
                }
            }

            $state = [
                'id' => (string) Str::uuid(),
                'status' => 'pending',
                'progress' => 5,
                'phase' => '等待备份进程接管恢复任务',
                'filename' => basename($resolved),
                'archive' => $resolved,
                'source' => $source,
                'requested_by' => $adminId,
                'requested_at' => now()->toIso8601String(),
                'started_at' => null,
                'finished_at' => null,
                'error' => null,
                'maintenance_retained' => false,
            ];
            $this->writeState($state);

            return $this->publicState($state);
        });
    }

    /**
     * Execute a queued restore job safely in the background worker process.
     */
    public function process(int $lockWaitSeconds = 30): bool
    {
        $job = $this->withQueueLock(function (): ?array {
            $current = $this->status();
            if (! is_array($current) || ($current['status'] ?? null) !== 'pending') {
                return null;
            }
            $current['status'] = 'running';
            $current['progress'] = 15;
            $current['phase'] = '进入维护模式并等待正在进行的请求结束';
            $current['started_at'] = now()->toIso8601String();
            $current['error'] = null;
            $current['maintenance_retained'] = false;
            $this->writeState($current);

            return $current;
        });

        if ($job === null) {
            return false;
        }

        $alreadyDown = app()->isDownForMaintenance();
        $retainMaintenance = false;

        try {
            $archive = (string) ($job['archive'] ?? '');
            if ($archive === '' || ! is_file($archive)) {
                throw new RuntimeException('待恢复的备份文件已不存在。');
            }

            if (! $alreadyDown) {
                Artisan::call('down', ['--retry' => 15]);
            }

            $database = (string) config('database.connections.pgsql.database');
            $deadline = microtime(true) + max(1, $lockWaitSeconds);

            while (true) {
                try {
                    $job['progress'] = 45;
                    $job['phase'] = '创建恢复前安全快照并执行单事务恢复';
                    $this->writeState($job);

                    // ShopBackupService::restore() acquires MaintenanceWriteBarrier::restore()
                    // (LOCK_EX) internally, creates a safety snapshot, and runs pg_restore in one
                    // transaction with atomic file rollback on failure.
                    $this->backups->restore($archive, $database, false, true);
                    break;
                } catch (\App\Exceptions\MaintenanceWriteBlockedException) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('等待写入请求释放超时，恢复已取消并恢复正常服务，请稍后重试。');
                    }
                    usleep(250_000);
                }
            }

            $job['progress'] = 90;
            $job['phase'] = '清理缓存并恢复服务';
            $this->writeState($job);
            SecretSettings::forgetCaches();
            try {
                Cache::flush();
            } catch (\Throwable) {
                // Redis flush failure should not fail an already committed DB restore.
            }

            $job['status'] = 'completed';
            $job['progress'] = 100;
            $job['phase'] = '全量恢复已完成';
            $job['finished_at'] = now()->toIso8601String();
            $job['error'] = null;
            $job['maintenance_retained'] = false;
            $this->writeState($job);
        } catch (\Throwable $e) {
            $hasLingeringStage = (glob($this->backups->directory() . '/restore-*', GLOB_ONLYDIR) ?: []) !== [];
            $retainMaintenance = $hasLingeringStage || str_contains($e->getMessage(), '保持维护模式');
            $job['status'] = 'failed';
            $job['phase'] = $retainMaintenance ? '恢复异常（已保持维护模式）' : '恢复失败（已自动恢复原服务）';
            $job['finished_at'] = now()->toIso8601String();
            $job['error'] = mb_substr($e->getMessage() ?: '恢复失败，请检查日志与密钥文件。', 0, 500);
            $job['maintenance_retained'] = $retainMaintenance;
            $this->writeState($job);
        } finally {
            if (! $alreadyDown && ! $retainMaintenance && app()->isDownForMaintenance()) {
                try {
                    Artisan::call('up');
                } catch (\Throwable) {
                    // Leave state recorded if up fails.
                }
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $state @return array<string, mixed> */
    public function publicState(?array $state): ?array
    {
        if (! is_array($state)) {
            return null;
        }
        unset($state['archive']);

        return $state;
    }

    /** @param  array<string, mixed>  $state */
    private function writeState(array $state): void
    {
        $dir = $this->backups->directory();
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $tmp = $this->statePath() . '.' . Str::uuid() . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($tmp, 0600);
        rename($tmp, $this->statePath());
    }

    private function withQueueLock(callable $fn): mixed
    {
        $dir = $this->backups->directory();
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $handle = fopen($this->lockPath(), 'c');
        if (! is_resource($handle)) {
            throw new RuntimeException('无法打开恢复队列锁文件。');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('无法获取恢复队列锁。');
            }

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
