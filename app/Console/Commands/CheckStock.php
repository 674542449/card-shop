<?php

namespace App\Console\Commands;

use App\Services\StockAlertService;
use Illuminate\Console\Command;

class CheckStock extends Command
{
    protected $signature = 'stock:check';
    protected $description = 'Queue deduplicated low-stock alerts';

    public function handle(StockAlertService $alerts, \App\Services\MaintenanceWriteBarrier $barrier): int
    {
        $this->info('Queued '.$barrier->run(fn () => $alerts->scan()).' stock alert(s).');
        return self::SUCCESS;
    }
}
