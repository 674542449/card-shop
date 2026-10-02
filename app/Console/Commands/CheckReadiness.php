<?php

namespace App\Console\Commands;

use App\Services\ReadinessService;
use Illuminate\Console\Command;

class CheckReadiness extends Command
{
    protected $signature = 'shop:ready';
    protected $description = 'Check database, Redis, runtime storage and published frontend readiness';

    public function handle(ReadinessService $readiness): int
    {
        $result = $readiness->check();
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
