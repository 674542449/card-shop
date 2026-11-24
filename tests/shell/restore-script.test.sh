#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$ROOT/scripts/restore.sh"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

# 1. --help prints usage and exits 0.
"$SCRIPT" --help | grep -q "一键迁移与全量恢复脚本"

# 2. Missing archive argument fails cleanly with exit code 2.
if "$SCRIPT" >/dev/null 2>&1; then
    echo "FAIL: expected missing archive to fail" >&2
    exit 1
fi

# 3. Create a minimal valid backup archive & keyring + stub docker binary.
STAGE="$TMPDIR/stage"
mkdir -p "$STAGE"
printf '{"version":1,"files":{"database.dump":{}}}\n' > "$STAGE/manifest.json"
printf 'PGDMP-STUB\n' > "$STAGE/database.dump"
printf 'APP_URL=https://shop.example.test\nDB_DATABASE=cardshop_test_restore\nSHOP_SECRETS_DIR=%s\n' "$TMPDIR/secrets" > "$STAGE/config.env"
ARCHIVE="$TMPDIR/shop-20261125-030000-test.tar.gz"
tar -czf "$ARCHIVE" -C "$STAGE" manifest.json database.dump config.env

KEYRING="$TMPDIR/src-keyring.json"
printf '{"keyring_id":"k-test","current_version":"v1","keys":{"v1":"AAAA"}}\n' > "$KEYRING"

LOG_FILE="$TMPDIR/docker-calls.log"
STUB_BIN="$TMPDIR/stub-docker"
cat > "$STUB_BIN" <<EOF
#!/usr/bin/env bash
echo "\$*" >> "$LOG_FILE"
if [ "\${1:-}" = "compose" ] && [ "\${2:-}" = "version" ]; then
    echo "Docker Compose version v2.29.0"
    exit 0
fi
if [ "\${1:-}" = "compose" ]; then
    for arg in "\$@"; do
        if [ "\$arg" = "ps" ]; then
            echo "stub-app-container-id"
            exit 0
        fi
        if [ "\$arg" = "shop:admin-path" ]; then
            echo "/random-admin-path"
            exit 0
        fi
    done
    exit 0
fi
exit 0
EOF
chmod +x "$STUB_BIN"

# 4. --dry-run validates archive + keyring without touching env/secrets or calling docker mutating commands.
CARDSHOP_DOCKER_BIN="$STUB_BIN" "$SCRIPT" "$ARCHIVE" \
    --keyring "$KEYRING" \
    --env-file "$TMPDIR/etc/runtime.env" \
    --secrets-dir "$TMPDIR/secrets" \
    --dry-run | grep -q "前置条件检查全部通过"

test ! -f "$TMPDIR/etc/runtime.env"
test ! -f "$TMPDIR/secrets/shop-keyring.json"

# 5. Full --yes run installs keyring + extracts config.env + orchestrates down -> cp -> verify -> restore -> up.
CARDSHOP_DOCKER_BIN="$STUB_BIN" "$SCRIPT" "$ARCHIVE" \
    --keyring "$KEYRING" \
    --env-file "$TMPDIR/etc/runtime.env" \
    --secrets-dir "$TMPDIR/secrets" \
    --yes | grep -q "全量恢复完成"

test -f "$TMPDIR/etc/runtime.env"
test -f "$TMPDIR/secrets/shop-keyring.json"
grep -q "stop worker nginx" "$LOG_FILE"
grep -q "artisan down --retry=15" "$LOG_FILE"
grep -q "stub-app-container-id:/var/www/html/storage/app/private/shop-backups/shop-20261125-030000-test.tar.gz" "$LOG_FILE"
grep -q "shop:restore storage/app/private/shop-backups/shop-20261125-030000-test.tar.gz --verify-only" "$LOG_FILE"
grep -q "shop:restore storage/app/private/shop-backups/shop-20261125-030000-test.tar.gz --database=cardshop_test_restore --confirm=restore:cardshop_test_restore" "$LOG_FILE"
grep -q "artisan up" "$LOG_FILE"

echo "PASS: all 5 restore.sh regression scenarios passed"
