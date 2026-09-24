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
# Retried, because "no report" here is the network rather than a verdict.
#
# composer audit needs https://packagist.org/api/security-advisories/ — the API
# host, NOT repo.packagist.org that serves package metadata. MEASURED from this
# host by probing that endpoint every 8s for 96s while the release stack ran:
# reachable in 9 of 12 probes, with drops lasting about 16 seconds and no
# relation to the stack restart (it answered 8s after one). During a drop
# composer times out at 10s, exits 100 and prints nothing on stdout — the case
# this gate refuses, correctly, because an exit code cannot separate "clean"
# from "never ran".
#
# So the retry window has to outlast a drop, not merely repeat inside one: five
# attempts, twenty seconds apart, is roughly 100 seconds against a 16-second
# outage. An earlier three-by-three version was tuned against the wrong
# measurement — repo.packagist.org, which is a different host and was fine —
# and kept failing.
#
# This does NOT soften the gate: an audit that never produces a readable report
# still fails, and the message says how many attempts it took. Verified with a
# composer stub that always exits 100.
#
# A report therefore means the advisories API was genuinely reached. MEASURED
# with COMPOSER_DISABLE_NETWORK=1 immediately after a successful run had warmed
# every cache: exit 100, empty stdout, "Network disabled, request canceled:
# https://packagist.org/api/security-advisories/". Composer caches repo metadata
# and zips, never the advisory answer, so there is no cached verdict for this
# gate to mistake for a fresh one.
#
# The "package information was loaded from the local cache" warning composer
# sometimes prints is about repo.packagist.org, not the advisories API, and
# under --locked the versions being asked about come from composer.lock anyway.
AUDIT_ATTEMPTS=5
AUDIT_RETRY_SLEEP=20

audit_gate() {
    local report summary attempt
    summary=""

    for attempt in $(seq 1 "$AUDIT_ATTEMPTS"); do
        report="$(cd "$RELEASE_ROOT" && composer audit --locked --format=json 2>/dev/null || true)"

        if summary="$(printf '%s' "$report" | python3 "$SCRIPT_DIR/release-audit-summary.py" 2>&1)"; then
            break
        fi

        if [ "$attempt" -lt "$AUDIT_ATTEMPTS" ]; then
            warn "composer audit produced no readable report (attempt ${attempt}/${AUDIT_ATTEMPTS}); retrying"
            sleep "$AUDIT_RETRY_SLEEP"
            continue
        fi

        fail "composer audit did not produce a report this gate could read after ${AUDIT_ATTEMPTS} attempts, so no dependency was checked:
$summary"
    done

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
#
# Positional targets also BYPASS test:run's own discovery, and that discovery
# carries a guard this list has to repeat: a release checkout keeps every
# package repo under packages/, but composer installs only a subset, and the
# tests of a package that is not installed cannot autoload its own src classes.
#
# MEASURED before this guard existed: packages/semitexa-ultimate/tests ran here
# and produced ELEVEN errors, all "Class Semitexa\Ultimate\...\InitCommand does
# not exist" — semitexa/ultimate is the project skeleton the clone was built
# FROM, so its src/ is the clone root and there is no vendor/semitexa/ultimate
# for its own tests to load. Dev never saw this because dev never takes this
# path; the same eleven tests are simply not discovered there.
(
    cd "$RELEASE_ROOT"
    _phpunit_paths=""
    for _dir in packages/*/tests src/modules/*/tests; do
        [ -d "$_dir" ] || continue
        _pkg_composer="$(dirname "$_dir")/composer.json"
        if [ -f "$_pkg_composer" ]; then
            # jq, not a line-oriented match: the TOP-LEVEL name is the package,
            # and "name" also appears under authors[] and funding[]. A regex that
            # takes the first hit can read an author's name as the package, and
            # then vendor/<that> never exists, so the guard skips a package that
            # IS installed. Grepping cannot tell the two apart; a parser can.
            _pkg_name="$(jq -r '.name // empty' "$_pkg_composer" 2>/dev/null || true)"
            if [ -n "$_pkg_name" ] && [ ! -d "vendor/$_pkg_name" ]; then
                continue
            fi
        fi
        _phpunit_paths="$_phpunit_paths $_dir"
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

# ── phpstan neutrality ─────────────────────────────────────────────────────
#
# NOT a pass/fail gate on a clean analysis: the project sits above its own
# baseline by hundreds of messages, so demanding zero would block every release
# and demanding nothing is what let the number get there. The bar is that a
# release does not make it WORSE — the same bargain StructuralOutlierBudgetTest
# strikes for class size, which is the guard that has actually caught drift.
#
# MEASURED 2026-09-10: 597 errors above a 1141-entry baseline, identical across
# two consecutive runs. Raise this number only by editing it deliberately, with
# a note saying what grew; a release that quietly bumps it is the drift this
# exists to notice.
# Measured IN THE RELEASE CONTAINER, because that is where this gate runs. The
# first number here was 597, taken on the dev host, and the clone reported 604 on
# byte-identical files: the host has PHP 8.4.1 with phpstan 2.1.40, the container
# PHP 8.4.25 with phpstan 2.2.13, and a newer analyser simply infers more. Seven
# messages of apparent regression that no commit caused. Re-measure here, never
# on the host.
#
# 604 -> 538 on 2026-09-12, measured in the release container during the
# Cavavera 2026.09.11.1941 preflight, which reported "538 above baseline — 604
# was the ceiling; lower it." Sixty-six messages went away in one release: the
# typed accessors on the GraphQL result, the narrowed container catch in the
# Twig extension catalog, the three-state registry write, and the record types
# that replaced hand-written array shapes each removed a cluster of them.
#
# Lowered deliberately rather than left: a ceiling that stays above the real
# count is not a ratchet, it is sixty-six messages of room for the next
# regression to hide in, and the gate would pass while the number climbed back.
#
# 538 -> 379 on 2026-09-13. The largest single cluster in the project was 83
# "Cannot cast mixed to X": a Swoole\Table row, a debug_backtrace() frame and a
# decoded JSON body all arrive as `array<mixed, mixed>`, and the habit around
# that was `(string) ($row['col'] ?? '')` at the point of use. That is wrong
# twice -- the analyser cannot see a string there, and casting an ARRAY value
# raises "Array to string conversion" rather than yielding the default, so a
# malformed row took a worker down. Semitexa\Core\Support\Row narrows once,
# where it can be tested; adopting it across the SSE subsystem, the ORM queue
# and query, the Swoole bootstrap and the UiPlayground feeds removed 107.
#
# MEASURED THE HARD WAY, and the note above is why. The dev container reports
# 375 for the same tree: it runs phpstan 2.1.40 against the clone's 2.2.13, and
# four messages differ on byte-identical files. So this number was taken HERE,
# in the release container, with develop's package code staged into the clone
# and then reverted -- because at the time of measuring, core/orm/ssr had not
# yet merged to master and the clone reported 508, of which every one of the
# 133 extra was `unknown class Semitexa\Core\Support\Row` and the casts it
# replaces. Expect 379 once those merges land; if the gate reads higher on the
# first release after this note, the difference is the release's own, not this
# measurement's.
#
# 379 -> 363, same day, second pass. This one was the custom rule rather than
# the analyser's own: semitexa.explicitOptionalDependency, 38 violations of
# "package absence must not be modelled through runtime checks". Every single
# class_exists() it flagged named a class in the SAME package, or one in
# semitexa/core or semitexa/locale -- plain `require` entries. The Twig
# extensions showed the shape: the guard decided whether a function was
# REGISTERED while the method body called the class unguarded, so an absent
# class fataled either way. Three of them could switch off real work -- locale
# validation on an input, the deferred-request table, per-request tenant
# cleanup.
#
# Measured the same way as the line above: in the release container, with
# develop's package code staged into the clone and reverted afterwards.
#
# 363 -> 357: the last stale baseline entry went, and with it three real errors
# it had been hiding. `composer phpstan:strict` now reports ZERO unmatched
# entries — the baseline finally describes only errors that exist.
#
# 357 -> 353, and this is the last time these two numbers will disagree.
# Everything above about "measure it HERE, the dev container reports something
# else" had one cause, found by making the baseline gate below a hard failure:
# both sides declared `phpstan/phpstan: ^2.1` and had drifted to DIFFERENT
# locks, 2.1.40 here and 2.2.13 there. A newer analyser infers more, so the
# same bytes measured differently and four baseline entries that match in the
# workspace matched nothing in the clone. Both are pinned to 2.1.40 now and the
# two report the same 353.
#
# (Pinned in the clone's own composer.json, which is clone-owned and not
# synced. PHPSTAN_EXPECTED_ANALYSER below is what stops that drifting back
# unnoticed — a ratchet a developer cannot reproduce locally is not a ratchet.)
#
# 353 -> 179 on 2026-09-13, and most of that is a CHANGE OF SCOPE rather than
# fixes. `src` left phpstan.neon's paths: the 13 modules under src/modules are
# the workspace's exercise surface, but src/ is in no git repository, so a fix
# made there lives on one machine while the (versioned) baseline would record
# the error as gone. The gate now measures the analysed, versioned, releasable
# tree. 365 baseline entries went with them — strict cannot report an entry
# whose file is no longer analysed, so leaving them would have been invisible
# rot of exactly the kind this gate exists to catch.
#
# 179 -> 177 on the first preflight that ran the strict gate: it measured 177
# and said "lower it", which is the ratchet doing its job. The two are the
# Pub/Sub message annotation from review of ssr#116.
#
# 177 -> 178 on 2026-09-17, raised deliberately and for one named reason. The
# CSP-nonce work renamed AssetRenderer::inlineScriptAttributes() to
# inlineNonceAttributes(), because a <style> needs the same nonce handling a
# <script> does and two copies of that rule had already drifted once. The
# helper is now called from two places rather than one, and
# AssetEntry::$attributes is an untyped `array`, so each call site is an
# argument.type error where there used to be a single one. The count grew by
# one; nothing became less correct.
#
# Typing the property WAS tried and reverted the same day. It removed those
# errors and produced three new ones a level up — the callers that BUILD an
# AssetEntry pass array<mixed, mixed>, array<mixed> and plain mixed — and
# stranded two baseline entries, tripping the rot gate. Fixing it properly
# means typing the whole asset-definition chain, which is a refactor and not
# something to land on release eve. The debt is named here so the next person
# does not rediscover it by repeating the experiment.
#
# 178 -> 179 on 2026-09-19, raised deliberately and for one named reason. This
# cut ships the SQL-identifier hardening, and with it the NEW custom rule
# semitexa.builtSqlFragment. The rule found exactly one call in the analysed
# tree: CollectionQueryCompiler::applyCursor()'s whereRaw(), which is given
# implode(' OR ', $branches) — a keyset-pagination predicate assembled at
# runtime because the number of OR branches depends on the sort spec.
#
# The fragment is safe in substance: every identifier goes through
# SqlIdentifier::quote() and every value is a bound '?'. It is not safe by
# CONSTRUCTION, which is the distinction the rule is written on, and the rule
# is right to say so — a dynamic OR-chain cannot be a literal, so there is no
# spelling of this that satisfies it without restructuring cursor pagination.
# That is ORM work, not release-eve work.
#
# NOT baselined, on purpose. AcceptedViolations states the project's position:
# "a baseline makes a violation invisible... the rule still fires, the gate
# still reports it". Hiding the first finding of a security rule on the day it
# ships is how the rule gets quietly switched off. The ceiling records it in
# the open instead.
#
# The other three errors this cut added were fixed rather than absorbed:
# two SqlIdentifier::quote(string|null) call sites in the ORM relation loader
# (a ManyToMany missing its pivot metadata quoted null into an empty
# identifier) and an always-true instanceof in ResponseRenderer.
#
# 2026-09-24: the number moved into a versioned file,
# packages/semitexa-dev/resources/phpstan/phpstan-ceiling.json, beside the
# semantic-rule snapshot. It used to be a shell default of 179 here, so an
# environment variable on one machine could raise the release's bar without a
# commit anyone reviewed; and a count below the ceiling only printed "lower it",
# so an improvement was one forgotten edit away from being given back. The file
# is read from the release clone, like everything this gate judges.
PHPSTAN_CEILING_FILE="packages/semitexa-dev/resources/phpstan/phpstan-ceiling.json"

# The analyser this ceiling and this baseline were measured with. Not a
# preference — a precondition: every number in this gate is meaningless when
# the clone analyses with a different version than the workspace does.
# It lives in the same file as the ceiling, for the same reason.

phpstan_neutrality_gate() {
    # The old overrides are refused by name rather than ignored: someone who
    # sets one expects it to take effect, and a gate that silently disagrees
    # with its operator is how a release ships with the wrong bar.
    if [ -n "${PHPSTAN_CEILING:-}" ] || [ -n "${PHPSTAN_EXPECTED_ANALYSER:-}" ]; then
        fail "PHPSTAN_CEILING / PHPSTAN_EXPECTED_ANALYSER are no longer read from the environment."
        fail "Change ${PHPSTAN_CEILING_FILE} in a reviewed commit instead."
        exit 1
    fi

    local PHPSTAN_CEILING PHPSTAN_EXPECTED_ANALYSER
    PHPSTAN_CEILING="$(php -r '$d = json_decode((string) @file_get_contents($argv[1]), true); echo is_int($d["ceiling"] ?? null) ? $d["ceiling"] : "";' "$RELEASE_ROOT/$PHPSTAN_CEILING_FILE" 2>/dev/null)"
    PHPSTAN_EXPECTED_ANALYSER="$(php -r '$d = json_decode((string) @file_get_contents($argv[1]), true); echo is_string($d["analyser"] ?? null) ? $d["analyser"] : "";' "$RELEASE_ROOT/$PHPSTAN_CEILING_FILE" 2>/dev/null)"

    # Unreadable, missing or not a number: the same rule this gate applies to an
    # unreadable report — something it cannot judge is not something it passes.
    case "$PHPSTAN_CEILING" in
        ''|*[!0-9]*)
            fail "phpstan ceiling unreadable in ${PHPSTAN_CEILING_FILE} (need an integer \"ceiling\")."
            exit 1
            ;;
    esac
    if [ -z "$PHPSTAN_EXPECTED_ANALYSER" ]; then
        fail "phpstan analyser version missing from ${PHPSTAN_CEILING_FILE} (need a string \"analyser\")."
        exit 1
    fi

    local analyser
    analyser="$(cd "$RELEASE_ROOT" && docker compose \
        -f docker-compose.yml -f docker-compose.mysql.yml \
        -f docker-compose.redis.yml -f docker-compose.ollama.yml \
        exec -T app vendor/bin/phpstan --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"

    if [ -z "$analyser" ]; then
        fail "Could not read the analyser version — cannot judge neutrality."
        exit 1
    fi

    if [ "$analyser" != "$PHPSTAN_EXPECTED_ANALYSER" ]; then
        fail "phpstan ${analyser} here, but the ceiling and baseline were measured with ${PHPSTAN_EXPECTED_ANALYSER}."
        fail "A newer analyser infers more, so the same code measures differently and baseline entries stop matching."
        fail "Pin it back (composer require --dev phpstan/phpstan:${PHPSTAN_EXPECTED_ANALYSER} in the clone),"
        fail "or re-measure both the ceiling and the baseline on the new one and update this script deliberately."
        exit 1
    fi

    info "phpstan ${analyser}: analysing (ceiling ${PHPSTAN_CEILING} above baseline)..."

    local report
    # Spelled out, like run_playwright_smoke above: there is no COMPOSE variable
    # in this script, and referring to one made every run die on `set -u` before
    # phpstan started. The gate then failed closed and said it could not judge —
    # correct, and the reason it was caught on its first real release.
    # `-c phpstan-strict.neon`, which is phpstan.neon plus
    # reportUnmatchedIgnoredErrors. One analysis, two facts: the same real
    # errors the plain config reports, AND every baseline entry that no longer
    # matches anything. See the baseline-rot gate below for why the second one
    # is here.
    local stderr_file
    stderr_file="$(mktemp)"
    report="$(cd "$RELEASE_ROOT" && docker compose \
        -f docker-compose.yml -f docker-compose.mysql.yml \
        -f docker-compose.redis.yml -f docker-compose.ollama.yml \
        exec -T app php -d memory_limit=2G \
        vendor/bin/phpstan analyse --no-progress -c phpstan-strict.neon --error-format=json 2>"$stderr_file")" || true

    # `file_errors` counts only what phpstan could attribute to a file. A
    # configuration mistake, an unreadable path or an internal error lands in
    # `errors` instead, and `file_errors` stays 0 — so reading it alone lets the
    # gate report a clean run for an analysis that never analysed anything. Both
    # are read, and a path-less error is named separately because it almost
    # never means "one more violation"; it means the run itself is wrong.
    # Real errors and stale-baseline reports arrive in the same list, so the
    # totals cannot be used directly: `file_errors` counts both. Partition on
    # the message instead, and keep the ceiling comparison about real errors.
    local count global_errors stale
    count="$(printf '%s' "$report" | php -r \
        '$d = json_decode(stream_get_contents(STDIN), true);
         if (!is_array($d["totals"] ?? null)) { exit; }
         $real = 0;
         foreach ($d["files"] ?? [] as $file) {
             foreach ($file["messages"] as $m) {
                 if (!str_contains($m["message"], "Ignored error pattern")) { $real++; }
             }
         }
         echo $real + (int) ($d["totals"]["errors"] ?? 0);' 2>/dev/null)"
    stale="$(printf '%s' "$report" | php -r \
        '$d = json_decode(stream_get_contents(STDIN), true);
         $n = 0;
         foreach ($d["files"] ?? [] as $file) {
             foreach ($file["messages"] as $m) {
                 if (str_contains($m["message"], "Ignored error pattern")) { $n++; }
             }
         }
         echo $n;' 2>/dev/null)"
    global_errors="$(printf '%s' "$report" | php -r \
        '$d = json_decode(stream_get_contents(STDIN), true); echo (int) ($d["totals"]["errors"] ?? 0);' 2>/dev/null)"

    # An unreadable report is not a pass. A gate that cannot see is a gate that
    # says yes to everything, which is how the claim in SKILL.md came to
    # describe a check nothing ran.
    if [ -z "$count" ]; then
        fail "phpstan produced no readable report — cannot judge neutrality."
        # The usual cause has a name. A baseline entry pointing at a deleted
        # file makes phpstan refuse to START under the strict config, and it
        # says so on stderr rather than producing a report.
        if grep -q 'is neither a directory, nor a file path' "$stderr_file" 2>/dev/null; then
            fail "A baseline entry names a path that no longer exists:"
            grep -m3 'is neither a directory' "$stderr_file" | sed 's/^/  /' >&2
            fail "Fix with: php bin/phpstan/strip-stale-baseline.php phpstan-baseline.neon <out>"
        fi
        rm -f "$stderr_file"
        exit 1
    fi
    rm -f "$stderr_file"

    if [ "${global_errors:-0}" -gt 0 ]; then
        fail "phpstan reported ${global_errors} error(s) with no file — the analysis itself failed, not the code."
        printf '%s' "$report" | php -r \
            '$d = json_decode(stream_get_contents(STDIN), true);
             foreach (array_slice($d["errors"] ?? [], 0, 5) as $e) { echo "  - ", is_array($e) ? ($e["message"] ?? "?") : $e, PHP_EOL; }' 2>/dev/null || true
        exit 1
    fi

    # THE BASELINE MUST DESCRIBE ERRORS THAT EXIST.
    #
    # PHPSTAN.md has said since it was written that `phpstan:strict` "must run
    # as a hard gate" in CI. There is no CI here — that was a deliberate cost
    # decision — so nothing ran it, and the document's own account of "why the
    # baseline rotted before" happened again: measured 2026-09-13, 147 of 1131
    # entries described errors that no longer existed, 13% of the file. That is
    # not tidiness. A `count:` that overshoots lets the next occurrence of the
    # same error through silently, which is exactly how the previous rot "hid
    # 10 real errors" — and one was still hiding when this gate was written.
    #
    # A release is the one moment the whole tree is analysed anyway, so the
    # check costs nothing extra here.
    if [ "${stale:-0}" -gt 0 ]; then
        fail "phpstan: ${stale} baseline entr(y|ies) no longer match any reported error."
        fail "The baseline is describing errors that do not exist, and a stale count hides the next one."
        printf '%s' "$report" | php -r \
            '$d = json_decode(stream_get_contents(STDIN), true);
             $shown = 0;
             foreach ($d["files"] ?? [] as $path => $file) {
                 foreach ($file["messages"] as $m) {
                     if (!str_contains($m["message"], "Ignored error pattern")) { continue; }
                     if ($shown++ >= 5) { echo "  ...\n"; exit; }
                     echo "  - ", substr($m["message"], 0, 160), "\n";
                 }
             }' 2>/dev/null || true
        fail "Re-align with bin/phpstan/strip-unmatched.php and bin/phpstan/sync-counts.php, then re-run."
        exit 1
    fi

    ok "phpstan: baseline is in sync — every entry still matches a real error."

    if [ "$count" -gt "$PHPSTAN_CEILING" ]; then
        fail "phpstan: ${count} errors above baseline, ceiling is ${PHPSTAN_CEILING}."
        fail "This release adds $((count - PHPSTAN_CEILING)). Fix them, or raise \"ceiling\" in ${PHPSTAN_CEILING_FILE} with a \"deliberate\" entry saying what grew."
        exit 1
    fi

    # Below the ceiling fails too. Printing "lower it" did not lower it, and a
    # ceiling left above the real count hands the next release that much room
    # to get worse without anyone deciding it should.
    if [ "$count" -lt "$PHPSTAN_CEILING" ]; then
        fail "phpstan: ${count} above baseline, below the ceiling of ${PHPSTAN_CEILING} — an improvement."
        fail "Lock it in: set \"ceiling\": ${count} in ${PHPSTAN_CEILING_FILE} in the same commit."
        exit 1
    else
        ok "phpstan: ${count} above baseline, unchanged."
    fi
}


# ── semantic-rule ratchet ──────────────────────────────────────────────────
#
# The level-max gate above analyses four packages: orm, core, ssr, tenancy. The
# Semitexa rules — the ones that encode THIS framework's contracts rather than
# general PHP hygiene — are cheap enough to run over everything, and until
# 2026-09-17 nothing did. `ai:verify` runs them on the files a change TOUCHES,
# so a violation in a file nobody edits was never looked at again.
#
# MEASURED 2026-09-17: 223 of them, in 20 packages, accumulated exactly that
# way. This gate does not demand zero — it demands that the number per package
# does not move without somebody saying so.
#
# Compared per package and in BOTH directions. A package that gains a violation
# fails, which is the point. A package that loses one fails too, and the message
# says to lower the entry: a recorded number that silently drifts down stops
# being evidence of anything, and the next reader cannot tell a fix from a gate
# that went blind.
#
# Cost: ~87s cold, ~2s warm. That is why it is here and not in the unit suite,
# where its neighbours in tests/Unit/Structure are pure-PHP scans that finish in
# milliseconds, and where it would add 87s to every test:run for everyone.
semantic_rule_ratchet_gate() {
    local snapshot="packages/semitexa-dev/resources/phpstan/semantic-rule-counts.json"

    if [ ! -f "$RELEASE_ROOT/$snapshot" ]; then
        fail "Semantic-rule snapshot missing: ${snapshot}"
        fail "Something it cannot judge is not something it passes."
        exit 1
    fi

    info "semantic rules: counting across every package..."

    local report stderr_file
    stderr_file="$(mktemp)"
    # Spelled out for the same reason the gate above spells it out: there is no
    # COMPOSE variable in this script, and referring to one dies on `set -u`.
    report="$(cd "$RELEASE_ROOT" && docker compose \
        -f docker-compose.yml -f docker-compose.mysql.yml \
        -f docker-compose.redis.yml -f docker-compose.ollama.yml \
        exec -T app sh -lc 'php -d memory_limit=2G vendor/bin/phpstan analyse \
            --no-progress --error-format=json \
            -c packages/semitexa-dev/config/phpstan-ai-verify.neon \
            $(ls -d packages/semitexa-*/src | grep -v semitexa-ultimate)' 2>"$stderr_file")" || true

    local verdict
    export SNAPSHOT_PATH="$RELEASE_ROOT/$snapshot"
    verdict="$(printf '%s' "$report" | php -r '
        $snapshot = json_decode(file_get_contents(getenv("SNAPSHOT_PATH")), true);
        $report   = json_decode(stream_get_contents(STDIN), true);

        // A run that never ran reports no file errors, which reads exactly like
        // a clean tree. Refuse it rather than pass it.
        if (!is_array($report["totals"] ?? null)) { echo "UNREADABLE"; exit; }
        if ((int) ($report["totals"]["errors"] ?? 0) > 0) { echo "GLOBAL"; exit; }
        if (($report["totals"]["file_errors"] ?? 0) === 0 && ($snapshot["total"] ?? 0) > 0) {
            echo "EMPTY"; exit;
        }

        $found = [];
        foreach ($report["files"] ?? [] as $path => $file) {
            if (preg_match("#packages/(semitexa-[^/]+)/#", $path, $m) !== 1) { continue; }
            $found[$m[1]] = ($found[$m[1]] ?? 0) + count($file["messages"]);
        }
        ksort($found);

        $expected = $snapshot["packages"] ?? [];
        ksort($expected);
        if ($found === $expected) { echo "OK"; exit; }

        foreach (array_keys($found + $expected) as $pkg) {
            $was = $expected[$pkg] ?? 0;
            $now = $found[$pkg] ?? 0;
            if ($was !== $now) { echo $pkg, " ", $was, " -> ", $now, "\n"; }
        }
    ' 2>/dev/null)"

    case "$verdict" in
        OK)
            ok "semantic rules: every package matches the recorded count."
            rm -f "$stderr_file"
            return 0
            ;;
        UNREADABLE|EMPTY|GLOBAL)
            fail "semantic rules: the analysis did not produce a judgeable report (${verdict})."
            sed -n '1,20p' "$stderr_file" >&2 || true
            rm -f "$stderr_file"
            exit 1
            ;;
    esac

    fail "semantic rules: the per-package counts moved."
    printf '%s\n' "$verdict" | sed 's/^/  /' >&2
    fail "A count that went UP is a new violation — fix it, or record it deliberately."
    fail "A count that went DOWN is a fix — lower the entry in ${snapshot} in the same commit."
    rm -f "$stderr_file"
    exit 1
}

# ── quality ledger ─────────────────────────────────────────────────────────
#
# `ai:quality check --all`: every #[AsQualityMetric] against the versioned
# baseline in packages/semitexa-dev/resources/quality/. The cheap ones already
# run on every ai:verify; `--all` adds the release tier, which needs what only a
# release has — the clone's server up — for the per-route request cost.
#
# ONE-way here, unlike the gates above. The baseline is recorded in the
# workspace, and the clone installs a different set of packages with different
# data (demo, showcase-kit): a page can legitimately cost less here. Failing on
# that would demand a `record` in the workspace, which measures the workspace
# again, not the clone — a loop with no exit. So a regression fails, and so does
# anything it cannot judge (no ledger, no server, an unrecorded metric, an
# unreadable report); an improvement is reported. The two-way ratchet lives in
# the workspace, where QualityLedgerGateTest runs on every ai:verify.
quality_ledger_gate() {
    local report
    report="$(cd "$RELEASE_ROOT" && "$RELEASE_ROOT/bin/semitexa" ai:quality check --all --json 2>/dev/null)" || true

    local verdict
    verdict="$(printf '%s' "$report" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($d) || !isset($d["verdict"])) { echo "UNREADABLE\n"; exit; }
        // One-way: the command fails on BETTER too; the release only on what it
        // cannot accept. An error or a skip is never a pass.
        $blocking = array_filter($d["metrics"] ?? [], static fn ($m) => in_array($m["status"], ["worse", "new"], true));
        echo match (true) {
            $d["verdict"] === "pass" => "PASS",
            $d["verdict"] === "fail" && $blocking === [] => "IMPROVED",
            default => strtoupper((string) $d["verdict"]),
        }, "\n";
        if (isset($d["error"])) { echo "  ", $d["error"], "\n"; }
        if (isset($d["reason"])) { echo "  ", $d["reason"], "\n"; }
        foreach ($d["metrics"] ?? [] as $m) {
            if ($m["status"] === "same") { continue; }
            echo "  ", $m["status"], " ", $m["metric"], " ", $m["from"], " -> ", $m["to"], "\n";
            foreach ($m["moved"] ?? [] as $key => $mv) { echo "      ", $key, " ", $mv["from"], " -> ", $mv["to"], "\n"; }
        }
    ' 2>/dev/null)"

    case "$(printf '%s' "$verdict" | head -1)" in
        PASS)
            ok "quality ledger: every metric matches its baseline."
            return 0
            ;;
        IMPROVED)
            warn "quality ledger: lower than the workspace baseline here (not blocking — see the note above the gate):"
            printf '%s\n' "$verdict" | tail -n +2 >&2
            return 0
            ;;
    esac

    fail "quality ledger: $(printf '%s' "$verdict" | head -1 | tr '[:upper:]' '[:lower:]')"
    printf '%s\n' "$verdict" | tail -n +2 >&2
    fail "A regression: fix it, or bin/semitexa ai:quality accept --metric=<id> --reason=\"...\" in the workspace."
    fail "An improvement: bin/semitexa ai:quality record --all in the workspace, and commit the baseline."
    exit 1
}

phpstan_neutrality_gate
semantic_rule_ratchet_gate
quality_ledger_gate

ok "Automated release checks passed"
