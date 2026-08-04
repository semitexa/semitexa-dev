#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd curl

ensure_release_domain_namespace

info "Starting release clone..."
(
    cd "$RELEASE_ROOT"
    "$RELEASE_ROOT/bin/semitexa" install
    "$RELEASE_ROOT/bin/semitexa" server:start
)

tmp_body="$(mktemp)"
trap 'rm -f "$tmp_body"' EXIT

status=""
for _attempt in $(seq 1 20); do
    status="$(curl --max-time 5 -sS -o "$tmp_body" -w '%{http_code}' -H "Host: ${RLS_DEMO_HOST}" "http://127.0.0.1/" || true)"
    if [ "$status" = "200" ]; then
        break
    fi
    sleep 1
done

[ "$status" = "200" ] || fail "Release clone did not come up cleanly on proxy endpoint http://127.0.0.1/ (status ${status:-curl-failed})"

# The app container runs as root, the phpunit container as 1000:1000. Whichever
# writes a log file first owns it, so a root-created app.log silently blocks every
# later test run from logging — the checks stay green while thousands of log lines
# are dropped. Normalising ownership here (rather than by hand, once) keeps the fix
# from regressing the next time a log file is rotated or recreated by the app.
normalize_release_log_ownership() {
    local host_uid host_gid
    host_uid="$(id -u)"
    host_gid="$(id -g)"

    local app_container
    app_container="$(cd "$RELEASE_ROOT" && docker compose -f docker-compose.yml ps -q app 2>/dev/null || true)"
    if [ -z "$app_container" ]; then
        warn "Could not resolve the release clone's app container; skipping var/log ownership normalization"
        return 0
    fi

    if ! docker exec "$app_container" sh -c \
        "chown -R ${host_uid}:${host_gid} /var/www/html/var/log && chmod -R u+rwX,g+rwX /var/www/html/var/log"
    then
        warn "Could not normalize var/log ownership in the release clone; test-run logging may be dropped"
        return 0
    fi

    ok "Release clone var/log is writable by both the app (root) and the test runner (${host_uid}:${host_gid})"
}

normalize_release_log_ownership

ok "Release clone is reachable on http://${RLS_DEMO_HOST}/"
