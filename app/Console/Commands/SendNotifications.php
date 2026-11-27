<?php

namespace App\Console\Commands;

use App\Services\NotificationQueue;
use Illuminate\Console\Command;

class SendNotifications extends Command
{
    protected $signature = 'notifications:send {--limit=10 : Maximum deliveries per pass} {--work : Keep polling}';
    protected $description = 'Send durable order emails and operator alerts with bounded retries';

    public function handle(NotificationQueue $queue, \App\Services\MaintenanceWriteBarrier $barrier): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
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
                $count = $barrier->run(function () use ($queue, $limit) {
                    app(\App\Services\HeartbeatService::class)->beat('notifications');
                    return $queue->process($limit);
                });
            } catch (\Throwable) {
                $this->error('通知进程暂时不可用或正在维护，稍后重试。');
                if (! $this->option('work')) { return self::FAILURE; }
                $pause(5);
                continue;
            }
            if (!$this->option('work')) {
                $this->info("Processed {$count} notification(s).");
                break;
            }
            if ($shouldQuit) {
                break;
            }
            if ($count === 0) {
                $pause(5);
            }
        } while (! $shouldQuit);
        return self::SUCCESS;
    }
}
