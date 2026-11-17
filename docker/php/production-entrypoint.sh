#!/bin/sh
set -eu
cd /var/www/html
# Runtime performs no downloads, migrations, dependency installation or source mutation.
mkdir -p storage/app/public/uploads storage/app/private storage/framework/cache/data \
    storage/framework/cache/purifier storage/framework/sessions storage/framework/views storage/logs
touch storage/framework/shop-write.lock
case "$1 ${2:-} ${3:-}" in
    "php artisan secrets:init") ;;
    *)
        # Check only the independent keyring, so first migrations do not need a schema yet.
        php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); try { $app->make(App\Security\SecretCipher::class)->metadata(); } catch (Throwable $e) { fwrite(STDERR, "Independent keyring unavailable; startup refused.\n"); exit(1); }'
        ;;
esac
exec "$@"
