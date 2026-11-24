#!/bin/sh
set -eu
cd /var/www/html
# Runtime performs no downloads, migrations, dependency installation or source mutation.
mkdir -p storage/app/public/uploads storage/app/private storage/framework/cache/data \
    storage/framework/cache/purifier storage/framework/sessions storage/framework/views storage/logs
# Ensure nginx (non-www-data worker) can traverse to public uploads while private stays restricted.
chmod 755 storage storage/app storage/app/public storage/app/public/uploads 2>/dev/null || true
chmod -R a+rX storage/app/public 2>/dev/null || true
chmod 750 storage/app/private 2>/dev/null || true
touch storage/framework/shop-write.lock
case "$1 ${2:-} ${3:-}" in
    "php artisan secrets:init") ;;
    *)
        # Check only the independent keyring, so first migrations do not need a schema yet.
        php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); try { $app->make(App\Security\SecretCipher::class)->metadata(); } catch (Throwable $e) { fwrite(STDERR, "Independent keyring unavailable; startup refused.\n"); exit(1); }'
        ;;
esac
exec "$@"
