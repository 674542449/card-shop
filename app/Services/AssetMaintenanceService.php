<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AssetMaintenanceService
{
    private static array $locks = [];

    private function locked(\Closure $operation): mixed
    {
        $path = storage_path('framework/asset-maintenance.lock');
        if (isset(self::$locks[$path])) { return $operation(); }
        $handle = fopen($path, 'c+b');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('暂时无法锁定素材，请稍后重试。');
        }
        self::$locks[$path] = $handle;
        try { return $operation(); }
        finally { unset(self::$locks[$path]); flock($handle, LOCK_UN); fclose($handle); }
    }

    public function restoreReferences(array $values): void
    {
        $paths = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            preg_match_all('~ /storage/(uploads/\d{4}/\d{2}/[A-Za-z0-9]{32}\.(?:jpg|png|gif|webp|ico))~x', $value, $matches);
            $paths = array_merge($paths, $matches[1]);
        }
        if (! $paths) { return; }
        $this->locked(function () use ($paths) {
            foreach (array_unique($paths) as $path) {
                if (Storage::disk('local')->exists('asset-quarantine/'.$path)) {
                    $this->restore($path);
                }
            }
        });
    }

    public function references(): string
    {
        $values = [];
        foreach (['products' => ['image', 'description'], 'categories' => ['image'], 'articles' => ['cover_image', 'content'], 'settings' => ['value']] as $table => $fields) {
            foreach (DB::table($table)->get($fields) as $row) {
                foreach ($fields as $field) {
                    $values[] = (string) $row->$field;
                }
            }
        }

        return implode("\n", $values);
    }

    public function list(): array
    {
        $refs = $this->references();
        $disk = Storage::disk('public');
        $result = [];
        foreach ($disk->allFiles('uploads') as $path) {
            $referenced = str_contains($refs, '/storage/'.$path);
            $tooRecent = $disk->lastModified($path) > time() - 7 * 86400;
            $result[] = ['path' => $path, 'url' => '/storage/'.$path, 'size' => $disk->size($path),
                'modified_at' => date(DATE_ATOM, $disk->lastModified($path)), 'referenced' => $referenced,
                'can_quarantine' => ! $referenced && ! $tooRecent,
                'quarantine_reason' => $referenced ? '素材仍在使用' : ($tooRecent ? '上传未满 7 天' : null),
                'quarantined' => false];
        }
        foreach (Storage::disk('local')->allFiles('asset-quarantine/uploads') as $path) {
            $result[] = ['path' => substr($path, strlen('asset-quarantine/')), 'size' => Storage::disk('local')->size($path), 'referenced' => false, 'quarantined' => true];
        }

        return $result;
    }

    private function validate(string $path): void
    {
        if (! preg_match('~^uploads/\d{4}/\d{2}/[A-Za-z0-9]{32}\.(jpg|png|gif|webp|ico)$~D', $path)) {
            throw new RuntimeException('素材路径不允许。');
        }
    }

    public function quarantine(string $path): void
    {
        $this->validate($path);
        $this->locked(function () use ($path) {
            $disk = Storage::disk('public');
            if (! $disk->exists($path) || str_contains($this->references(), '/storage/'.$path)) {
                throw new RuntimeException('素材不存在或仍在使用，不能归档。');
            }
            if ($disk->lastModified($path) > time() - 7 * 86400) {
                throw new RuntimeException('上传未满 7 天，暂不归档，以免影响未保存的编辑。');
            }
            if (! Storage::disk('local')->put('asset-quarantine/'.$path, $disk->get($path))) {
                throw new RuntimeException('素材归档失败，原文件已保留。');
            }
            $disk->delete($path);
            // A reference may have committed after the initial scan. Saving models
            // restore after commit too, under this same lock, covering both orderings.
            if (str_contains($this->references(), '/storage/'.$path)) {
                $this->restore($path);
                throw new RuntimeException('素材刚被引用，已保留公开文件，不能归档。');
            }
        });
    }

    public function restore(string $path): void
    {
        $this->validate($path);
        $this->locked(function () use ($path) {
            $local = Storage::disk('local');
            if (! $local->exists('asset-quarantine/'.$path)) {
                throw new RuntimeException('归档素材不存在。');
            }
            if (! Storage::disk('public')->put($path, $local->get('asset-quarantine/'.$path))) {
                throw new RuntimeException('素材恢复失败，归档文件已保留。');
            }
            $local->delete('asset-quarantine/'.$path);
        });
    }
}
