<?php

namespace App\Console\Commands;

use App\Services\BackupQueue;
use Illuminate\Console\Command;

class WorkBackups extends Command
{
    protected $signature = 'shop:backup-work {--once : Process at most one queued backup}';
    protected $description = 'Run the dedicated durable full-backup worker';

    public function handle(BackupQueue $queue, \App\Services\MaintenanceWriteBarrier $barrier): int
    {
        do {
            settings_memo(clear: true);
            try {
                $processed = $barrier->run(fn () => $queue->process());
            } catch (\Throwable) {
                // Do not leak database connection strings or mount details in logs.
                $this->error('备份进程暂时无法访问数据库或锁服务，稍后重试。');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(5);
                continue;
            }
            if ($this->option('once')) {
                return self::SUCCESS;
            }
            if (! $processed) {
                sleep(5);
            }
        } while (true);
    }
}
