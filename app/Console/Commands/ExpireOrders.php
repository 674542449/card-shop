<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = '将超过支付时限的待支付订单标记为过期，并把它们锁定的卡密释放回库存';

    public function handle(OrderService $orders): int
    {
        // Without this running on a schedule, cards locked by an abandoned order stay
        // locked forever and are permanently subtracted from sellable stock.
        $count = $orders->expireOrders();

        if ($count > 0) {
            $this->info("已过期 {$count} 个订单，卡密已释放回库存。");
            Log::info('Expired pending orders', ['count' => $count]);
        }

        return self::SUCCESS;
    }
}
