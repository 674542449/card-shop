<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Complete backups have their own worker; they never block checkout or mail delivery. */
class BackupQueue
{
    public const LEASE_SECONDS = 7200;

    public function __construct(private readonly ShopBackupService $backups) {}

    public function enqueue(string $source = 'manual', ?int $adminId = null, ?string $scheduleKey = null): BackupRun
    {
        return Cache::lock('shop:backup-enqueue', 10)->block(2, function () use ($source, $adminId, $scheduleKey) {
            return DB::transaction(function () use ($source, $adminId, $scheduleKey) {
                // Keep submission serialization even if Redis loses its locks.
                DB::select('SELECT pg_advisory_xact_lock(1680147181)');
                if ($scheduleKey && ($existing = BackupRun::where('schedule_key', $scheduleKey)->first())) {
                    return $existing;
                }
                // A repeated button click joins the existing job rather than making another archive.
                if ($existing = BackupRun::whereIn('status', ['pending', 'running'])->orderBy('id')->first()) {
                    return $existing;
                }
                return BackupRun::create(['source' => $source, 'requested_by' => $adminId,
                    'schedule_key' => $scheduleKey, 'sync_status' => setting('backup_sync_directory', '') ? 'pending' : 'disabled']);
            });
        });
    }

    public function schedule(): ?BackupRun
    {
        if (! in_array((string) setting('backup_auto_enabled', '0'), ['1', 'true'], true)) {
            return null;
        }
        $time = (string) setting('backup_schedule_time', '03:00');
        if (! preg_match('/^\d{2}:\d{2}$/D', $time) || now()->format('H:i') < $time) {
            return null;
        }
        // A missed minute catches up later that day, at most one automatic backup per day.
        return $this->enqueue('scheduled', null, now()->format('Y-m-d'));
    }

    public function retry(BackupRun $run): BackupRun
    {
        return Cache::lock('shop:backup-enqueue', 10)->block(2, function () use ($run) {
            return DB::transaction(function () use ($run) {
                DB::select('SELECT pg_advisory_xact_lock(1680147181)');
                $locked = BackupRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'failed' && ! ($locked->status === 'completed' && $locked->last_error)) {
                    throw new RuntimeException('仅失败的备份或同步任务可以重试。');
                }
                if (BackupRun::whereIn('status', ['pending', 'running'])->exists()) {
                    throw new RuntimeException('已有备份任务等待或执行中，请稍后重试。');
                }
                if ($locked->status === 'completed') {
                    $locked->update(['health_acknowledged_at' => now()]);
                    return BackupRun::create(['source' => 'retry', 'requested_by' => $locked->requested_by,
                        'sync_status' => setting('backup_sync_directory', '') ? 'pending' : 'disabled']);
                }
                $locked->update(['status' => 'pending', 'progress' => 0, 'phase' => '等待备份进程',
                    'last_error' => null, 'lease_token' => null, 'lease_expires_at' => null,
                    'started_at' => null, 'finished_at' => null, 'health_acknowledged_at' => null]);
                return $locked;
            });
        });
    }

    public function process(): bool
    {
        app(HeartbeatService::class)->beat('backups');
        $lock = Cache::lock('shop:backup', self::LEASE_SECONDS);
        if (! $lock->get()) {
            return false;
        }
        try {
            $run = DB::transaction(function () {
                DB::select('SELECT pg_advisory_xact_lock(1680147181)');
                BackupRun::where('status', 'running')->where('lease_expires_at', '<=', now())->update([
                    'status' => 'failed', 'phase' => '进程中断', 'last_error' => '备份进程中断，租约已过期，请重试。',
                    'lease_token' => null, 'lease_expires_at' => null, 'finished_at' => now(), 'health_acknowledged_at' => null,
                ]);
                if (BackupRun::where('status', 'running')->exists()) {
                    return null;
                }
                $run = BackupRun::where('status', 'pending')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
                if ($run) {
                    $run->update(['status' => 'running', 'progress' => 1, 'phase' => '准备备份',
                        'attempts' => $run->attempts + 1, 'started_at' => now(), 'lease_token' => (string) Str::uuid(),
                        'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS)]);
                }
                return $run;
            });
            if (! $run) {
                return false;
            }
            $started = microtime(true);
            $progress = function (int $percent, string $phase) use ($run, $started) {
                if (microtime(true) - $started > self::LEASE_SECONDS - 300) {
                    throw new RuntimeException('备份执行时间过长，请检查磁盘和备份体积。');
                }
                if (! BackupRun::whereKey($run->id)->where('lease_token', $run->lease_token)->update([
                    'progress' => min(99, $percent), 'phase' => $phase, 'updated_at' => now(),
                ])) {
                    throw new RuntimeException('备份任务租约已失效。');
                }
                app(HeartbeatService::class)->beat('backups', ['run_id' => $run->id, 'phase' => $phase]);
            };
            try {
                $file = $this->backups->create($progress);
                $progress(90, '校验完整备份');
                $this->backups->validate($file);
                $syncStatus = 'disabled';
                $syncError = null;
                if (setting('backup_sync_directory', '')) {
                    $progress(95, '复制到指定挂载目录');
                    try {
                        $this->backups->sync($file);
                        $syncStatus = 'synced';
                    } catch (\Throwable) {
                        $syncStatus = 'failed';
                        $syncError = '本地备份已完成，挂载目录同步失败，请检查指定目录、权限和空间。';
                    }
                }
                $progress(99, '完成备份');
                $completed = BackupRun::whereKey($run->id)->where('lease_token', $run->lease_token)->update([
                    'status' => 'completed', 'progress' => 100, 'phase' => '备份完成',
                    'filename' => basename($file), 'size' => filesize($file), 'sync_status' => $syncStatus,
                    'synced_at' => $syncStatus === 'synced' ? now() : null, 'last_error' => $syncError,
                    'finished_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'health_acknowledged_at' => null,
                ]);
                if (! $completed) {
                    throw new RuntimeException('备份任务租约已失效。');
                }
                // Retention is applied only after a new complete backup passes validation.
                try {
                    $this->backups->prune($file);
                } catch (\Throwable) {
                    BackupRun::whereKey($run->id)->where('status', 'completed')->update([
                        'last_error' => ($syncError ? $syncError.' ' : '').'完整备份已完成，旧备份保留清理失败，请检查文件权限。',
                    ]);
                }
            } catch (\Throwable) {
                BackupRun::whereKey($run->id)->where('lease_token', $run->lease_token)->update([
                    'status' => 'failed', 'phase' => '备份失败',
                    'last_error' => '备份失败，请检查 PostgreSQL 客户端、文件权限、磁盘空间和备份体积。',
                    'finished_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'health_acknowledged_at' => null,
                ]);
            }
            app(HeartbeatService::class)->beat('backups');
            return true;
        } finally {
            $lock->release();
        }
    }
}
