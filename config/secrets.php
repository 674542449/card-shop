<?php

return [
    // This environment value is a path only. Key material must remain outside the
    // checkout, upload directory, .env and the shop backup archive.
    'keyring_file' => env('SHOP_KEYRING_FILE') ?: (PHP_OS_FAMILY === 'Windows'
        ? rtrim((string) getenv('USERPROFILE'), '/\\').'/.cardshop-secrets/shop-keyring.json'
        : '/run/secrets/shop-keyring.json'),
];
