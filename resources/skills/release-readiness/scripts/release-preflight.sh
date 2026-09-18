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
# Constraint FORMS plus the question that matters: does the release a package
# floored actually contain what that package uses. Two kinds of "uses" are
# checked — a PHP class, named by an import or written out in full, and a Twig
# function the dependency registers, which a template reaches with no import
# and no class name anywhere.
#
# STILL INVISIBLE, and named here rather than discovered during a release: a
# Twig VARIABLE a provider binds into the render context, and a template
# NAMESPACE a consumer extends. Neither has a registration site to compare a
# tagged release against, so neither is checkable the way a class or a function
# is. A floor for one of those is still found by a person.
run_stage "check-internal-constraints" php "$SCRIPT_DIR/release-check-internal-constraints.php"
# A floor declared but not dated. An author writes WHICH dependency needs one —
# extra.semitexa.floors: {"semitexa/core": "next"} — and the release writes the
# version, because until the tag exists the date is a guess and a guess goes
# stale the moment a release slips. MEASURED 2026-09-16: os floored prompt at
# the day the author expected, review ran a day past it, and preflight died on
# "that tag is not in semitexa-prompt". This stage fails while any declaration
# is still undated, so the resolve step cannot be forgotten on the way to a tag. The way out is
# printed by the failure itself: --confirm --commit, which lands the floor on origin/master. The
# commit is not optional — bump-packages.php tags after `git reset --hard origin/master`, so an
# edit left in the working tree never reaches the tag.
run_stage "floors-are-dated" php "$SCRIPT_DIR/release-resolve-floors.php" --check
# Not a gate — a question, printed where the operator is already reading. The
# constraint check above compares CLASS declarations, so a new public METHOD on
# a class that shipped months ago is invisible to it: ssr called
# Request::getServedPath() on 2026-09-18 while its floor named a core that had
# no such method, and only a person remembering stood in the way.
run_stage "new-public-api" php "$SCRIPT_DIR/release-new-public-api.php"
# The shipped capability index is generated in the monorepo and travels inside
# semitexa/dev. Without this stage a package that gained a capability could be
# released while the index still described the previous shape — and an index
# that has silently rotted teaches a confidently shrinking subset of the
# framework, which is worse than shipping none at all.
run_stage "capability-index-freshness" "$SCRIPT_DIR/release-capability-index-check.sh"
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
