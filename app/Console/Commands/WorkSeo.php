<?php

namespace App\Console\Commands;

use App\Services\HeartbeatService;
use App\Services\MaintenanceWriteBarrier;
use App\Services\SeoQueue;
use Illuminate\Console\Command;

class WorkSeo extends Command
{
    protected $signature = 'seo:work {--once} {--limit=5}';
    protected $description = 'Process search engine deliveries without delaying customer notifications';

    public function handle(SeoQueue $queue, MaintenanceWriteBarrier $barrier): int
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
                $count = $barrier->run(function () use ($queue) {
                    app(HeartbeatService::class)->beat('seo');
                    return $queue->process(max(1, min(100, (int) $this->option('limit'))));
                });
            } catch (\Throwable) {
                $this->error('SEO 进程暂时不可用或正在维护，稍后重试。');
                if ($this->option('once')) { return self::FAILURE; }
                $pause(5);
                continue;
            }
            if ($this->option('once') || $shouldQuit) { return self::SUCCESS; }
            if ($count === 0) { $pause(5); }
        } while (! $shouldQuit);

        return self::SUCCESS;
    }
}
