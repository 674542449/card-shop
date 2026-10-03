<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Models\BackupRun;
use App\Services\AssetMaintenanceService;
use App\Services\BackupQueue;
use App\Services\ShopBackupService;
use App\Support\AdminListQuery;
use Illuminate\Http\Request;
use App\Http\Resources\Admin\AdminRecordResource;
use App\Policies\AdminPolicy;

class MaintenanceController extends Controller
{
    private function owner(): void
    {
        AdminPolicy::authorize(request()->attributes->get('admin'), 'backups.read');
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

        return response()->json(['data' => array_map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'created_at' => date(DATE_ATOM, filemtime($f))], $files),
            'runs' => AdminRecordResource::collection(BackupRun::orderByDesc('id')->limit(20)->get())->resolve(request()), 'health' => app(\App\Services\HeartbeatService::class)->backupHealth()]);
    }

    private function file(string $name, ShopBackupService $service): string
    {
        abort_unless(preg_match('/^shop-[A-Za-z0-9-]+\.tar\.gz$/D', $name), 404);
        $path = $service->directory().'/'.$name;
        abort_unless(is_file($path), 404);

        return $path;
    }

    public function backup(BackupQueue $queue)
    {
        $this->owner();
        try {
            $run = $queue->enqueue('manual', request()->attributes->get('admin')->id);
        } catch (\Throwable) {
            return response()->json(['message' => '暂时无法提交备份任务，请稍后重试。'], 503);
        }
        OperationLog::log('提交完整备份', 'backup_run', $run->id, '备份任务已入队');
        return response()->json(['message' => '备份任务已提交，页面将显示进度。', 'run' => (new AdminRecordResource($run))->resolve(request())], 202);
    }

    public function backupRuns(Request $request)
    {
        $this->owner();
        $size = AdminListQuery::pageSize($request, 20, ['status' => 'nullable|in:pending,running,completed,failed', 'needs_attention' => 'nullable|boolean']);
        return response()->json(BackupRun::when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('needs_attention'), fn ($q) => $q->whereNull('health_acknowledged_at')
                ->where(fn ($failure) => $failure->where('status', 'failed')->orWhereNotNull('last_error')))
            ->orderByDesc('id')->paginate($size)->through(fn ($run) => (new AdminRecordResource($run))->resolve($request)));
    }

    public function backupRun(BackupRun $run)
    {
        $this->owner();
        return response()->json((new AdminRecordResource($run))->resolve(request()));
    }

    public function retryBackup(BackupRun $run, BackupQueue $queue)
    {
        $this->owner();
        try {
            $updated = $queue->retry($run);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        OperationLog::log('重试完整备份', 'backup_run', $run->id, '备份任务重新入队');
        return response()->json(['message' => '备份任务已重新提交。', 'run' => (new AdminRecordResource($updated))->resolve(request())], 202);
    }

    public function acknowledgeBackup(BackupRun $run)
    {
        $this->owner();
        $updated = BackupRun::whereKey($run->id)->whereNull('health_acknowledged_at')
            ->where(fn ($q) => $q->where('status', 'failed')->orWhereNotNull('last_error'))
            ->update(['health_acknowledged_at' => now()]);
        abort_unless($updated, 422, '该任务没有待确认的失败。');
        OperationLog::log('确认备份告警', 'backup_run', $run->id, '历史失败已确认，文件和任务记录保留');
        return response()->json(['message' => '已确认这次失败；后续新失败仍会告警。']);
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
