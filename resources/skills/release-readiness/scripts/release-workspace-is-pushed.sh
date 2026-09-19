#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

# WORK THAT EXISTS ONLY ON THIS MACHINE IS INVISIBLE TO EVERY OTHER GATE.
#
# Each release gate reads origin, or the release clone, which is built from
# origin. A commit sitting on a local branch is therefore not "not yet
# released" — it does not exist at all, as far as the release is concerned.
#
# That is survivable on its own. What is not is a registry that DESCRIBES such
# code and did get merged: StructuralOutlierBudget and semantic-rule-counts.json
# both live in semitexa/dev, a different repository from the packages they
# measure, so a sweep lands as two independent PRs and one of them can simply
# not be pushed.
#
# MEASURED 2026-09-19: scheduler, workflow and project-graph each carried a
# domain-model sweep committed locally and never pushed, while the registries
# recording the swept numbers were already on master. Three gates went red in
# three separate preflight runs, each naming a symptom
# ("budget still 53 / 352", "semitexa-project-graph 0 -> 20") and none naming
# the cause. Roughly 1500 lines of real work were one "fix the registry"
# away from being recorded as never having happened.
#
# ⚠️ The obvious check CANNOT see this. `git rev-list origin/master..origin/develop`
# compares two REMOTE refs, so an unpushed local commit is invisible to it; on
# that same day it reported 2 packages with pending work when the real number
# was 6. The branch has to be compared against its own upstream.

DEV_PACKAGES="$DEV_ROOT/packages"
[ -d "$DEV_PACKAGES" ] || fail "Cannot find $DEV_PACKAGES to check for unpushed work."

unpushed=()
dirty=()
untracked_branch=()

for dir in "$DEV_PACKAGES"/*/; do
    repo="${dir%/}"
    name="$(basename "$repo")"
    [ -d "$repo/.git" ] || continue

    branch="$(git -C "$repo" rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
    [ -n "$branch" ] && [ "$branch" != "HEAD" ] || continue

    # An upstream that does not resolve is its own finding: the branch has
    # never been pushed, so ALL of it is local.
    if ! git -C "$repo" rev-parse --verify --quiet "origin/$branch" >/dev/null 2>&1; then
        untracked_branch+=("$name ($branch has no origin/$branch)")
        continue
    fi

    ahead="$(git -C "$repo" rev-list --count "origin/$branch..$branch" 2>/dev/null || echo 0)"
    if [ "$ahead" != "0" ]; then
        subject="$(git -C "$repo" log -1 --format='%s' "$branch" 2>/dev/null | cut -c1-58)"
        unpushed+=("$name [$branch] $ahead commit(s), newest: $subject")
    fi

    if [ -n "$(git -C "$repo" status --porcelain 2>/dev/null)" ]; then
        dirty+=("$name [$branch] $(git -C "$repo" status --porcelain | wc -l) uncommitted file(s)")
    fi
done

problems=0

if [ "${#unpushed[@]}" -ne 0 ]; then
    problems=$((problems + ${#unpushed[@]}))
    printf '[FAIL] Commits exist only in the authoring workspace:\n' >&2
    printf '  %s\n' "${unpushed[@]}" >&2
fi

if [ "${#untracked_branch[@]}" -ne 0 ]; then
    problems=$((problems + ${#untracked_branch[@]}))
    printf '[FAIL] Branches that were never pushed:\n' >&2
    printf '  %s\n' "${untracked_branch[@]}" >&2
fi

if [ "${#dirty[@]}" -ne 0 ]; then
    problems=$((problems + ${#dirty[@]}))
    printf '[FAIL] Uncommitted changes in the authoring workspace:\n' >&2
    printf '  %s\n' "${dirty[@]}" >&2
fi

if [ "$problems" -ne 0 ]; then
    printf '\n' >&2
    printf 'Push and merge them, or stash them deliberately. A release cut now would\n' >&2
    printf 'ship a tree that does not contain this work, while anything already merged\n' >&2
    printf 'that DESCRIBES it — a budget, a rule count, an index — would be measured\n' >&2
    printf 'against its absence and read as a regression somewhere else entirely.\n' >&2
    fail "Authoring workspace has $problems item(s) that never reached origin."
fi

ok "Authoring workspace is fully pushed: every package branch matches its upstream, no uncommitted changes."
