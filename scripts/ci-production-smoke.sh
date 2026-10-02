#!/usr/bin/env bash
# First-install/restart acceptance for isolated GitHub runner data only.
# Never run against developer or production databases, keys, configuration or volumes.
set -euo pipefail
if [ "${GITHUB_ACTIONS:-}" != true ] || [ "${CI:-}" != true ]; then
    echo "This destructive fixture cleanup script runs only in GitHub Actions." >&2
    exit 2
fi
: "${SHOP_APP_IMAGE:?The already-loaded application image is required}"
: "${SHOP_WEB_IMAGE:?The already-loaded Nginx image is required}"
docker image inspect "$SHOP_APP_IMAGE" >/dev/null
docker image inspect "$SHOP_WEB_IMAGE" >/dev/null
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SMOKE_TEMP_ROOT="$(realpath "${RUNNER_TEMP:-/tmp}")"
SMOKE_DIRECTORY="$(mktemp -d "$SMOKE_TEMP_ROOT/cardshop-smoke.XXXXXX")"
case "$SMOKE_DIRECTORY" in "$REPO_ROOT"/*) echo "Fixture must stay outside the repository." >&2; exit 2 ;; esac
SMOKE_PROJECT="cardshop-ci-${GITHUB_RUN_ID:-0}-${GITHUB_RUN_ATTEMPT:-1}-${RANDOM}"
[[ "$SMOKE_PROJECT" =~ ^cardshop-ci-[0-9]+-[0-9]+-[0-9]+$ ]] || exit 2
SMOKE_APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
SMOKE_DB_PASSWORD="$(openssl rand -hex 32)"
SMOKE_ADMIN_PASSWORD="$(openssl rand -hex 32)"
SMOKE_PROBE_TOKEN="$(openssl rand -hex 32)"
for SMOKE_MASK in "$SMOKE_APP_KEY" "$SMOKE_DB_PASSWORD" "$SMOKE_ADMIN_PASSWORD" "$SMOKE_PROBE_TOKEN"; do
    echo "::add-mask::$SMOKE_MASK"
done
export SHOP_ENV_FILE="$SMOKE_DIRECTORY/runtime.env"
export APP_KEY="$SMOKE_APP_KEY" DB_PASSWORD="$SMOKE_DB_PASSWORD"
export SHOP_SECRETS_DIR="$SMOKE_DIRECTORY/keys"
export BACKUP_SYNC_MOUNT="$SMOKE_DIRECTORY/backup-sync"
export TLS_CONFIG_DIR="$SMOKE_DIRECTORY/tls-config" TLS_CERT_DIR="$SMOKE_DIRECTORY/tls-certs"
mkdir -p "$SHOP_SECRETS_DIR" "$BACKUP_SYNC_MOUNT" "$TLS_CONFIG_DIR" "$TLS_CERT_DIR"
sudo chown 33:33 "$SHOP_SECRETS_DIR" "$BACKUP_SYNC_MOUNT"
sudo chmod 750 "$SHOP_SECRETS_DIR" "$BACKUP_SYNC_MOUNT"
umask 077
cat > "$SHOP_ENV_FILE" <<ENV
APP_ENV=production
APP_DEBUG=false
APP_KEY=$SMOKE_APP_KEY
APP_URL=http://127.0.0.1
DB_CONNECTION=pgsql
DB_DATABASE=cardshop_ci_smoke
DB_USERNAME=cardshop_ci_smoke
DB_PASSWORD=$SMOKE_DB_PASSWORD
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=cardshop_ci_smoke_
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=false
MAIL_MAILER=array
MAIL_HOST=
ADMIN_USERNAME=ci_fixture_owner
ADMIN_PASSWORD=$SMOKE_ADMIN_PASSWORD
SHOP_PROBE_TOKEN=$SMOKE_PROBE_TOKEN
SHOP_APP_IMAGE=$SHOP_APP_IMAGE
SHOP_WEB_IMAGE=$SHOP_WEB_IMAGE
SHOP_ENV_FILE=$SHOP_ENV_FILE
SHOP_SECRETS_DIR=$SHOP_SECRETS_DIR
BACKUP_SYNC_MOUNT=$BACKUP_SYNC_MOUNT
TLS_CONFIG_DIR=$TLS_CONFIG_DIR
TLS_CERT_DIR=$TLS_CERT_DIR
ENV
# Compose's runner user owns the file; the container's UID/GID 33 can read its mount.
sudo chgrp 33 "$SHOP_ENV_FILE"
chmod 640 "$SHOP_ENV_FILE"
DC=(docker compose -p "$SMOKE_PROJECT" --env-file "$SHOP_ENV_FILE" -f "$REPO_ROOT/docker-compose.production.yml")
cleanup() {
    SMOKE_STATUS=$?
    trap - EXIT
    if [ "$SMOKE_STATUS" -ne 0 ]; then
        echo "Production smoke failed; showing container states and bounded logs."
        "${DC[@]}" ps -a || true
        "${DC[@]}" logs --no-color --tail=100 app nginx scheduler notifications backups seo reconciliation postgres redis || true
    fi
    # Only this generated CI project and this generated external fixture may be removed.
    "${DC[@]}" down --volumes --remove-orphans --timeout 15 >/dev/null 2>&1 || true
    case "$SMOKE_DIRECTORY" in "$SMOKE_TEMP_ROOT"/cardshop-smoke.*)
        [ "$(realpath "$SMOKE_DIRECTORY")" = "$SMOKE_DIRECTORY" ] && sudo rm -rf -- "$SMOKE_DIRECTORY"
        ;;
    esac
    exit "$SMOKE_STATUS"
}
trap cleanup EXIT

echo "Starting an isolated production first-install fixture."
"${DC[@]}" config --quiet
"${DC[@]}" up -d --wait --wait-timeout 180 postgres redis
"${DC[@]}" run --rm --no-deps initialize
"${DC[@]}" run --rm --no-deps migrate
"${DC[@]}" up -d --pull never --wait --wait-timeout 180 app nginx scheduler notifications backups seo reconciliation

await_readiness() {
    for SMOKE_ATTEMPT in $(seq 1 45); do
        if "${DC[@]}" exec -T app php artisan shop:ready > "$SMOKE_DIRECTORY/readiness.json" 2>/dev/null; then
            return 0
        fi
        sleep 2
    done
    echo "Readiness never passed." >&2
    return 1
}
await_heartbeats() {
    # schedule:work may wait for the next minute boundary before its first heartbeat.
    for SMOKE_ATTEMPT in $(seq 1 75); do
        if "${DC[@]}" exec -T app php artisan shop:health > "$SMOKE_DIRECTORY/health.json" 2>/dev/null \
            && [ "$(sql "SELECT count(*) FROM service_heartbeats WHERE name IN ('scheduler', 'notifications', 'seo', 'reconciliation', 'backups') AND last_seen_at >= '$SMOKE_HEARTBEAT_SINCE'::timestamp" 2>/dev/null)" = 5 ]; then
            return 0
        fi
        sleep 2
    done
    echo "Business worker heartbeats never became healthy." >&2
    return 1
}
http_acceptance() {
    curl --fail --silent --show-error --max-time 20 --dump-header "$SMOKE_DIRECTORY/home.headers" \
        --output "$SMOKE_DIRECTORY/home.html" http://127.0.0.1/
    grep -q 'csrf-token' "$SMOKE_DIRECTORY/home.html"
    grep -iq '^X-Request-ID:' "$SMOKE_DIRECTORY/home.headers"
    curl --fail --silent --show-error --max-time 20 --header "X-Shop-Probe-Token: $SMOKE_PROBE_TOKEN" \
        --output "$SMOKE_DIRECTORY/probe.json" http://127.0.0.1/health/ready
    grep -Eq '"ready"[[:space:]]*:[[:space:]]*true' "$SMOKE_DIRECTORY/probe.json"
    curl --fail --silent --show-error --max-time 20 --output "$SMOKE_DIRECTORY/admin.html" http://127.0.0.1/admin/login
    SMOKE_ADMIN_ASSET="$(grep -oE '/admin-assets/assets/[^"[:space:]<>]+\.js' "$SMOKE_DIRECTORY/admin.html" | sed -n '1p')"
    [ -n "$SMOKE_ADMIN_ASSET" ]
    curl --fail --silent --show-error --max-time 20 --output "$SMOKE_DIRECTORY/admin.js" "http://127.0.0.1$SMOKE_ADMIN_ASSET"
    [ -s "$SMOKE_DIRECTORY/admin.js" ]
    "${DC[@]}" exec -T app php scripts/validate-admin-assets.php
}
sql() {
    "${DC[@]}" exec -T postgres psql -U cardshop_ci_smoke -d cardshop_ci_smoke -tA -v ON_ERROR_STOP=1 -c "$1"
}
dependency_snapshot() {
    "${DC[@]}" exec -T app php -r 'foreach (["composer.lock", "vendor/autoload.php", "public/admin-assets/.build-stamp"] as $f) { echo hash_file("sha256", $f), "\n"; }'
}
application_clock() {
    # Heartbeats use the application's timezone in a timestamp-without-timezone column.
    "${DC[@]}" exec -T app php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo now()->format("Y-m-d H:i:s");'
}
await_readiness
SMOKE_HEARTBEAT_SINCE="$(application_clock)"
await_heartbeats
http_acceptance
for SMOKE_SERVICE in app nginx postgres redis scheduler notifications backups seo reconciliation; do
    SMOKE_CID="$("${DC[@]}" ps -q "$SMOKE_SERVICE")"
    [ -n "$SMOKE_CID" ] && [ "$(docker inspect -f '{{.State.Running}}' "$SMOKE_CID")" = true ]
done
SMOKE_APP_CID="$("${DC[@]}" ps -q app)"
[ "$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$SMOKE_APP_CID")" = true ]
[ "$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/run/secrets"}}{{.RW}}{{end}}{{end}}' "$SMOKE_APP_CID")" = false ]
[ "$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/run/config/runtime.env"}}{{.RW}}{{end}}{{end}}' "$SMOKE_APP_CID")" = false ]
[ "$("${DC[@]}" exec -T app id -u | tr -d '\r\n')" = 33 ]
[ "$("${DC[@]}" exec -T app stat -c '%u:%g:%a' /run/secrets/shop-keyring.json | tr -d '\r\n')" = 33:33:600 ]
SMOKE_MIGRATIONS_BEFORE="$(sql "SELECT count(*)::text || ':' || md5(string_agg(migration || ':' || batch::text, ',' ORDER BY migration)) FROM migrations")"
[ "$(sql 'SELECT count(*) FROM admins')" = 1 ]
[ "$(sql 'SELECT count(*) FROM orders')" = 0 ]
[ "$(sql 'SELECT count(*) FROM cards')" = 0 ]
SMOKE_DEPENDENCIES_BEFORE="$(dependency_snapshot)"
"${DC[@]}" logs --no-color postgres > "$SMOKE_DIRECTORY/postgres-before.log"
SMOKE_INITDB_BEFORE="$(grep -c 'initdb' "$SMOKE_DIRECTORY/postgres-before.log" || true)"
SMOKE_RESTART_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SMOKE_HEARTBEAT_SINCE="$(application_clock)"
echo "Restarting all nine fixture services without initialization or migrations."
"${DC[@]}" restart app nginx postgres redis scheduler notifications backups seo reconciliation
await_readiness
await_heartbeats
http_acceptance
[ "$SMOKE_MIGRATIONS_BEFORE" = "$(sql "SELECT count(*)::text || ':' || md5(string_agg(migration || ':' || batch::text, ',' ORDER BY migration)) FROM migrations")" ]
[ "$SMOKE_DEPENDENCIES_BEFORE" = "$(dependency_snapshot)" ]
[ "$(sql 'SELECT count(*) FROM admins')" = 1 ]
"${DC[@]}" logs --no-color postgres > "$SMOKE_DIRECTORY/postgres-after.log"
[ "$SMOKE_INITDB_BEFORE" = "$(grep -c 'initdb' "$SMOKE_DIRECTORY/postgres-after.log" || true)" ]
"${DC[@]}" logs --no-color --since "$SMOKE_RESTART_TIME" app > "$SMOKE_DIRECTORY/app-restart.log"
if grep -Eiq 'Composer dependencies|composer install|npm install|Nothing to migrate|Migrations OK|Migrating:|Downloading' "$SMOKE_DIRECTORY/app-restart.log"; then
    echo "A runtime restart attempted installation or migrations." >&2
    exit 1
fi
echo "Production smoke passed: first install, nine services, HTTP/CSRF/admin assets, protected readiness, heartbeats and restart invariants."
