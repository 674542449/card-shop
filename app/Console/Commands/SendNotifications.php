<?php

namespace App\Console\Commands;

use App\Services\NotificationQueue;
use Illuminate\Console\Command;

class SendNotifications extends Command
{
    protected $signature = 'notifications:send {--limit=10 : Maximum deliveries per pass} {--work : Keep polling}';
    protected $description = 'Send durable order emails and operator alerts with bounded retries';

    public function handle(NotificationQueue $queue): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        do {
            settings_memo(clear: true);
            app(\App\Services\HeartbeatService::class)->beat('notifications');
            $count = $queue->process($limit);
            $count += app(\App\Services\SeoQueue::class)->process(2);
            if (!$this->option('work')) {
                $this->info("Processed {$count} notification(s).");
                break;
            }
            if ($count === 0) {
                sleep(5);
            }
        } while (true);
        return self::SUCCESS;
    }
}
