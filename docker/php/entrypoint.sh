#!/bin/bash
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
fi

mkdir -p bootstrap/cache \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Build admin frontend if not built yet
if [ -d admin-frontend ] && [ ! -f public/admin-assets/index.html ]; then
    echo "Building admin frontend..."
    cd admin-frontend
    npm install --no-audit --no-fund
    npm run build
    cd /var/www/html
    echo "Admin frontend built successfully."
fi

exec docker-php-entrypoint "$@"
