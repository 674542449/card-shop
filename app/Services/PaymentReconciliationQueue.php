<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The scheduler only creates durable jobs. Gateway I/O belongs to its own worker. */
class PaymentReconciliationQueue
{
    public function enabled(): bool
    {
        return in_array((string) setting('payment_reconciliation_enabled', '0'), ['1', 'true'], true);
    }

    public function enqueueDue(int $limit = 10): int
    {
        if (! $this->enabled()) { return 0; }
        PaymentAttempt::where('status', 'processing')->where('lease_expires_at', '<=', now())
            ->update(['status' => 'uncertain', 'error_code' => 'worker_interrupted', 'lease_token' => null, 'lease_expires_at' => null]);
        $orders = Order::whereIn('status', ['pending', 'expired'])->where(fn ($q) => $q
            ->where('created_at', '>', now()->subDays(2))->orWhereExists(fn ($a) => $a->selectRaw('1')
                ->from('payment_attempts')->whereColumn('order_id', 'orders.id')->where('status', 'uncertain')))
            ->where('created_at', '<', now()->subMinutes(2))->where('payment_method', '!=', 'manual')
            ->where(fn ($q) => $q->whereNull('reconciled_at')->orWhere('reconciled_at', '<', now()->subMinutes(10)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_reconciliation_jobs')
                ->whereColumn('order_id', 'orders.id')->whereIn('status', ['pending', 'processing', 'failed']))
            ->orderByRaw('reconciled_at asc nulls first')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        $count = 0;
        foreach ($orders as $id) {
            $count += DB::transaction(function () use ($id) {
                // Serialize the unique job even when several schedulers run together.
                $order = Order::whereKey($id)->lockForUpdate()->first();
                if (! $order || ! in_array($order->status, ['pending', 'expired'], true)) { return 0; }
                $job = PaymentReconciliationJob::firstOrCreate(['order_id' => $id], ['available_at' => now(), 'queued_at' => now()]);
                if ($job->wasRecentlyCreated) { return 1; }
                if ($job->status !== 'completed') { return 0; }
                $job->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'queued_at' => now(),
                    'reserved_at' => null, 'lease_token' => null, 'last_error' => null, 'finished_at' => null]);
                return 1;
            });
        }
        return $count;
    }

    public function retryFailed(): int
    {
        if (! $this->enabled()) { return 0; }
        return PaymentReconciliationJob::where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0,
            'available_at' => now(), 'queued_at' => now(), 'reserved_at' => null, 'lease_token' => null, 'last_error' => null, 'finished_at' => null]);
    }

    public function process(int $limit = 10): int
    {
        app(HeartbeatService::class)->beat('reconciliation');
        if (! $this->enabled()) { return 0; }
        $count = 0;
        while ($count < $limit && ($job = $this->claim())) {
            $success = true;
            try {
                $order = Order::find($job->order_id);
                if ($order && in_array($order->status, ['pending', 'expired'], true)) {
                    app(PaymentReconciliationService::class)->sync($order);
                }
                app(HeartbeatService::class)->recordReconciliation(true);
            } catch (\Throwable) {
                $success = false;
                app(HeartbeatService::class)->recordReconciliation(false);
            }
            PaymentReconciliationJob::whereKey($job->id)->where('lease_token', $job->lease_token)->update([
                'status' => $success ? 'completed' : ($job->attempts >= 5 ? 'failed' : 'pending'),
                'lease_token' => null, 'reserved_at' => null,
                'last_error' => $success ? null : '网关对账失败，请检查订单核对记录和网关配置。',
                'available_at' => now()->addSeconds([60, 300, 900, 3600, 3600][$job->attempts - 1]),
                'finished_at' => $success || $job->attempts >= 5 ? now() : null,
            ]);
            $count++;
            app(HeartbeatService::class)->beat('reconciliation');
        }
        return $count;
    }

    private function claim(): ?PaymentReconciliationJob
    {
        return DB::transaction(function () {
            PaymentReconciliationJob::where('status', 'processing')->where('reserved_at', '<', now()->subMinutes(5))
                ->where('attempts', '>=', 5)->update(['status' => 'failed', 'lease_token' => null,
                    'finished_at' => now(), 'last_error' => '对账进程中断且重试已耗尽，请人工重试。']);
            $job = PaymentReconciliationJob::where('attempts', '<', 5)->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('status', 'pending')->where('available_at', '<=', now()))
                ->orWhere(fn ($p) => $p->where('status', 'processing')->where('reserved_at', '<', now()->subMinutes(5))))
                ->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if ($job) {
                $job->update(['status' => 'processing', 'attempts' => $job->attempts + 1,
                    'reserved_at' => now(), 'lease_token' => (string) Str::uuid()]);
            }
            return $job;
        });
    }
}
