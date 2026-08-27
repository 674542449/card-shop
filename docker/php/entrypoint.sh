#!/bin/bash
set -e

cd /var/www/html

mkdir -p bootstrap/cache \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
fi

if [ -f artisan ]; then
    php artisan config:cache --no-interaction 2>/dev/null || true
    php artisan route:cache --no-interaction 2>/dev/null || true
    php artisan view:cache --no-interaction 2>/dev/null || true
    php artisan migrate --force --no-interaction 2>/dev/null || true
fi

if [ -d admin-frontend ] && [ ! -f public/admin-assets/index.html ]; then
    echo "Building admin frontend in background..."
    (
        cd admin-frontend
        NODE_OPTIONS="--max-old-space-size=512" npm install --no-audit --no-fund 2>&1 | tail -1
        NODE_OPTIONS="--max-old-space-size=512" npm run build 2>&1 | tail -1
        echo "Admin frontend built successfully."
    ) &
fi

exec docker-php-entrypoint "$@"
