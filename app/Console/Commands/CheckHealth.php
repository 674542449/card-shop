<?php

namespace App\Console\Commands;

use App\Services\HeartbeatService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckHealth extends Command
{
    protected $signature = 'shop:health {--scheduler : Record the scheduler heartbeat} {--alert : Send a direct operator alert once per 15 minutes}';

    protected $description = 'Return worker/scheduler liveness and backlog for external monitoring';

    public function handle(HeartbeatService $service): int
    {
        if ($this->option('scheduler')) {
            $service->beat('scheduler');
        }
        $health = $service->health();
        $this->line(json_encode($health, JSON_UNESCAPED_UNICODE));
        $healthy = $health['notifications']['healthy'] && $health['scheduler']['healthy'] && (! $health['reconciliation']['enabled'] || $health['reconciliation']['healthy']) && ! $health['backlog_warning'] && $health['overdue_orders'] === 0;
        if (! $healthy && $this->option('alert') && Cache::add('shop:health-alert', true, 900)) {
            $sent = app(NotificationService::class)->sendTelegramNotification('<b>⚠ 商城后台任务异常</b>\n通知或定时任务未收到近期心跳，或有积压/逾期订单。请检查维护与推送页面和服务器进程。');
            if (! $sent) {
                Cache::forget('shop:health-alert');
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
