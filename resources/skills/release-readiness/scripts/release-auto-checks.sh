#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd curl
require_cmd python3

ensure_release_domain_namespace

SMOKE_HOST="${SMOKE_HOST:-$RLS_DEMO_HOST}"
SMOKE_BASE_URL="${SMOKE_BASE_URL:-http://${SMOKE_HOST}}"

curl_smoke() {
    curl --resolve "${SMOKE_HOST}:80:127.0.0.1" -sS "$@"
}

check_page() {
    local path="$1"
    local marker="${2:-}"
    local body_file
    body_file="$(mktemp)"

    local status
    status="$(curl_smoke -H 'Accept: text/html' -o "$body_file" -w '%{http_code}' "${SMOKE_BASE_URL}${path}")"
    if [ "$status" != "200" ]; then
        cat "$body_file" >&2 || true
        rm -f "$body_file"
        fail "Route check failed for ${path} (status ${status})"
    fi

    if ! grep -iFq '<!doctype html>' "$body_file"; then
        cat "$body_file" >&2 || true
        rm -f "$body_file"
        fail "Route check failed for ${path} (not html)"
    fi

    if grep -q '"authentication_required"' "$body_file"; then
        cat "$body_file" >&2 || true
        rm -f "$body_file"
        fail "Route check failed for ${path} (auth_required body)"
    fi

    if [ -n "$marker" ] && ! grep -Fq "$marker" "$body_file"; then
        cat "$body_file" >&2 || true
        rm -f "$body_file"
        fail "Route check failed for ${path} (missing marker: ${marker})"
    fi

    rm -f "$body_file"
    ok "HTTP 200 ${path}"
}

check_json_page() {
    local path="$1"
    local marker="${2:-}"
    local body_file
    body_file="$(mktemp)"

    local status
    status="$(curl_smoke -H 'Accept: application/json' -o "$body_file" -w '%{http_code}' "${SMOKE_BASE_URL}${path}")"
    if [ "$status" != "200" ]; then
        cat "$body_file" >&2 || true
        rm -f "$body_file"
        fail "JSON route check failed for ${path} (status ${status})"
    fi

    if [ -n "$marker" ] && ! grep -Fq "$marker" "$body_file"; then
        cat "$body_file" >&2 || true
        rm -f "$body_file"
        fail "JSON route check failed for ${path} (missing marker: ${marker})"
    fi

    rm -f "$body_file"
    ok "JSON 200 ${path}"
}

check_logs() {
    local log_file
    log_file="$(mktemp)"
    compose logs --since 10m app >"$log_file" 2>&1 || true

    if grep -Eq 'Fatal error|authentication_required' "$log_file"; then
        cat "$log_file" >&2 || true
        rm -f "$log_file"
        fail "Recent app logs contain fatal/authentication errors"
    fi

    if grep -Eq 'denied payload=Semitexa\\Ssr\\Application\\Payload\\Request\\(SseKissPayload|SsrFallbackPayload|SsrLocaleSwitchPayload)' "$log_file"; then
        cat "$log_file" >&2 || true
        rm -f "$log_file"
        fail "Recent app logs contain denied SSR helper payloads"
    fi

    if grep -Eq 'denied payload=Semitexa\\Modules\\(SsrDemo|OrmDemo)\\Application\\Payload\\Request\\' "$log_file"; then
        cat "$log_file" >&2 || true
        rm -f "$log_file"
        fail "Recent app logs contain denied public demo payloads"
    fi

    ok "Recent app logs look clean"
    rm -f "$log_file"
}

info "Running automated release checks..."

(
    cd "$RELEASE_ROOT"
    "$RELEASE_ROOT/bin/semitexa" self-test
)

# Can this box actually run what we are about to ship?
#
# system:doctor asks the packages themselves — is the database reachable, does
# ImageMagick carry the WEBP coder, is the cache driver coroutine-safe. That is
# precisely the class of gap that made the release clone unfit for months
# without anything noticing: no node, no pcov, no webp delegate, all while a
# WebP image pipeline was being released through it.
#
# FAILS the release only on `fail`. Warnings are printed and do not block: a
# warn is "usable, look at this" (a duplicate weave node, a redis cache driver),
# and a gate that stops a release for those is a gate someone routes around.
doctor_gate() {
    local report
    if ! report="$(cd "$RELEASE_ROOT" && "$RELEASE_ROOT/bin/semitexa" system:doctor --json 2>/dev/null)"; then
        warn "system:doctor is unavailable in the release clone; environment capabilities were not checked"
        return 0
    fi

    # Reading the report happens in its own step with its own exit code. An
    # earlier draft piped straight into a heredoc and a syntax error in the
    # reader made the gate print "no failing capability" and return 0 — the
    # exact shape of failure this whole gate exists to end.
    local summary
    if ! summary="$(printf '%s' "$report" | python3 "$SCRIPT_DIR/release-doctor-summary.py" 2>&1)"; then
        fail "system:doctor produced a report this gate could not read, so nothing was checked:
$summary"
    fi

    while IFS= read -r line; do
        case "$line" in
            COUNTS*) info "system:doctor ${line#COUNTS }" ;;
            WARN*)   warn "system:doctor: ${line#WARN }" ;;
        esac
    done <<<"$summary"

    if printf '%s\n' "$summary" | grep -q '^FAIL '; then
        fail "system:doctor reports a capability this release needs and this environment does not have:
$(printf '%s\n' "$summary" | grep '^FAIL ' | sed 's/^FAIL /  - /')"
    fi

    ok "system:doctor found no failing capability"
}
doctor_gate

# Are we about to ship a dependency with a known advisory?
#
# `composer audit` has always been able to answer; nothing in the release flow
# asked. MEASURED 2026-09-06 on the real command: network up and nothing found
# gives exit 0 with a JSON report; network DOWN gives exit 100 and an EMPTY
# stdout. So the exit code alone cannot separate "clean" from "never ran", and
# the parse has to be the thing that decides — an audit that did not happen is a
# hard failure here, not a quiet pass.
#
# ONLY an explicit low severity is non-blocking. Everything else stops the
# release, including an advisory carrying no severity at all: composer gained
# that field in 2.7 and this project pins no composer version, so an older
# binary can report an advisory it cannot classify — and unclassified is not
# harmless. A release stopped by a genuine low in a dev-only package would be a
# release someone ships with the gate switched off, which is why low stays a
# warning.
audit_gate() {
    local report summary
    report="$(cd "$RELEASE_ROOT" && composer audit --locked --format=json 2>/dev/null || true)"

    if ! summary="$(printf '%s' "$report" | python3 "$SCRIPT_DIR/release-audit-summary.py" 2>&1)"; then
        fail "composer audit did not produce a report this gate could read, so no dependency was checked:
$summary"
    fi

    while IFS= read -r line; do
        case "$line" in
            COUNTS*)   info "composer audit ${line#COUNTS }" ;;
            SEVERITY*) info "composer audit severity ${line#SEVERITY }" ;;
            LOW*)      warn "advisory (not blocking): ${line#LOW }" ;;
        esac
    done <<<"$summary"

    if printf '%s\n' "$summary" | grep -q '^BLOCK '; then
        fail "Dependencies carry advisories that are not classified as low:
$(printf '%s\n' "$summary" | grep '^BLOCK ' | sed 's/^BLOCK /  - /')"
    fi

    ok "composer audit found nothing above low severity"
}

audit_gate

# Lightweight HTTP availability checks against the live Semitexa Demo on
# demo.rls.semitexa.test. These are NOT the test suite — see bin/semitexa
# test:run below for the real test gate. Scope is Semitexa Demo only: home
# (/) and section routes (/demo/<section>); Site/OS/Platform and the legacy
# framework Playground routes are intentionally out of release smoke scope.
# Markers are picked from <title> / <h1> / CTA content stable across releases.
check_page / "Get Started"
check_page /demo/routing "Section Overview"
check_page /demo/routing/basic "Basic Route"
check_page /demo/di/readonly "Readonly Injection"
check_page /demo/data/relations "Relations"
check_page /demo/auth/session "Session Auth"
check_page /demo/events/sync "Sync Events"
check_page /demo/events/sse "SSE Stream"
check_page /demo/rendering "One rendering story, not two"
check_page /demo/rendering/components "Components"
check_page /demo/rendering/seo "SEO"
check_page /demo/rendering/deferred "Deferred Blocks"
check_page /demo/platform/tenancy-resolution "Resolution Story"
check_page /demo/api/graphql "GraphQL API"
check_page /demo/cli/runtime-maintenance "Runtime Maintenance"
check_page /demo/testing/payload-contracts "Payload Contract Testing"

check_json_page '/demo/routing/basic?_format=json' '"featureTitle":"Basic Route"'
check_json_page '/demo/rendering/components?_format=json' '"sourceCode"'

check_logs

# Real test gate, phase 1 — full PHPUnit via bin/semitexa test:run.
# Positional targets deliberately make test:run SKIP its own E2E phase: the
# clone's dev-module Playwright suite assumes the dev environment (Playground
# tenants, dev domains) and is out of release smoke scope. Browser smoke is
# skill-owned and runs separately below.
(
    cd "$RELEASE_ROOT"
    _phpunit_paths=""
    for _dir in packages/*/tests src/modules/*/tests; do
        [ -d "$_dir" ] && _phpunit_paths="$_phpunit_paths $_dir"
    done
    # shellcheck disable=SC2086
    "$RELEASE_ROOT/bin/semitexa" test:run $_phpunit_paths
)

# Real test gate, phase 2 — skill-owned browser smoke (Semitexa Demo only).
# Deploys references/release-smoke.spec.ts + its dedicated Playwright config
# into the clone and runs ONLY that spec via the e2e-runner service, so the
# clone's own playwright.config testMatch (dev-module E2E) never applies.
run_playwright_smoke() {
    info "Browser smoke: Semitexa Demo via skill-owned release-smoke spec..."
    local smoke_dir="$RELEASE_ROOT/var/release-smoke"
    mkdir -p "$smoke_dir"
    cp "$SCRIPT_DIR/../references/release-smoke.spec.ts" "$smoke_dir/"
    cp "$SCRIPT_DIR/../references/release-smoke.playwright.config.ts" "$smoke_dir/"
    (
        cd "$RELEASE_ROOT"
        docker compose \
            -f docker-compose.yml -f docker-compose.mysql.yml \
            -f docker-compose.redis.yml -f docker-compose.ollama.yml \
            -f docker-compose.test.yml \
            run --rm e2e-runner npx --no-install playwright test \
            --config=var/release-smoke/release-smoke.playwright.config.ts
    )
    ok "Browser smoke passed (Semitexa Demo)"
}

run_playwright_smoke

ok "Automated release checks passed"
