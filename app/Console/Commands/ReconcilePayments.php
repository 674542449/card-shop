<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\HeartbeatService;
use App\Services\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=10}';

    protected $description = 'Check recent gateway orders without trusting browser return data';

    public function handle(PaymentReconciliationService $service, HeartbeatService $health): int
    {
        $health->beat('reconciliation');
        if (! in_array((string) setting('payment_reconciliation_enabled', '0'), ['1', 'true'], true)) {
            return self::SUCCESS;
        }
        $orders = Order::whereIn('status', ['pending', 'expired'])->where('created_at', '>', now()->subDays(2))
            ->where('created_at', '<', now()->subMinutes(2))->where('payment_method', '!=', 'manual')
            ->where(fn ($q) => $q->whereNull('reconciled_at')->orWhere('reconciled_at', '<', now()->subMinutes(10)))
            ->orderByRaw('reconciled_at asc nulls first')->orderBy('id')->limit(max(1, min(50, (int) $this->option('limit'))))->get();
        foreach ($orders as $order) {
            try {
                $service->sync($order);
            } catch (\Throwable) {
            } $health->beat('reconciliation');
        }

        return self::SUCCESS;
    }
}
