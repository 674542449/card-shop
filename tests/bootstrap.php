<?php

require __DIR__.'/../vendor/autoload.php';

// Independent, public dummy test keys. Never load the development/production
// keyring, and propagate this external path to PHPUnit and process-race workers.
$testKeyring = getenv('SHOP_TEST_KEYRING_FILE') ?: sys_get_temp_dir().'/cardshop-test-secrets/shop-keyring.json';
$testDirectory = dirname($testKeyring);
if (! is_dir($testDirectory) && ! mkdir($testDirectory, 0700, true)) {
    throw new RuntimeException('Cannot prepare isolated test keyring directory.');
}
if (! is_file($testKeyring)) {
    $testHandle = fopen($testKeyring, 'x+b');
    if ($testHandle === false) { throw new RuntimeException('Cannot create isolated test keyring.'); }
    try {
        if (PHP_OS_FAMILY !== 'Windows') { chmod($testKeyring, 0600); }
        fwrite($testHandle, json_encode(['format' => 1, 'active' => 'test-v1',
            'versions' => ['test-v1' => base64_encode(str_repeat('D', 32))],
            'fingerprint' => base64_encode(str_repeat('T', 32))], JSON_THROW_ON_ERROR));
    } finally { fclose($testHandle); }
}
putenv('SHOP_KEYRING_FILE='.$testKeyring);
$_ENV['SHOP_KEYRING_FILE'] = $_SERVER['SHOP_KEYRING_FILE'] = $testKeyring;
