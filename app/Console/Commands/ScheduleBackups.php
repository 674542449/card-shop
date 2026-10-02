<?php

namespace App\Console\Commands;

use App\Services\BackupQueue;
use Illuminate\Console\Command;

class ScheduleBackups extends Command
{
    protected $signature = 'shop:backup-schedule';
    protected $description = 'Enqueue the configured daily full backup, disabled by default';

    public function handle(BackupQueue $queue, \App\Services\MaintenanceWriteBarrier $barrier): int
    {
        if ($run = $barrier->run(fn () => $queue->schedule())) {
            $this->line('备份任务 '.$run->id.'：'.$run->status);
        }
        return self::SUCCESS;
    }
}
