<?php

namespace App\Console\Commands;

use App\Security\SecretCipher;
use App\Security\SecretSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EncryptStoredSecrets extends Command
{
    protected $signature = 'secrets:encrypt {--rotate : Re-encrypt existing ciphertext with the active version}
        {--batch-size=500 : Rows per transaction, between 1 and 1000}
        {--force : Confirm that a private backup exists and business writes are stopped}';

    protected $description = 'Convert legacy card/configuration plaintext in bounded, resumable transactions';

    public function handle(SecretCipher $cipher): int
    {
        $size = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT);
        if ($size === false || $size < 1 || $size > 1000) {
            $this->error('batch-size 必须在 1 至 1000 之间。');
            return self::FAILURE;
        }
        if (! $this->option('force') || (! app()->environment('testing') && ! app()->isDownForMaintenance())) {
            $this->error('请先独立备份 keyring 和商城、artisan down 并停止业务写入，再使用 --force。');
            return self::FAILURE;
        }
        try {
            $operation = fn () => $this->convert($cipher, $size);

            return app()->isDownForMaintenance()
                ? app(\App\Services\MaintenanceWriteBarrier::class)->restore($operation) : $operation();
        } catch (\Throwable) {
            $this->error('商城仍有请求、任务或恢复操作，敏感数据转换未启动。');
            return self::FAILURE;
        }
    }

    private function convert(SecretCipher $cipher, int $size): int
    {
        $locked = false;
        try {
            $cipher->metadata();
            if (! Schema::hasColumn('cards', 'content_fingerprint')) {
                $this->error('请先运行数据库迁移以建立卡密指纹索引。');
                return self::FAILURE;
            }
            $locked = (bool) DB::selectOne('SELECT pg_try_advisory_lock(20261003, 100001) AS acquired')->acquired;
            if (! $locked) {
                $this->error('另一个敏感数据转换进程正在运行。');
                return self::FAILURE;
            }
            $cards = 0;
            DB::table('cards')->select('id')->orderBy('id')->chunkById($size, function ($batch) use ($cipher, &$cards) {
                DB::transaction(function () use ($batch, $cipher, &$cards) {
                    $rows = DB::table('cards')->whereIn('id', $batch->pluck('id')->all())->orderBy('id')->lockForUpdate()->get();
                    foreach ($rows as $row) {
                        $encrypted = SecretCipher::isEncrypted($row->content);
                        $plaintext = $encrypted ? $cipher->decrypt($row->content, 'card-content') : $row->content;
                        $fingerprint = $cipher->fingerprint($plaintext);
                        $changes = ['content_fingerprint' => $fingerprint];
                        if (! $encrypted || $this->option('rotate')) {
                            $changes['content'] = $cipher->encrypt($plaintext, 'card-content');
                        }
                        if (isset($changes['content']) || $row->content_fingerprint !== $fingerprint) {
                            DB::table('cards')->where('id', $row->id)->update($changes);
                            $cards++;
                        }
                    }
                });
            });
            $settings = 0;
            DB::table('settings')->whereIn('key', SecretSettings::KEYS)->select('id')->orderBy('id')
                ->chunkById($size, function ($batch) use ($cipher, &$settings) {
                    DB::transaction(function () use ($batch, $cipher, &$settings) {
                        foreach (DB::table('settings')->whereIn('id', $batch->pluck('id')->all())->orderBy('id')->lockForUpdate()->get() as $row) {
                            if ($row->value === null || $row->value === '') {
                                continue;
                            }
                            $encrypted = SecretCipher::isEncrypted($row->value);
                            $plaintext = $encrypted ? $cipher->decrypt($row->value, 'setting:'.$row->key) : $row->value;
                            if (! $encrypted || $this->option('rotate')) {
                                DB::table('settings')->where('id', $row->id)->update(['value' => $cipher->encrypt($plaintext, 'setting:'.$row->key)]);
                                $settings++;
                            }
                        }
                    });
                });
            // Do not leave an older plaintext settings map behind in Redis.
            SecretSettings::forgetCaches();
            $this->info("转换完成：卡密 {$cards} 行，敏感配置 {$settings} 行。未改变库存、订单关系或业务时间。");
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('敏感存储转换失败；已提交批次保留，可在修复 keyring/数据库后重复执行。密文校验失败的批次不会写入。');
            return self::FAILURE;
        } finally {
            if ($locked) {
                DB::select('SELECT pg_advisory_unlock(20261003, 100001)');
            }
        }
    }
}
