<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HeartbeatService
{
    public function beat(string $name, array $details = []): void
    {
        DB::table('service_heartbeats')->upsert([['name' => $name, 'last_seen_at' => now(), 'details' => json_encode($details)]], ['name'], ['last_seen_at', 'details']);
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
        $result['backlog_warning'] = $oldest && Carbon::parse($oldest)->lt(now()->subMinutes(15));
        $result['overdue_orders'] = Order::where('status', 'pending')->where('expires_at', '<', now()->subMinutes(2))->count();

        return $result;
    }
}
