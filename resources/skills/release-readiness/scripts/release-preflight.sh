#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

CURRENT_STAGE="session-initialization"
init_release_session

run_stage() {
    local stage="$1"
    shift

    local log_file="$RELEASE_STATE_DIR/${stage}.log"
    CURRENT_STAGE="$stage"
    : >"$log_file"

    if ! "$@" > >(tee "$log_file") 2>&1; then
        local failure_output
        failure_output="$(tail -n 120 "$log_file")"
        if [ -n "$failure_output" ]; then
            fail "Stage '${stage}' failed. Recent output:
${failure_output}"
        fi
        fail "Stage '${stage}' failed without captured output."
    fi
}

on_exit() {
    local status=$?
    if [ "$status" -ne 0 ]; then
        write_failure_report "$CURRENT_STAGE" "$(report_failure_reason)"
        printf '\nRELEASE READINESS: FAIL\n'
    fi
}

trap on_exit EXIT

run_stage "sync-masters" "$SCRIPT_DIR/release-sync-masters.sh"
# sync-root-tests removed: the root semitexa.dev/tests/ release-smoke suite was
# superseded by the module/package-level tests/E2E convention, which the release
# clone's playwright.config testMatch ('packages/*/tests/E2E/**',
# 'src/modules/*/tests/E2E/**') runs directly in the automated-checks stage. The
# old root tests/ dir is gone and was never in testMatch, so this stage synced
# files that never ran.
run_stage "check-internal-constraints" php "$SCRIPT_DIR/release-check-internal-constraints.php"
# Refresh the clone's application code before the containers come up. The package
# tree is pulled every release, but src/ was not — so a package change needing a
# matching consumer-side change was smoke-tested against frozen app code. That is
# how a stale test double survived a release and failed the NEXT preflight instead
# of its own. --code-only leaves the clone's .env and composer.json alone.
run_stage "sync-release-code" "$SCRIPT_DIR/release-sync-root.sh" --code-only
# ...and its infrastructure, which no stage refreshed until now. The clone had
# drifted far enough that its checks were not measuring what we ship: no node, so
# RenderParityTest SKIPPED and a green preflight said nothing about parity; no
# imagemagick webp delegate while a WebP image pipeline was being released. A
# gate that cannot run is worse than a gate that is missing, because the summary
# line looks the same.
run_stage "sync-clone-infra" "$SCRIPT_DIR/release-sync-clone-infra.sh"
run_stage "stop-semitexa-containers" "$SCRIPT_DIR/release-stop-semitexa.sh"
run_stage "start-release-clone" "$SCRIPT_DIR/release-start-rls.sh"
run_stage "sync-release-schema" "$SCRIPT_DIR/release-orm-sync.sh"
# A release run must not accept a skipped check. With this set, a missing node
# fails RenderParityTest instead of skipping it, so the clone can never again
# report parity it did not measure. docker-compose.test.yml passes it through to
# the phpunit service; unset (an ordinary local run) keeps the skip.
export SEMITEXA_PARITY_REQUIRED=1
run_stage "automated-checks" "$SCRIPT_DIR/release-auto-checks.sh"
CURRENT_STAGE="preflight-complete"

write_pending_report

if [ -s "$RELEASE_DIVERGENCE_FILE" ]; then
    printf '\n\033[1;33m⚠ develop is ahead of master for some packages:\033[0m\n'
    while IFS="$(printf '\t')" read -r name ahead develop_sha master_sha; do
        [ -n "$name" ] || continue
        printf '  %s: develop @ %s is %s commit(s) ahead of master @ %s\n' \
            "$name" "$develop_sha" "$ahead" "$master_sha"
    done <"$RELEASE_DIVERGENCE_FILE"
    printf '  Merge develop→master (GitHub PR or /review-prep) before finalize, or finalize will be a no-op for these packages.\n'
fi

printf '\nRELEASE READINESS: PASS\n'
