<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use App\Models\BackupRun;
use App\Services\AssetMaintenanceService;
use App\Services\BackupQueue;
use App\Services\CloudBackupStorageService;
use App\Services\RestoreQueue;
use App\Services\ShopBackupService;
use App\Support\AdminListQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Http\Resources\Admin\AdminRecordResource;
use App\Policies\AdminPolicy;

class MaintenanceController extends Controller
{
    private function authorizeBackup(string $ability = 'backups.read'): void
    {
        AdminPolicy::authorize(request()->attributes->get('admin'), $ability);
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

    public function backups(ShopBackupService $service, RestoreQueue $restoreQueue, CloudBackupStorageService $cloud)
    {
        $this->authorizeBackup();
        $files = glob($service->directory().'/shop-*.tar.gz') ?: [];
        usort($files, static fn (string $a, string $b): int => ((int) @filemtime($b) <=> (int) @filemtime($a)) ?: strcmp($b, $a));

        $providers = array_map(
            static fn (string $key): array => ['key' => $key, 'label' => CloudBackupStorageService::label($key)],
            $cloud->enabledProviders(),
        );

        return response()->json([
            'data' => array_map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'created_at' => date(DATE_ATOM, filemtime($f))], $files),
            'runs' => AdminRecordResource::collection(BackupRun::orderByDesc('id')->limit(20)->get())->resolve(request()),
            'health' => app(\App\Services\HeartbeatService::class)->backupHealth(),
            'restore' => $restoreQueue->publicState($restoreQueue->status()),
            'enabled_cloud_providers' => $providers,
        ]);
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
        $this->authorizeBackup('backups.write');
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
        $this->authorizeBackup();
        $size = AdminListQuery::pageSize($request, 20, ['status' => 'nullable|in:pending,running,completed,failed', 'needs_attention' => 'nullable|boolean']);
        return response()->json(BackupRun::when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('needs_attention'), fn ($q) => $q->whereNull('health_acknowledged_at')
                ->where(fn ($failure) => $failure->where('status', 'failed')->orWhereNotNull('last_error')))
            ->orderByDesc('id')->paginate($size)->through(fn ($run) => (new AdminRecordResource($run))->resolve($request)));
    }

    public function backupRun(BackupRun $run)
    {
        $this->authorizeBackup();
        return response()->json((new AdminRecordResource($run))->resolve(request()));
    }

    public function retryBackup(BackupRun $run, BackupQueue $queue)
    {
        $this->authorizeBackup('backups.write');
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
        $this->authorizeBackup('backups.write');
        $updated = BackupRun::whereKey($run->id)->whereNull('health_acknowledged_at')
            ->where(fn ($q) => $q->where('status', 'failed')->orWhereNotNull('last_error'))
            ->update(['health_acknowledged_at' => now()]);
        abort_unless($updated, 422, '该任务没有待确认的失败。');
        OperationLog::log('确认备份告警', 'backup_run', $run->id, '历史失败已确认，文件和任务记录保留');
        return response()->json(['message' => '已确认这次失败；后续新失败仍会告警。']);
    }

    public function download(string $name, ShopBackupService $service)
    {
        $this->authorizeBackup();
        OperationLog::log('下载完整备份', 'backup', null, $name);

        return response()->download($this->file($name, $service), null, ['Cache-Control' => 'no-store, private']);
    }

    public function validateBackup(string $name, ShopBackupService $service)
    {
        $this->authorizeBackup('backups.write');
        try {
            $manifest = $service->validate($this->file($name, $service));
        } catch (\Throwable) {
            return response()->json(['message' => '备份校验失败。'], 422);
        }

        return response()->json(['message' => '备份清单和全部文件校验通过。', 'created_at' => $manifest['created_at'], 'files' => count($manifest['files'])]);
    }

    public function restoreStatus(RestoreQueue $restoreQueue, CloudBackupStorageService $cloud)
    {
        $this->authorizeBackup();

        $providers = array_map(
            static fn (string $key): array => ['key' => $key, 'label' => CloudBackupStorageService::label($key)],
            $cloud->enabledProviders(),
        );

        return response()->json([
            'restore' => $restoreQueue->publicState($restoreQueue->status()),
            'enabled_cloud_providers' => $providers,
        ]);
    }

    public function restoreBackup(Request $request, string $name, ShopBackupService $service, RestoreQueue $restoreQueue)
    {
        $this->authorizeBackup('backups.write');
        $admin = $request->attributes->get('admin');
        $this->validateRestoreConfirmation($request, $admin);

        $path = $this->file($name, $service);
        try {
            $job = $restoreQueue->enqueue($path, 'local', $admin->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        OperationLog::log('提交全量恢复', 'backup_restore', null, $name);

        return response()->json([
            'message' => '恢复任务已提交，站点将短暂进入维护模式（约 10–30 秒）自动完成恢复。',
            'restore' => $job,
        ], 202);
    }

    public function uploadBackup(Request $request, ShopBackupService $service, RestoreQueue $restoreQueue)
    {
        $this->authorizeBackup('backups.write');
        $admin = $request->attributes->get('admin');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'restore_now' => ['nullable', 'boolean'],
        ], [
            'file.required' => '请选择要上传的 .tar.gz 备份归档文件。',
            'file.max' => '单次浏览器上传上限 100 MB；更大的备份包请通过云端对象存储拉取或使用 scripts/restore.sh 恢复。',
        ]);

        $restoreNow = (bool) ($data['restore_now'] ?? false);
        if ($restoreNow) {
            $this->validateRestoreConfirmation($request, $admin);
        }

        $uploaded = $request->file('file');
        $originalName = strtolower((string) $uploaded->getClientOriginalName());
        if (! str_ends_with($originalName, '.tar.gz')) {
            return response()->json(['message' => '仅支持上传 .tar.gz 格式的 CardShop 完整备份归档。'], 422);
        }

        try {
            $imported = $service->importValidatedArchive($uploaded->getRealPath(), 'upload');
        } catch (\Throwable $e) {
            $msg = $e instanceof \RuntimeException ? $e->getMessage() : '上传的备份包校验失败，请确认文件完整且与当前密钥匹配。';

            return response()->json(['message' => $msg], 422);
        }

        OperationLog::log('上传完整备份', 'backup', null, $imported['filename']);

        if ($restoreNow) {
            try {
                $job = $restoreQueue->enqueue($imported['path'], 'upload', $admin->id);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            OperationLog::log('提交上传备份恢复', 'backup_restore', null, $imported['filename']);

            return response()->json([
                'message' => '备份包已上传校验并提交恢复任务，站点将短暂进入维护模式自动完成恢复。',
                'file' => ['name' => $imported['filename'], 'size' => $imported['size']],
                'restore' => $job,
            ], 202);
        }

        return response()->json([
            'message' => '备份包上传并校验通过，已加入本地备份列表。',
            'file' => ['name' => $imported['filename'], 'size' => $imported['size']],
        ], 201);
    }

    public function cloudBackups(Request $request, CloudBackupStorageService $cloud)
    {
        $this->authorizeBackup();
        $data = $request->validate([
            'provider' => ['required', 'in:s3,r2,oss,cos'],
        ]);

        $provider = (string) $data['provider'];
        try {
            $items = $cloud->listBackups($provider);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: '获取云端备份列表失败。'], 422);
        }

        return response()->json([
            'provider' => $provider,
            'label' => CloudBackupStorageService::label($provider),
            'data' => $items,
        ]);
    }

    public function pullCloudBackup(Request $request, CloudBackupStorageService $cloud, ShopBackupService $service, RestoreQueue $restoreQueue)
    {
        $this->authorizeBackup('backups.write');
        $admin = $request->attributes->get('admin');

        $data = $request->validate([
            'provider' => ['required', 'in:s3,r2,oss,cos'],
            'key' => ['required', 'string', 'max:512'],
            'restore_now' => ['nullable', 'boolean'],
        ]);

        $restoreNow = (bool) ($data['restore_now'] ?? false);
        if ($restoreNow) {
            $this->validateRestoreConfirmation($request, $admin);
        }

        $provider = (string) $data['provider'];
        $key = (string) $data['key'];

        if (! is_dir($service->directory())) {
            $appDir = storage_path('app');
            if (! is_dir($appDir)) {
                mkdir($appDir, 0755, true);
            }
            mkdir($service->directory(), 0700, true);
        }

        $tempFile = $service->directory() . DIRECTORY_SEPARATOR . '.pull-' . Str::uuid() . '.tar.gz';
        // Avoid hitting PHP-FPM's 30s execution limit while downloading and hashing large cloud backups.
        @set_time_limit(600);
        try {
            $cloud->downloadFile($provider, $key, $tempFile);
            $imported = $service->importValidatedArchive($tempFile, $provider);
        } catch (\Throwable $e) {
            $msg = $e instanceof \RuntimeException ? $e->getMessage() : '从云端拉取或校验备份失败。';

            return response()->json(['message' => $msg], 422);
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }

        OperationLog::log('拉取云端备份', 'backup', null, CloudBackupStorageService::label($provider) . ' -> ' . $imported['filename']);

        if ($restoreNow) {
            try {
                $job = $restoreQueue->enqueue($imported['path'], 'cloud:' . $provider, $admin->id);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            OperationLog::log('提交云端备份恢复', 'backup_restore', null, $imported['filename']);

            return response()->json([
                'message' => '已从 ' . CloudBackupStorageService::label($provider) . ' 拉取校验并提交恢复任务，站点将短暂进入维护模式自动完成恢复。',
                'file' => ['name' => $imported['filename'], 'size' => $imported['size']],
                'restore' => $job,
            ], 202);
        }

        return response()->json([
            'message' => '已从 ' . CloudBackupStorageService::label($provider) . ' 拉取并校验通过：' . $imported['filename'],
            'file' => ['name' => $imported['filename'], 'size' => $imported['size']],
        ], 201);
    }

    private function validateRestoreConfirmation(Request $request, $admin): void
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:128'],
            'confirm' => ['required', 'in:RESTORE'],
        ], [
            'password.required' => '请输入当前管理员登录密码以确认全量恢复。',
            'confirm.in' => '请确认执行恢复操作。',
        ]);

        if (! $admin || ! Hash::check($data['password'], (string) $admin->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'password' => ['管理员密码错误，拒绝执行全量恢复。'],
            ]);
        }
    }
}
