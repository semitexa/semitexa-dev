#!/usr/bin/env bash
# List every open Semitexa package PR with its merge verdict; optionally merge
# the ones that are READY.
#
# Usage:
#   pr-merge-sweep.sh            # report only
#   pr-merge-sweep.sh --merge    # also merge every READY PR
#
# pr-process.sh is the FIX queue: it drops a PR with no actionable comments,
# which is right for fixing and wrong for merging — a PR waiting on a
# rate-limited review has no comments either, and simply disappears from it.
# This lists ALL open PRs, so none can go missing between fixing and merging.
#
# READY is merge-gate.sh's merge_verdict(): no unresolved actionable comment
# (pr-review.sh's own count), no "Outside diff range" finding left unanswered
# in a CodeRabbit review body, CodeRabbit finished on the CURRENT head ("Review
# rate limited" is a skipped review, not a pass), and mergeStateStatus CLEAN;
# the comment count is taken again once everything else has passed. Greptile
# stopped reviewing on 2026-10-14 and is no longer waited for.
# Anything else is FIX:<n> or WAIT:<reason>. A merge uses --merge (a merge
# commit, as every develop->master merge in these repos) and
# --match-head-commit, so a push landing between the check and the merge
# refuses the merge instead of shipping an unreviewed head. develop is never
# deleted.
#
# Fails closed: an error from gh or pr-review.sh exits non-zero rather than
# reporting "nothing open".
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=merge-gate.sh
. "$SCRIPT_DIR/merge-gate.sh"
MERGE=0
# One option at most: `--merge --help` must not merge while ignoring the rest.
if (( $# > 1 )); then
    echo "expected at most one option" >&2
    exit 2
fi
case "${1:-}" in
    --merge) MERGE=1 ;;
    '') ;;
    -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
esac

queue="$("$SCRIPT_DIR/pr-review.sh" --compact --no-diff)"

rows="$(jq -r '.repos[] | .slug as $s | .prs[] | "\($s) \(.number) \(.summary.unresolvedActionableComments // 0)"' <<<"$queue")"
if [[ -z "$rows" ]]; then
    echo "No open PRs."
    exit 0
fi

ready=0
waiting=0
while read -r slug number unresolved; do
    meta="$(gh pr view "$number" -R "$slug" --json headRefOid,mergeStateStatus --jq '"\(.headRefOid) \(.mergeStateStatus)"')"
    read -r head merge_state <<<"$meta"
    # CodeRabbit reports as a check run on the head; an older head may carry
    # the commit status it used to set. Paginated: a head with more runs than
    # one page holds can have CodeRabbit's on a later one.
    review="$(gh api --paginate "repos/$slug/commits/$head/check-runs?per_page=100" \
        --jq '.check_runs[] | select(.name | test("coderabbit"; "i")) | "\(.status)|\(.conclusion // "")|\(.output.title // "")"' | sed -n 1p)"
    if [[ -z "$review" ]]; then
        review="$(gh api "repos/$slug/commits/$head/status?per_page=100" \
            --jq '[.statuses[] | select(.context == "CodeRabbit")][0] | if . == null then "none||" elif .state == "pending" then "in_progress||\(.description)" else "completed|\(.state)|\(.description)" end')"
    fi
    IFS='|' read -r cr_status cr_conclusion cr_title <<<"$review"
    outside="$(outside_unanswered "$slug" "$number")"

    verdict="$(merge_verdict "$unresolved" "$outside" "${cr_status:-none}" "$cr_conclusion" "$cr_title" "$merge_state")"

    # The comment count above was taken before this PR's reviewers were seen
    # finished; findings posted in between would be missed. Recount now that
    # every gate has passed.
    if [[ "$verdict" == "READY" ]]; then
        recount="$("$SCRIPT_DIR/pr-review.sh" --repo "$slug" --pr "$number" --compact --no-diff \
            | jq -r '[.repos[].prs[].summary.unresolvedActionableComments // 0] | add // 0')"
        if (( recount > 0 )); then
            verdict="FIX:$recount"
        fi
    fi

    printf '%-40s %-8s %-10s %s\n' "$slug#$number" "${head:0:7}" "$merge_state" "$verdict"

    if [[ "$verdict" == "READY" ]]; then
        ready=$((ready + 1))
        if (( MERGE )); then
            gh pr merge "$number" -R "$slug" --merge --match-head-commit "$head"
            echo "  merged $slug#$number"
        fi
    else
        waiting=$((waiting + 1))
    fi
done <<<"$rows"

echo "ready=$ready not-ready=$waiting$( (( MERGE )) && echo ' (ready ones merged)')"
