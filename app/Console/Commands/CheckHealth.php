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
        $healthy = $health['healthy'];
        if (! $healthy && $this->option('alert') && Cache::add('shop:health-alert', true, 900)) {
            $sent = app(NotificationService::class)->sendTelegramNotification("<b>⚠ 商城后台任务异常</b>\n请检查维护与推送：进程心跳、未确认失败、通知/SEO积压、对账或备份可能异常。");
            if (! $sent) {
                Cache::forget('shop:health-alert');
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
