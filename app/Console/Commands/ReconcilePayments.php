<?php

namespace App\Console\Commands;

use App\Services\MaintenanceWriteBarrier;
use App\Services\PaymentReconciliationQueue;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=10} {--retry-failed : Explicitly retry exhausted jobs}';

    protected $description = 'Enqueue recent gateway orders without running network requests in the scheduler';

    public function handle(PaymentReconciliationQueue $queue, MaintenanceWriteBarrier $barrier): int
    {
        $count = $barrier->run(function () use ($queue) {
            if ($this->option('retry-failed')) { $queue->retryFailed(); }
            return $queue->enqueueDue((int) $this->option('limit'));
        });
        $this->info('Enqueued '.$count.' reconciliation job(s).');

        return self::SUCCESS;
    }
}
