#!/usr/bin/env bash
# Exercise the real Docker startup dependency block without Docker, PHP, or a
# network connection. Fixtures stay in ignored .local/ for failure diagnosis.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[ -d "$REPO_ROOT/.local" ] || mkdir "$REPO_ROOT/.local"
FIXTURE_ROOT="$(mktemp -d "$REPO_ROOT/.local/composer-install-regression.XXXXXX")"
SEGMENT="$FIXTURE_ROOT/composer-startup.sh"
mkdir "$FIXTURE_ROOT/bin"

# Keep production logic in one place: extract the complete Composer stage, with
# the same errexit setting as entrypoint.sh, and exclude all later startup work.
printf '#!/usr/bin/env bash\nset -e\n' > "$SEGMENT"
awk '
    /echo "==> \[2\/8\] Composer dependencies"/ { copying = 1 }
    /echo "==> \[3\/8\] Environment file"/ { copying = 0 }
    copying { print }
' "$REPO_ROOT/docker/php/entrypoint.sh" >> "$SEGMENT"
if ! grep -q 'COMPOSER_STAMP=' "$SEGMENT"; then
    printf 'FAIL: unable to extract the real Composer startup stage\n' >&2
    exit 1
fi

cat > "$FIXTURE_ROOT/bin/composer" <<'COMPOSER'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "$COMPOSER_TEST_CALLS"
if [ "${1:-}" != install ]; then
    printf 'Unexpected Composer operation: %s\n' "${1:-}" >&2
    exit 90
fi
if [ "${COMPOSER_TEST_FAIL:-0}" = 1 ]; then
    printf 'Simulated locked-dependency installation failure\n' >&2
    exit 42
fi
mkdir -p vendor
printf '<?php // isolated test autoloader\n' > vendor/autoload.php
COMPOSER
chmod +x "$FIXTURE_ROOT/bin/composer"

fail() {
    printf 'FAIL: %s\nFixtures: %s\n' "$1" "$FIXTURE_ROOT" >&2
    exit 1
}

new_fixture() {
    CASE_DIR="$FIXTURE_ROOT/$1"
    mkdir "$CASE_DIR"
    mkdir "$CASE_DIR/storage"
    printf '{"require":{"test/package":"^1.0"}}\n' > "$CASE_DIR/composer.json"
    if [ "${2:-with-lock}" = with-lock ]; then
        printf '{"packages":[{"name":"test/package","version":"1.0.0"}]}\n' > "$CASE_DIR/composer.lock"
    fi
}

run_segment() {
    (
        cd "$CASE_DIR"
        export PATH="$FIXTURE_ROOT/bin:$PATH"
        export COMPOSER_TEST_CALLS="$CASE_DIR/calls.log"
        export COMPOSER_TEST_FAIL="${1:-0}"
        bash "$SEGMENT" > "$CASE_DIR/run.log" 2>&1
    )
}

assert_installs() {
    local expected="$1" count=0
    if [ -f "$CASE_DIR/calls.log" ]; then
        count="$(awk 'END { print NR }' "$CASE_DIR/calls.log")"
        if grep -vFx 'install --no-dev --optimize-autoloader --no-interaction --no-progress' "$CASE_DIR/calls.log" >/dev/null; then
            fail 'startup used unexpected arguments or an operation other than locked install'
        fi
    fi
    [ "$count" = "$expected" ] || fail "expected $expected Composer installs, observed $count"
}

assert_failure() {
    if run_segment "${1:-0}"; then
        fail 'startup succeeded when dependency installation should have stopped it'
    fi
}

new_fixture first-install
run_segment || fail 'first installation failed'
assert_installs 1
[ -f "$CASE_DIR/vendor/autoload.php" ] || fail 'first installation did not produce vendor files'
[ -s "$CASE_DIR/storage/.composer-installed" ] || fail 'successful installation did not record a fingerprint'
printf 'PASS: first installation records its fingerprint\n'

cp "$CASE_DIR/storage/.composer-installed" "$CASE_DIR/stamp-before"
run_segment || fail 'unchanged restart failed'
assert_installs 1
cmp -s "$CASE_DIR/stamp-before" "$CASE_DIR/storage/.composer-installed" || fail 'unchanged restart rewrote its fingerprint'
printf 'PASS: unchanged dependencies skip installation\n'

new_fixture lock-only-upgrade
run_segment || fail 'lock-only fixture initial installation failed'
cp "$CASE_DIR/storage/.composer-installed" "$CASE_DIR/stamp-before"
cp "$CASE_DIR/composer.json" "$CASE_DIR/json-before"
printf '{"packages":[{"name":"test/package","version":"1.0.1"}]}\n' > "$CASE_DIR/composer.lock"
run_segment || fail 'lock-only upgrade failed'
assert_installs 2
cmp -s "$CASE_DIR/json-before" "$CASE_DIR/composer.json" || fail 'lock-only fixture unexpectedly changed composer.json'
if cmp -s "$CASE_DIR/stamp-before" "$CASE_DIR/storage/.composer-installed"; then
    fail 'lock-only upgrade retained the old fingerprint'
fi
run_segment || fail 'restart after lock-only upgrade failed'
assert_installs 2
printf 'PASS: a lock-only upgrade reinstalls once\n'

new_fixture json-only-upgrade
run_segment || fail 'json-only fixture initial installation failed'
cp "$CASE_DIR/storage/.composer-installed" "$CASE_DIR/stamp-before"
cp "$CASE_DIR/composer.lock" "$CASE_DIR/lock-before"
printf '{"require":{"test/package":"^1.0"},"config":{"sort-packages":true}}\n' > "$CASE_DIR/composer.json"
run_segment || fail 'json-only upgrade failed'
assert_installs 2
cmp -s "$CASE_DIR/lock-before" "$CASE_DIR/composer.lock" || fail 'json-only fixture unexpectedly changed composer.lock'
if cmp -s "$CASE_DIR/stamp-before" "$CASE_DIR/storage/.composer-installed"; then
    fail 'json-only upgrade retained the old fingerprint'
fi
run_segment || fail 'restart after json-only upgrade failed'
assert_installs 2
printf 'PASS: a json-only change reinstalls once\n'

new_fixture legacy-fingerprint
mkdir "$CASE_DIR/vendor"
printf '<?php // legacy vendor fixture\n' > "$CASE_DIR/vendor/autoload.php"
(cd "$CASE_DIR" && md5sum composer.json | awk '{ print $1 }') > "$CASE_DIR/storage/.composer-installed"
cp "$CASE_DIR/storage/.composer-installed" "$CASE_DIR/stamp-before"
run_segment || fail 'legacy fingerprint migration failed'
assert_installs 1
if cmp -s "$CASE_DIR/stamp-before" "$CASE_DIR/storage/.composer-installed"; then
    fail 'legacy single-file fingerprint was not replaced'
fi
run_segment || fail 'restart after legacy fingerprint migration failed'
assert_installs 1
printf 'PASS: the legacy fingerprint refreshes once\n'

new_fixture failed-install-existing-stamp
printf 'previous-successful-fingerprint\n' > "$CASE_DIR/storage/.composer-installed"
cp "$CASE_DIR/storage/.composer-installed" "$CASE_DIR/stamp-before"
cp "$CASE_DIR/composer.lock" "$CASE_DIR/lock-before"
assert_failure 1
assert_installs 1
cmp -s "$CASE_DIR/stamp-before" "$CASE_DIR/storage/.composer-installed" || fail 'failed installation replaced the previous fingerprint'
cmp -s "$CASE_DIR/lock-before" "$CASE_DIR/composer.lock" || fail 'failed installation changed the lock file'
new_fixture failed-first-install
assert_failure 1
assert_installs 1
[ ! -e "$CASE_DIR/storage/.composer-installed" ] || fail 'failed first installation recorded a successful fingerprint'
printf 'PASS: installation failure stops startup without updating or stamping\n'

new_fixture missing-lock without-lock
assert_failure
assert_installs 0
[ ! -e "$CASE_DIR/storage/.composer-installed" ] || fail 'missing lock recorded an installation fingerprint'
printf 'PASS: a missing lock stops before Composer runs\n'

printf 'All 7 Composer startup regression scenarios passed.\nFixtures: %s\n' "$FIXTURE_ROOT"
