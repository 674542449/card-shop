#!/usr/bin/env bash
# Read-only diagnosis for released images. Never displays the environment file or Docker env.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME_ENV=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        --env-file) [ "$#" -ge 2 ] || exit 2; RUNTIME_ENV="$2"; shift ;;
        -h|--help) echo "Usage: $0 --env-file /etc/cardshop/runtime.env"; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
    shift
done
case "$RUNTIME_ENV" in /*) ;; *) echo "An external absolute --env-file is required." >&2; exit 2 ;; esac
[ -f "$RUNTIME_ENV" ] || { echo "Runtime environment file is missing." >&2; exit 2; }
RESOLVED_ENV="$(realpath "$RUNTIME_ENV")"
case "$RESOLVED_ENV" in "$REPO_ROOT"/*) echo "Keep the production environment file outside the source tree." >&2; exit 2 ;; esac
export SHOP_ENV_FILE="$RESOLVED_ENV"
DC=(docker compose --env-file "$RESOLVED_ENV" -f "$REPO_ROOT/docker-compose.production.yml")
"${DC[@]}" config --quiet
FAIL=0
for SERVICE in app nginx postgres redis scheduler notifications backups seo reconciliation; do
    CID="$("${DC[@]}" ps -q "$SERVICE")"
    if [ -z "$CID" ] || [ "$(docker inspect -f '{{.State.Running}}' "$CID")" != true ]; then
        echo "FAIL: $SERVICE is not running."
        FAIL=1
    else
        echo "OK: $SERVICE running."
    fi
done
APP_CID="$("${DC[@]}" ps -q app)"
if [ -n "$APP_CID" ]; then
    if [ "$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$APP_CID")" != true ]; then
        echo "FAIL: application root filesystem is writable."
        FAIL=1
    fi
    if [ "$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/run/secrets"}}{{.RW}}{{end}}{{end}}' "$APP_CID")" != false ]; then
        echo "FAIL: independent keyring is not mounted read-only."
        FAIL=1
    fi
fi
"${DC[@]}" exec -T app php artisan shop:ready || FAIL=1
"${DC[@]}" exec -T app php artisan shop:health || FAIL=1
"${DC[@]}" exec -T nginx nginx -t || FAIL=1
exit "$FAIL"
