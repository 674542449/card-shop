#!/usr/bin/env bash
# Lightweight multi-worker supervisor for the consolidated CardShop `worker` container.
# Keeps all five logical background workers (scheduler, notifications, seo, reconciliation,
# backups) as independent OS subprocesses inside one non-root read-only container:
#   - Slow backup archives never block customer delivery emails or order expiration.
#   - Crashed subprocesses are reaped immediately (wait -n) and restarted after a short backoff.
#   - SIGTERM/SIGINT forwards gracefully to all children so in-flight jobs finish before exit.
set -uo pipefail

PID_DIR="${CARDSHOP_WORKER_PID_DIR:-/tmp/cardshop-workers}"
RESTART_DELAY="${CARDSHOP_WORKER_RESTART_DELAY:-3}"
WORKER_NAMES=(scheduler notifications seo reconciliation backups)

is_worker_alive() {
    local pid="$1"
    [ -n "$pid" ] || return 1
    kill -0 "$pid" 2>/dev/null || return 1
    if [ -r "/proc/$pid/stat" ]; then
        local state
        state="$(sed 's/.*) //' "/proc/$pid/stat" 2>/dev/null | awk '{print $1}')"
        [ "$state" = "Z" ] && return 1
    fi
    return 0
}

if [ "${1:-}" = "--healthcheck" ]; then
    for name in "${WORKER_NAMES[@]}"; do
        pid="$(cat "$PID_DIR/$name.pid" 2>/dev/null || true)"
        is_worker_alive "$pid" || exit 1
    done
    exit 0
fi

WORKDIR="${CARDSHOP_WORKDIR:-/var/www/html}"
cd "$WORKDIR"

mkdir -p "$PID_DIR"
rm -f "$PID_DIR"/*.pid

STOPPING=0
SLEEP_PID=""

declare -A WORKER_PIDS=()
declare -A WORKER_CMDS=(
    [scheduler]="${CARDSHOP_CMD_SCHEDULER:-php artisan schedule:work}"
    [notifications]="${CARDSHOP_CMD_NOTIFICATIONS:-php artisan notifications:send --work}"
    [seo]="${CARDSHOP_CMD_SEO:-php artisan seo:work}"
    [reconciliation]="${CARDSHOP_CMD_RECONCILIATION:-php artisan payments:work}"
    [backups]="${CARDSHOP_CMD_BACKUPS:-php artisan shop:backup-work}"
)

log() {
    printf '[worker] %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2
}

start_worker() {
    local name="$1"
    local cmd="${WORKER_CMDS[$name]}"
    # shellcheck disable=SC2086
    $cmd &
    local pid=$!
    WORKER_PIDS[$name]="$pid"
    printf '%s\n' "$pid" > "$PID_DIR/$name.pid"
    log "started $name (pid=$pid)"
}

stop_all() {
    if [ "$STOPPING" -eq 1 ]; then
        return
    fi
    STOPPING=1
    log "shutdown signal received; stopping background workers..."
    if [ -n "$SLEEP_PID" ] && kill -0 "$SLEEP_PID" 2>/dev/null; then
        kill -TERM "$SLEEP_PID" 2>/dev/null || true
    fi
    local name pid
    for name in "${WORKER_NAMES[@]}"; do
        pid="${WORKER_PIDS[$name]:-}"
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill -TERM "$pid" 2>/dev/null || true
        fi
    done
}

trap stop_all TERM INT QUIT

for name in "${WORKER_NAMES[@]}"; do
    start_worker "$name"
done

while [ "$STOPPING" -eq 0 ]; do
    wait -n 2>/dev/null
    WAIT_RC=$?
    if [ "$STOPPING" -eq 1 ]; then
        break
    fi

    RESTART_LIST=()
    for name in "${WORKER_NAMES[@]}"; do
        pid="${WORKER_PIDS[$name]:-}"
        if ! is_worker_alive "$pid"; then
            if [ -n "$pid" ]; then
                wait "$pid" 2>/dev/null || true
            fi
            rm -f "$PID_DIR/$name.pid"
            log "$name (pid=${pid:-none}) exited (status=$WAIT_RC); restarting in ${RESTART_DELAY}s"
            RESTART_LIST+=("$name")
        fi
    done

    if [ "${#RESTART_LIST[@]}" -gt 0 ] && [ "$STOPPING" -eq 0 ]; then
        sleep "$RESTART_DELAY" &
        SLEEP_PID=$!
        wait "$SLEEP_PID" 2>/dev/null || true
        SLEEP_PID=""
        for name in "${RESTART_LIST[@]}"; do
            [ "$STOPPING" -eq 0 ] || break
            start_worker "$name"
        done
    fi
done

for name in "${WORKER_NAMES[@]}"; do
    pid="${WORKER_PIDS[$name]:-}"
    if [ -n "$pid" ]; then
        wait "$pid" 2>/dev/null || true
    fi
done
rm -rf "$PID_DIR"
log "all background workers stopped cleanly."
exit 0
