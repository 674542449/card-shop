#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d "${RUNNER_TEMP:-/tmp}/cardshop-worker-test.XXXXXX")"
PID_DIR="$TMP_DIR/pids"
SUPERVISOR_PID=""

cleanup() {
    if [ -n "$SUPERVISOR_PID" ] && kill -0 "$SUPERVISOR_PID" 2>/dev/null; then
        kill -TERM "$SUPERVISOR_PID" 2>/dev/null || true
        wait "$SUPERVISOR_PID" 2>/dev/null || true
    fi
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

CARDSHOP_WORKDIR="$REPO_ROOT" \
CARDSHOP_WORKER_PID_DIR="$PID_DIR" \
CARDSHOP_WORKER_RESTART_DELAY=1 \
CARDSHOP_CMD_SCHEDULER="sleep 60" \
CARDSHOP_CMD_NOTIFICATIONS="sleep 60" \
CARDSHOP_CMD_SEO="sleep 60" \
CARDSHOP_CMD_RECONCILIATION="sleep 60" \
CARDSHOP_CMD_BACKUPS="sleep 60" \
bash "$REPO_ROOT/docker/php/worker-supervisor.sh" >/dev/null 2>"$TMP_DIR/supervisor.log" &
SUPERVISOR_PID=$!

# 1. Wait until all 5 workers are running and healthcheck passes.
HEALTH_OK=0
for _ in $(seq 1 25); do
    if CARDSHOP_WORKER_PID_DIR="$PID_DIR" bash "$REPO_ROOT/docker/php/worker-supervisor.sh" --healthcheck; then
        HEALTH_OK=1
        break
    fi
    sleep 0.2
done
[ "$HEALTH_OK" -eq 1 ] || { echo "FAIL: initial healthcheck never passed"; cat "$TMP_DIR/supervisor.log"; exit 1; }
echo "PASS: all 5 supervised workers started and passed --healthcheck"

# 2. Kill one worker (seo) and verify automatic restart while notifications PID stays intact.
OLD_SEO_PID="$(cat "$PID_DIR/seo.pid")"
OLD_NOTIF_PID="$(cat "$PID_DIR/notifications.pid")"
kill -KILL "$OLD_SEO_PID" 2>/dev/null || true

RESPAWNED=0
for _ in $(seq 1 35); do
    NEW_SEO_PID="$(cat "$PID_DIR/seo.pid" 2>/dev/null || true)"
    if [ -n "$NEW_SEO_PID" ] && [ "$NEW_SEO_PID" != "$OLD_SEO_PID" ] \
        && CARDSHOP_WORKER_PID_DIR="$PID_DIR" bash "$REPO_ROOT/docker/php/worker-supervisor.sh" --healthcheck; then
        RESPAWNED=1
        break
    fi
    sleep 0.2
done
[ "$RESPAWNED" -eq 1 ] || { echo "FAIL: crashed seo worker was not respawned"; cat "$TMP_DIR/supervisor.log"; exit 1; }
[ "$(cat "$PID_DIR/notifications.pid")" = "$OLD_NOTIF_PID" ] || { echo "FAIL: sibling worker PID changed unexpectedly"; exit 1; }
echo "PASS: crashed worker auto-restarted without disrupting sibling workers"

# 3. Send SIGTERM to supervisor and verify graceful shutdown and PID dir cleanup.
kill -TERM "$SUPERVISOR_PID"
wait "$SUPERVISOR_PID"
EXIT_RC=$?
SUPERVISOR_PID=""
[ "$EXIT_RC" -eq 0 ] || { echo "FAIL: supervisor exited with $EXIT_RC on SIGTERM"; exit 1; }
[ ! -d "$PID_DIR" ] || { echo "FAIL: PID directory was not cleaned up on shutdown"; exit 1; }
echo "PASS: SIGTERM cleanly stopped all workers and cleaned up state"
