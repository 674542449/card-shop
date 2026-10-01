<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AssetMaintenanceService
{
    public function restoreReferences(array $values): void
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            preg_match_all('~ /storage/(uploads/\d{4}/\d{2}/[A-Za-z0-9]{32}\.(?:jpg|png|gif|webp|ico))~x', $value, $matches);
            foreach (array_unique($matches[1]) as $path) {
                if (Storage::disk('local')->exists('asset-quarantine/'.$path)) {
                    $this->restore($path);
                }
            }
        }
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
            $result[] = ['path' => $path, 'url' => '/storage/'.$path, 'size' => $disk->size($path),
                'modified_at' => date(DATE_ATOM, $disk->lastModified($path)), 'referenced' => str_contains($refs, '/storage/'.$path),
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
        $disk = Storage::disk('public');
        if (! $disk->exists($path) || str_contains($this->references(), '/storage/'.$path)) {
            throw new RuntimeException('素材不存在或仍在使用，不能归档。');
        }
        if ($disk->lastModified($path) > time() - 7 * 86400) {
            throw new RuntimeException('上传未满 7 天，暂不归档，以免影响未保存的编辑。');
        }
        Storage::disk('local')->put('asset-quarantine/'.$path, $disk->get($path));
        $disk->delete($path);
    }

    public function restore(string $path): void
    {
        $this->validate($path);
        $local = Storage::disk('local');
        if (! $local->exists('asset-quarantine/'.$path)) {
            throw new RuntimeException('归档素材不存在。');
        }
        Storage::disk('public')->put($path, $local->get('asset-quarantine/'.$path));
        $local->delete('asset-quarantine/'.$path);
    }
}
