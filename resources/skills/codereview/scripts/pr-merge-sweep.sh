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
# READY means all of:
#   - no unresolved actionable comment (pr-review.sh's own count)
#   - CodeRabbit's status on the CURRENT head is "success / Review completed"
#     (a success that says "Review rate limited" is a skipped review, not a pass)
#   - a Greptile check run on the head, if there is one, has completed with
#     success, neutral or skipped
#   - still no unresolved comment when recounted after the gates above passed
#   - GitHub mergeStateStatus is CLEAN
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
MERGE=0
# One option at most: `--merge --help` must not merge while ignoring the rest.
if (( $# > 1 )); then
    echo "expected at most one option" >&2
    exit 2
fi
case "${1:-}" in
    --merge) MERGE=1 ;;
    '') ;;
    -h|--help) sed -n '2,27p' "$0"; exit 0 ;;
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
    # The combined status holds the LATEST status per context; the plain
    # statuses list is paginated at 30, so a busy head could hide CodeRabbit.
    status="$(gh api "repos/$slug/commits/$head/status?per_page=100" \
        --jq '[.statuses[] | select(.context == "CodeRabbit")][0] | if . == null then "none|no CodeRabbit status" else "\(.state)|\(.description)" end')"
    state="${status%%|*}"
    desc="${status#*|}"
    # A second reviewer, when installed, reports as a check run. Its findings
    # land as line comments (counted above); an unfinished run means more may come.
    # Paginated: the endpoint pages at 30, and a run on a later page read as
    # "none" would skip the wait. --jq runs per page, so take the first match.
    greptile_runs="$(gh api --paginate "repos/$slug/commits/$head/check-runs?per_page=100" \
        --jq '.check_runs[] | select(.name | test("greptile"; "i")) | "\(.status)|\(.conclusion // "")"')"
    greptile_run="${greptile_runs%%$'\n'*}"
    greptile_status="${greptile_run%%|*}"
    greptile_conclusion="${greptile_run#*|}"

    if (( unresolved > 0 )); then
        verdict="FIX:$unresolved"
    elif [[ "$state" != "success" || "$desc" != "Review completed" ]]; then
        verdict="WAIT:$desc"
    elif [[ -n "$greptile_run" && "$greptile_status" != "completed" ]]; then
        verdict="WAIT:greptile-$greptile_status"
    elif [[ -n "$greptile_run" && ! "$greptile_conclusion" =~ ^(success|neutral|skipped)$ ]]; then
        # A failed or cancelled run posted nothing: no comments is not a pass.
        verdict="WAIT:greptile-${greptile_conclusion:-no-conclusion}"
    elif [[ "$merge_state" != "CLEAN" ]]; then
        verdict="WAIT:merge-state-$merge_state"
    else
        verdict="READY"
    fi

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
