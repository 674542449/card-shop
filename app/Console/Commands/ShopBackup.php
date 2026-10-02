<?php

namespace App\Console\Commands;

use App\Services\BackupQueue;
use Illuminate\Console\Command;

class ShopBackup extends Command
{
    protected $signature = 'shop:backup {--wait : Execute the queued backup in this CLI process}';

    protected $description = 'Private database + uploads + configuration backup with checksums';

    public function handle(BackupQueue $queue): int
    {
        try {
            $run = $queue->enqueue('cli');
            if ($this->option('wait')) {
                $deadline = microtime(true) + BackupQueue::LEASE_SECONDS + 60;
                do {
                    // A dedicated daemon may already own this job. Wait for its
                    // result instead of reporting failure merely because the lock is busy.
                    if ($run->status === 'pending') {
                        $queue->process();
                    }
                    $run->refresh();
                    if (! in_array($run->status, ['pending', 'running'], true)) {
                        break;
                    }
                    usleep(500000);
                } while (microtime(true) < $deadline);
                if ($run->status !== 'completed') {
                    $this->error($run->last_error ?: '备份尚未完成，请检查专用备份进程和任务状态。');
                    return self::FAILURE;
                }
                $this->line($run->filename);
                if ($run->last_error) {
                    $this->error($run->last_error);
                    return self::FAILURE;
                }
            } else {
                $this->line('备份任务 '.$run->id.' 已提交；运行 shop:backup-work 处理。');
            }
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('备份失败或已有备份正在执行，请检查 PostgreSQL 客户端、连接和磁盘空间。');

            return self::FAILURE;
        }
    }
}
