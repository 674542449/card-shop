<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class ReadinessService
{
    /** Safe booleans only: never serialize transport errors, paths or credentials. */
    public function check(): array
    {
        $checks = ['database' => false, 'schema' => false, 'redis' => false, 'admin_assets' => false, 'secrets' => false,
            'storage' => is_writable(storage_path('framework')), 'maintenance' => ! app()->isDownForMaintenance()];
        try {
            DB::select('SELECT 1');
            $checks['database'] = DB::getSchemaBuilder()->hasTable('settings')
                && DB::getSchemaBuilder()->hasTable('orders');
            $required = array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php')) ?: []);
            $ran = DB::table('migrations')->pluck('migration')->all();
            $checks['schema'] = $required !== [] && array_diff($required, $ran) === [];
        } catch (\Throwable) {}
        try {
            app(\App\Security\SecretCipher::class)->metadata();
            $checks['secrets'] = true;
        } catch (\Throwable) {}
        try {
            foreach (['default', 'cache'] as $connection) {
                Redis::connection($connection)->ping();
            }
            $checks['redis'] = true;
        } catch (\Throwable) {}
        try {
            $directory = public_path('admin-assets');
            $manifest = json_decode(file_get_contents($directory.'/.vite/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $valid = is_array($manifest) && $manifest !== [] && is_file($directory.'/index.html')
                && is_file($directory.'/.build-stamp') && preg_match('/^[a-f0-9]{32}$/D', trim(file_get_contents($directory.'/.build-stamp')));
            foreach ($manifest as $entry) {
                foreach (array_merge([$entry['file'] ?? ''], $entry['css'] ?? [], $entry['assets'] ?? []) as $relative) {
                    if (! is_string($relative) || $relative === '' || str_contains($relative, '..')
                        || str_starts_with($relative, '/') || ! is_file($directory.'/'.$relative)) {
                        $valid = false;
                    }
                }
                foreach (array_merge($entry['imports'] ?? [], $entry['dynamicImports'] ?? []) as $key) {
                    if (! isset($manifest[$key])) { $valid = false; }
                }
            }
            $checks['admin_assets'] = $valid;
        } catch (\Throwable) {}
        return ['ready' => ! in_array(false, $checks, true), 'checks' => $checks];
    }
}
