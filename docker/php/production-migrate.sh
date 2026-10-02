#!/bin/sh
set -eu
cd /var/www/html
# Explicit one-shot operation. Normal container restarts never run this script.
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
