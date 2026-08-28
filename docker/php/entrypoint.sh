#!/bin/bash
set -e

cd /var/www/html

echo "==> [1/7] Preparing writable directories"
mkdir -p bootstrap/cache \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

# bootstrap/cache and storage live on the host bind mount and survive rebuilds.
# Stale compiled config/routes/views from an older code version are a common cause
# of 500 errors after a deploy, so wipe them on every boot.
rm -f bootstrap/cache/config.php \
      bootstrap/cache/routes-*.php \
      bootstrap/cache/events.php \
      bootstrap/cache/services.php \
      bootstrap/cache/packages.php
rm -rf storage/framework/views/*

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "==> [2/7] Composer dependencies"
if [ ! -f vendor/autoload.php ]; then
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
else
    echo "    vendor/ present, skipping."
fi

echo "==> [3/7] Environment file"
if [ ! -f .env ]; then
    echo "    .env missing, creating from .env.example"
    cp .env.example .env
fi

# Match APP_KEY= with nothing (or only whitespace/CR) after it.
if grep -Eq '^APP_KEY=[[:space:]]*$' .env; then
    echo "    Generating APP_KEY"
    php artisan key:generate --force --no-interaction
else
    echo "    APP_KEY present."
fi

echo "==> [4/7] Waiting for PostgreSQL"
for i in $(seq 1 60); do
    if php -r '
        $h = getenv("DB_HOST") ?: "postgres";
        $p = getenv("DB_PORT") ?: "5432";
        $c = @fsockopen($h, (int) $p, $e, $s, 2);
        exit($c ? 0 : 1);
    '; then
        echo "    PostgreSQL reachable after ${i}s."
        break
    fi
    if [ "$i" = "60" ]; then
        echo "    !! PostgreSQL unreachable after 60s. Continuing, but the site will error."
    fi
    sleep 1
done

echo "==> [5/7] Migrations and seed"
# Deliberately NOT silenced: a failed migration means the settings table is missing,
# which turns every page into a 500. The operator needs to see it in `docker logs`.
if php artisan migrate --force --no-interaction; then
    echo "    Migrations OK."
    # DatabaseSeeder is idempotent (firstOrCreate), so it is safe on every boot:
    # it creates the default admin and settings rows only when they are absent and
    # never overwrites values changed from the admin UI.
    if php artisan db:seed --force --no-interaction; then
        echo "    Seed OK."
    else
        echo "    !! Seeding failed. The admin account may not exist."
    fi
else
    echo "    !! MIGRATIONS FAILED. The site will return 500 until this is fixed."
fi

echo "==> [6/7] Precompiling Blade views"
# This is not just a warm cache, it closes a race that took the site down.
#
# Blade writes each compiled view with file_put_contents() and then immediately
# includes it. When several PHP-FPM workers get their first request for the same
# uncompiled view at once, one worker can include the file while another is still
# writing it. The truncated include is a parse error ("unexpected end of file"),
# and OPcache then caches that broken compilation. Because the file's mtime never
# changes afterwards, OPcache never revalidates it, so every later request keeps
# getting the broken copy — `view:clear` and container rebuilds do not help.
#
# Compiling every view here, in one process, before FPM accepts any traffic, means
# workers only ever include files that are already complete on disk.
if php artisan view:cache --no-interaction; then
    echo "    Views precompiled."
else
    echo "    !! View precompilation failed — see the error above."
fi

# Syntax-check the generated PHP. A Blade template can compile "successfully" into
# PHP that does not parse — a literal "@context" in JSON-LD, for example, is the real
# @context directive and expands to an unclosed if(). That only shows up as a 500 at
# request time, so check it here where it is visible in the startup log.
BROKEN=0
for VIEW in storage/framework/views/*.php; do
    [ -e "$VIEW" ] || continue
    if ! php -l "$VIEW" >/dev/null 2>&1; then
        BROKEN=$((BROKEN + 1))
        echo "    !! Compiled view does not parse: $VIEW"
        php -l "$VIEW" 2>&1 | head -2 | sed 's/^/       /'
        grep -o 'PATH .* ENDPATH' "$VIEW" | sed 's/^/       source: /'
    fi
done
if [ "$BROKEN" -eq 0 ]; then
    echo "    All compiled views parse cleanly."
else
    echo "    !! $BROKEN compiled view(s) are broken — those pages will return 500."
fi

echo "==> [7/7] Admin frontend assets"
# The built SPA is committed to the repository under public/admin-assets/, so a normal
# deploy needs no Node toolchain at all. We only fall back to building when the assets
# are genuinely missing, and we never hide a failure behind `|| true`.
if [ -f public/admin-assets/index.html ]; then
    echo "    Prebuilt admin assets found."
elif [ -d admin-frontend ] && command -v npm >/dev/null 2>&1; then
    echo "    Admin assets missing. Building in the background; watch 'docker logs -f cardshop-app'."
    (
        cd admin-frontend
        if NODE_OPTIONS="--max-old-space-size=1536" npm install --no-audit --no-fund \
           && NODE_OPTIONS="--max-old-space-size=1536" npm run build; then
            echo "==> Admin frontend build SUCCEEDED."
        else
            echo "==> !! Admin frontend build FAILED (most likely out of memory)."
            echo "==> !! Build it on a machine with more RAM and commit public/admin-assets/."
        fi
    ) &
else
    echo "    !! Admin assets missing and npm unavailable. /admin will show the fallback page."
fi

echo "==> Starting PHP-FPM"
exec docker-php-entrypoint "$@"
