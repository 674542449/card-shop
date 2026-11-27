<?php

namespace App\Console\Commands;

use App\Services\BackupQueue;
use App\Services\RestoreQueue;
use Illuminate\Console\Command;

class WorkBackups extends Command
{
    protected $signature = 'shop:backup-work {--once : Process at most one queued backup or restore}';
    protected $description = 'Run the dedicated durable full-backup and restore worker';

    public function handle(BackupQueue $queue, RestoreQueue $restoreQueue, \App\Services\MaintenanceWriteBarrier $barrier): int
    {
        $shouldQuit = false;
        if (extension_loaded('pcntl') && defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$shouldQuit): void {
                $shouldQuit = true;
            });
        }
        $pause = static function (int $seconds) use (&$shouldQuit): void {
            for ($i = 0; $i < $seconds && ! $shouldQuit; $i++) {
                sleep(1);
            }
        };

        do {
            settings_memo(clear: true);
            try {
                // Pending one-click restores run outside LOCK_SH so MaintenanceWriteBarrier::restore()
                // can safely acquire LOCK_EX once in-flight HTTP requests drain.
                if ($restoreQueue->hasPending()) {
                    $processed = $restoreQueue->process();
                } else {
                    $processed = $barrier->run(fn () => $queue->process());
                }
            } catch (\Throwable) {
                // Do not leak database connection strings or mount details in logs.
                $this->error('备份进程暂时无法访问数据库或锁服务，稍后重试。');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                $pause(5);
                continue;
            }
            if ($this->option('once') || $shouldQuit) {
                return self::SUCCESS;
            }
            if (! $processed) {
                $pause(5);
            }
        } while (! $shouldQuit);

        return self::SUCCESS;
    }
}
