<?php

namespace App\Console\Commands;

use App\Services\ShopBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ShopBackup extends Command
{
    protected $signature = 'shop:backup';

    protected $description = 'Private database + uploads + configuration backup with checksums';

    public function handle(ShopBackupService $service): int
    {
        try {
            $file = Cache::lock('shop:backup', 1900)->block(1, fn () => $service->create());
            $this->line($file);

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('备份失败或已有备份正在执行，请检查 PostgreSQL 客户端、连接和磁盘空间。');

            return self::FAILURE;
        }
    }
}
