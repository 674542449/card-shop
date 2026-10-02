<?php

namespace App\Console\Commands;

use App\Services\ShopBackupService;
use Illuminate\Console\Command;

class ShopRestore extends Command
{
    protected $signature = 'shop:restore {archive} {--database=} {--confirm=} {--verify-only} {--include-config}';

    protected $description = 'Verify a backup or restore it to an explicitly named database';

    public function handle(ShopBackupService $service): int
    {
        try {
            $file = realpath($this->argument('archive'));
            if (! $file) {
                $this->error('备份文件不存在。');

                return self::FAILURE;
            }
            $manifest = $service->validate($file);
            if ($this->option('verify-only')) {
                $this->line(json_encode($manifest, JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }
            $database = (string) $this->option('database');
            if (! $database || $this->option('confirm') !== 'restore:'.$database) {
                $this->error('请明确指定 --database=目标库 --confirm=restore:目标库；恢复会覆盖目标库。');

                return self::FAILURE;
            }
            if ($database === config('database.connections.pgsql.database')) {
                if (! app()->isDownForMaintenance()) {
                    $this->error('正式库恢复须先 artisan down 并暂停全部写入进程；当前服务未停写，已拒绝。');
                    return self::FAILURE;
                }
            }
            $service->restore($file, $database, (bool) $this->option('include-config'), true);
            $this->info('恢复完成。正式库恢复后请清理配置缓存并重启应用和工作进程。');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($e instanceof \App\Exceptions\MaintenanceWriteBlockedException) {
                $this->error($e->getMessage());
            } elseif ($e instanceof \App\Exceptions\SecretStorageException) {
                $this->error('独立密钥无法读取，请检查对应密钥文件；正式库保持维护模式。');
            } elseif ($e instanceof \RuntimeException && preg_match('/^(生产运行配置为外部只读挂载|备份对应的独立密钥|备份独立密钥版本标识|发现未结束的恢复|恢复失败且部分文件撤销)/u', $e->getMessage())) {
                $this->error($e->getMessage());
            } else {
                $this->error('备份校验或恢复失败，请检查备份文件、PostgreSQL 客户端、目标库和磁盘空间；正式库保持维护模式。');
            }

            return self::FAILURE;
        }
    }
}
