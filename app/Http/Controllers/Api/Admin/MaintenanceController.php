<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Services\AssetMaintenanceService;
use App\Services\ShopBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MaintenanceController extends Controller
{
    private function owner(): void
    {
        abort_unless(request()->attributes->get('admin')?->role === 'owner', 403);
    }

    public function assets(AssetMaintenanceService $service)
    {
        return response()->json(['data' => $service->list()]);
    }

    public function assetAction(Request $request, AssetMaintenanceService $service)
    {
        $data = $request->validate(['path' => 'required|string|max:255', 'action' => 'required|in:quarantine,restore']);
        try {
            $service->{$data['action']}($data['path']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        OperationLog::log('素材归档/恢复', 'asset', null, $data['action'].' '.$data['path']);

        return response()->json(['message' => '素材已处理。']);
    }

    public function backups(ShopBackupService $service)
    {
        $this->owner();
        $files = glob($service->directory().'/shop-*.tar.gz') ?: [];
        rsort($files);

        return response()->json(['data' => array_map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'created_at' => date(DATE_ATOM, filemtime($f))], $files)]);
    }

    private function file(string $name, ShopBackupService $service): string
    {
        abort_unless(preg_match('/^shop-[A-Za-z0-9-]+\.tar\.gz$/D', $name), 404);
        $path = $service->directory().'/'.$name;
        abort_unless(is_file($path), 404);

        return $path;
    }

    public function backup(ShopBackupService $service)
    {
        $this->owner();
        set_time_limit(1800);
        try {
            $file = Cache::lock('shop:backup', 1900)->block(1, fn () => $service->create());
        } catch (\Throwable) {
            return response()->json(['message' => '备份失败或已有备份正在执行，请检查客户端和磁盘空间。'], 422);
        }
        OperationLog::log('完整备份', 'backup', null, basename($file));

        return response()->json(['name' => basename($file)], 201);
    }

    public function download(string $name, ShopBackupService $service)
    {
        $this->owner();
        OperationLog::log('下载完整备份', 'backup', null, $name);

        return response()->download($this->file($name, $service), null, ['Cache-Control' => 'no-store, private']);
    }

    public function validateBackup(string $name, ShopBackupService $service)
    {
        $this->owner();
        try {
            $manifest = $service->validate($this->file($name, $service));
        } catch (\Throwable) {
            return response()->json(['message' => '备份校验失败。'], 422);
        }

        return response()->json(['message' => '备份清单和全部文件校验通过。', 'created_at' => $manifest['created_at'], 'files' => count($manifest['files'])]);
    }
}
