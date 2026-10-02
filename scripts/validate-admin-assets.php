<?php

/** Standalone artifact validation: does not bootstrap the app or read environment secrets. */
$root = dirname(__DIR__).'/public/admin-assets';
try {
    $manifest = json_decode((string) file_get_contents($root.'/.vite/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ! $manifest || ! is_file($root.'/index.html')
        || ! preg_match('/^[a-f0-9]{32}$/D', trim((string) file_get_contents($root.'/.build-stamp')))) {
        throw new RuntimeException('Missing entry, manifest or build stamp.');
    }
    foreach ($manifest as $entry) {
        foreach (array_merge([$entry['file'] ?? ''], $entry['css'] ?? [], $entry['assets'] ?? []) as $file) {
            if (! is_string($file) || $file === '' || str_contains($file, '..') || str_starts_with($file, '/')
                || str_contains($file, '\\') || ! is_file($root.'/'.$file)) {
                throw new RuntimeException('Manifest contains a missing or unsafe file.');
            }
        }
        foreach (array_merge($entry['imports'] ?? [], $entry['dynamicImports'] ?? []) as $key) {
            if (! isset($manifest[$key])) { throw new RuntimeException('Manifest import is missing.'); }
        }
    }
    echo 'Admin artifact manifest and files verified.'.PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
