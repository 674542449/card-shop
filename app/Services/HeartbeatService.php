<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\BackupRun;
use App\Models\SeoDelivery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HeartbeatService
{
    public function beat(string $name, ?array $details = null): void
    {
        DB::table('service_heartbeats')->upsert([['name' => $name, 'last_seen_at' => now(), 'details' => json_encode($details ?? [])]], ['name'],
            $details === null ? ['last_seen_at'] : ['last_seen_at', 'details']);
    }

    public function recordReconciliation(bool $success): void
    {
        $this->beat('reconciliation');
        DB::transaction(function () use ($success) {
            $row = DB::table('service_heartbeats')->where('name', 'reconciliation')->lockForUpdate()->first();
            $details = json_decode($row->details ?? '{}', true) ?: [];
            $details['consecutive_failures'] = $success ? 0 : (int) ($details['consecutive_failures'] ?? 0) + 1;
            if (! $success) {
                $details['failure_version'] = (int) ($details['failure_version'] ?? 0) + 1;
                $details['last_failure_at'] = now()->toIso8601String();
            } else {
                $details['last_success_at'] = now()->toIso8601String();
            }
            DB::table('service_heartbeats')->where('name', 'reconciliation')->update(['details' => json_encode($details), 'last_seen_at' => now()]);
        });
    }

    public function acknowledgeReconciliation(int $version): void
    {
        DB::transaction(function () use ($version) {
            $row = DB::table('service_heartbeats')->where('name', 'reconciliation')->lockForUpdate()->first();
            $details = json_decode($row->details ?? '{}', true) ?: [];
            abort_if($version > (int) ($details['failure_version'] ?? 0), 422, '对账告警状态已变化，请刷新。');
            $details['acknowledged_failure_version'] = max($version, (int) ($details['acknowledged_failure_version'] ?? 0));
            $details['acknowledged_at'] = now()->toIso8601String();
            DB::table('service_heartbeats')->where('name', 'reconciliation')->update(['details' => json_encode($details)]);
        });
    }

    public function backupHealth(): array
    {
        $enabled = in_array((string) setting('backup_auto_enabled', '0'), ['1', 'true'], true);
        $pending = BackupRun::whereIn('status', ['pending', 'running']);
        $pendingCount = (clone $pending)->count();
        $oldest = (clone $pending)->min('created_at');
        $latest = BackupRun::where('status', 'completed')->orderByDesc('finished_at')->first();
        $failed = BackupRun::whereNull('health_acknowledged_at')->where(fn ($q) => $q->where('status', 'failed')->orWhereNotNull('last_error'));
        $directory = app(ShopBackupService::class)->directory();
        $disk = is_dir($directory) ? $directory : storage_path();
        $free = disk_free_space($disk);
        $total = disk_total_space($disk);
        $minFree = max(0, (int) setting('backup_min_free_mb', 1024)) * 1024 * 1024;
        $seen = DB::table('service_heartbeats')->where('name', 'backups')->value('last_seen_at');
        $workerRequired = $enabled || $pendingCount > 0;
        $stale = $enabled && (! $latest || $latest->finished_at->lt(now()->subHours(max(1, (int) setting('backup_stale_hours', 30)))));
        $spaceWarning = $free === false || $free < $minFree;
        $failedCount = (clone $failed)->count();
        // Running jobs have a lease; waiting jobs should be picked up promptly.
        $backlog = BackupRun::where('status', 'pending')->where('created_at', '<', now()->subMinutes(15))->exists();
        // Compression of one large file cannot emit PHP heartbeats. A running job is
        // allowed only within its explicit, bounded lease and maximum start age.
        $runningLease = BackupRun::where('status', 'running')->where('lease_expires_at', '>', now())
            ->where('started_at', '>', now()->subSeconds(BackupQueue::LEASE_SECONDS))->exists();
        $workerHealthy = ! $workerRequired || ($seen && Carbon::parse($seen)->gt(now()->subMinutes(10))) || $runningLease;
        return ['enabled' => $enabled, 'worker_required' => $workerRequired, 'worker_healthy' => (bool) $workerHealthy,
            'last_seen_at' => $seen, 'pending' => $pendingCount, 'oldest_pending_at' => $oldest,
            'last_success_at' => $latest?->finished_at?->toIso8601String(), 'stale_warning' => (bool) $stale,
            'free_bytes' => $free === false ? null : $free, 'total_bytes' => $total === false ? null : $total,
            'space_warning' => $spaceWarning, 'failed' => $failedCount, 'backlog_warning' => $backlog,
            'healthy' => $workerHealthy && ! $stale && ! $spaceWarning && $failedCount === 0 && ! $backlog];
    }

    public function health(): array
    {
        $beats = DB::table('service_heartbeats')->get()->keyBy('name');
        $result = [];
        foreach (['notifications', 'scheduler', 'reconciliation'] as $name) {
            $seen = $beats[$name]->last_seen_at ?? null;
            $result[$name] = ['last_seen_at' => $seen, 'healthy' => $seen && Carbon::parse($seen)->gt(now()->subMinutes($name === 'reconciliation' ? 15 : 3))];
        }
        $result['reconciliation']['enabled'] = in_array((string) setting('payment_reconciliation_enabled', '0'), ['1', 'true'], true);
        $result['notification_pending'] = NotificationDelivery::whereIn('status', ['pending', 'processing'])->count();
        $oldest = NotificationDelivery::whereIn('status', ['pending', 'processing'])->min('created_at');
        $result['oldest_notification_at'] = $oldest;
        $notificationFailures = NotificationDelivery::where('status', 'failed')->whereNull('health_acknowledged_at');
        $result['notification_failed'] = (clone $notificationFailures)->count();
        $result['notification_failure_through_id'] = (clone $notificationFailures)->max('id');
        $seoWaiting = SeoDelivery::whereIn('status', ['pending', 'processing']);
        $seoFailures = SeoDelivery::where('status', 'failed')->whereNull('health_acknowledged_at');
        $result['seo_pending'] = (clone $seoWaiting)->count();
        $result['oldest_seo_at'] = (clone $seoWaiting)->min('created_at');
        $result['seo_failed'] = (clone $seoFailures)->count();
        $result['seo_failure_through_id'] = (clone $seoFailures)->max('id');
        $result['backlog_warning'] = ($oldest && Carbon::parse($oldest)->lt(now()->subMinutes(15)))
            || ($result['oldest_seo_at'] && Carbon::parse($result['oldest_seo_at'])->lt(now()->subMinutes(15)));
        $result['overdue_orders'] = Order::where('status', 'pending')->where('expires_at', '<', now()->subMinutes(2))->count();
        $details = json_decode($beats['reconciliation']->details ?? '{}', true) ?: [];
        $result['reconciliation']['consecutive_failures'] = (int) ($details['consecutive_failures'] ?? 0);
        $result['reconciliation']['failure_version'] = (int) ($details['failure_version'] ?? 0);
        $result['reconciliation']['failure_warning'] = $result['reconciliation']['enabled']
            && $result['reconciliation']['consecutive_failures'] >= 3
            && $result['reconciliation']['failure_version'] > (int) ($details['acknowledged_failure_version'] ?? 0);
        $result['backups'] = $this->backupHealth();
        $result['observed_at'] = now()->toIso8601String();
        $result['healthy'] = $result['notifications']['healthy'] && $result['scheduler']['healthy']
            && (! $result['reconciliation']['enabled'] || ($result['reconciliation']['healthy'] && ! $result['reconciliation']['failure_warning']))
            && ! $result['backlog_warning'] && $result['overdue_orders'] === 0
            && $result['notification_failed'] === 0 && $result['seo_failed'] === 0 && $result['backups']['healthy'];

        return $result;
    }
}
