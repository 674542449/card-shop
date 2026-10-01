<?php

namespace App\Console\Commands;

use App\Services\StockAlertService;
use Illuminate\Console\Command;

class CheckStock extends Command
{
    protected $signature = 'stock:check';
    protected $description = 'Queue deduplicated low-stock alerts';

    public function handle(StockAlertService $alerts): int
    {
        $this->info('Queued '.$alerts->scan().' stock alert(s).');
        return self::SUCCESS;
    }
}
