<?php

namespace App\Console\Commands;

use App\Services\MaintenanceWriteBarrier;
use App\Services\PaymentReconciliationQueue;
use Illuminate\Console\Command;

class WorkReconciliation extends Command
{
    protected $signature = 'payments:work {--once} {--limit=10}';
    protected $description = 'Process durable gateway reconciliation independently of scheduled tasks';

    public function handle(PaymentReconciliationQueue $queue, MaintenanceWriteBarrier $barrier): int
    {
        do {
            settings_memo(clear: true);
            try {
                $count = $barrier->run(fn () => $queue->process(max(1, min(100, (int) $this->option('limit')))));
            } catch (\Throwable) {
                $this->error('对账进程暂时不可用或正在维护，稍后重试。');
                if ($this->option('once')) { return self::FAILURE; }
                sleep(5);
                continue;
            }
            if ($this->option('once')) { return self::SUCCESS; }
            if ($count === 0) { sleep(5); }
        } while (true);
    }
}
