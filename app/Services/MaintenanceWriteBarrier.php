<?php

namespace App\Services;

use App\Exceptions\MaintenanceWriteBlockedException;

/** All HTTP requests and business workers share this lock on the shared storage volume. */
class MaintenanceWriteBarrier
{
    public function run(callable $work): mixed
    {
        $handle = $this->open();
        try {
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                throw new MaintenanceWriteBlockedException('商城正在恢复，请稍后重试。');
            }
            // Check after acquiring the lock so a restore cannot race this check.
            if (app()->isDownForMaintenance()) {
                throw new MaintenanceWriteBlockedException('商城正在维护，请稍后重试。');
            }
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function restore(callable $work): mixed
    {
        if (! app()->isDownForMaintenance()) {
            throw new MaintenanceWriteBlockedException('正式库恢复须先进入维护模式并暂停所有写入进程。');
        }
        $handle = $this->open();
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new MaintenanceWriteBlockedException('仍有写入请求、任务或其他恢复进行中，拒绝恢复。');
            }
            if (! app()->isDownForMaintenance()) {
                throw new MaintenanceWriteBlockedException('维护状态已变化，拒绝恢复。');
            }
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    protected function open()
    {
        $path = storage_path('framework/shop-write.lock');
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0770, true)) {
            throw new MaintenanceWriteBlockedException('维护锁目录无法创建。');
        }
        $handle = fopen($path, 'c');
        if (! $handle) {
            throw new MaintenanceWriteBlockedException('维护锁无法打开。');
        }
        return $handle;
    }
}
