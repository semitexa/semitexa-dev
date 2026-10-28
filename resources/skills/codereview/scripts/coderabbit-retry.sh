#!/usr/bin/env bash
# Wake CodeRabbit on PRs it skipped for its rate limit — one PR per run.
#
# Usage:
#   coderabbit-retry.sh [--dry-run]
#
# CodeRabbit does not queue a review that hits its limit: the head commit gets
# a "Review rate limited" status and nothing happens until the next push or a
# manual `@coderabbitai review` (docs.coderabbit.ai/management/rate-limits).
# This finds open PRs whose HEAD carries that status and posts the trigger on
# ONE of them, so a timer running every 30 minutes spends a refilled slot
# instead of burning the whole window at once. Usage-based reviews are OFF for
# the org (2026-09-25), so a trigger that is still over the limit is skipped
# again for free — retrying costs nothing.
#
# A head is re-triggered at most once per CODERABBIT_RETRY_MIN_GAP_SECONDS
# (default 1500 — just under the 30-minute timer, so every run may retry);
# Among eligible heads a release-blocking PR goes first — one carrying
# CODERABBIT_RETRY_PRIORITY_LABEL or named in CODERABBIT_RETRY_PRIORITY —
# then the least recently triggered. Without that, never-triggered heads always
# won and a release blocker waited three runs (2026-09-27).
# A new push is a new head, so it starts fresh.
#
# Environment:
#   CODERABBIT_RETRY_OWNER            GitHub org/user to scan (default: semitexa)
#   CODERABBIT_RETRY_MATCH            status text that means "skipped, wake me"
#                                     (case-insensitive ERE, default: rate limited)
#   CODERABBIT_RETRY_MIN_GAP_SECONDS  per-head back-off (default: 1500)
#   CODERABBIT_RETRY_PRIORITY_LABEL   PR label that jumps the queue
#                                     (default: release-blocker; empty disables)
#   CODERABBIT_RETRY_PRIORITY         PRs that jump the queue, space- or
#                                     comma-separated owner/repo#number
#   CODERABBIT_RETRY_STATE_DIR        where trigger times are kept
#                                     (default: $XDG_STATE_HOME/semitexa/coderabbit-retry)
set -euo pipefail

OWNER="${CODERABBIT_RETRY_OWNER:-semitexa}"
MATCH="${CODERABBIT_RETRY_MATCH:-rate limited}"
MIN_GAP="${CODERABBIT_RETRY_MIN_GAP_SECONDS:-1500}"
PRIORITY_LABEL="${CODERABBIT_RETRY_PRIORITY_LABEL-release-blocker}"
PRIORITY=" $(printf '%s' "${CODERABBIT_RETRY_PRIORITY:-}" | tr ',' ' ') "
STATE_DIR="${CODERABBIT_RETRY_STATE_DIR:-${XDG_STATE_HOME:-$HOME/.local/state}/semitexa/coderabbit-retry}"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

if ! gh auth status >/dev/null 2>&1; then
    # Loud, not silent: a timer that quietly stops being able to post is the
    # failure this exists to prevent.
    log "ERROR gh is not authenticated for this session; nothing checked"
    exit 1
fi

mkdir -p "$STATE_DIR"
now=$(date +%s)
candidates=()
checked=0

mark_for() { printf '%s/%s-%s-%s' "$STATE_DIR" "$(printf '%s' "$1" | tr '/' '_')" "$2" "$3"; }

# Captured BEFORE the loop: fed through process substitution, a failed search
# left the loop empty and the run logged "none waiting" with exit 0 — the
# silent failure this script exists to prevent. 1000 is the search API's own
# ceiling on results, far above what this org keeps open.
if ! prs=$(gh search prs --owner="$OWNER" --state=open --limit 1000 \
    --json repository,number --jq '.[] | "\(.repository.nameWithOwner) \(.number)"'); then
    log "ERROR gh search prs failed for owner '$OWNER'; nothing checked"
    exit 1
fi

while read -r repo number; do
    [ -n "$repo" ] || continue
    # Labels ride along as a JSON array, so a label with spaces stays one token.
    pr=$(gh api "repos/$repo/pulls/$number" \
        --jq 'if .draft then "" else .head.sha + " " + ([.labels[].name] | tojson) end' 2>/dev/null) || continue
    head=${pr%% *}
    [ -n "$head" ] || continue
    labels=${pr#* }
    checked=$((checked + 1))
    status=$(gh api "repos/$repo/commits/$head/status" \
        --jq '[.statuses[] | select(.context | test("coderabbit"; "i")) | .description][0] // ""' 2>/dev/null) || continue
    # CodeRabbit now reports as a check run ("Review rate limited" in its
    # title) and sets no commit status: read alone, the status found nothing,
    # and every run logged "none waiting" while a dozen reviews were skipped.
    if [ -z "$status" ]; then
        status=$(gh api "repos/$repo/commits/$head/check-runs" \
            --jq '[.check_runs[] | select(.name | test("coderabbit"; "i")) | (.output.title // "")][0] // ""' 2>/dev/null) || continue
    fi
    printf '%s' "$status" | grep -qiE "$MATCH" || continue

    last=$(cat "$(mark_for "$repo" "$number" "$head")" 2>/dev/null || echo 0)
    [ $((now - last)) -ge "$MIN_GAP" ] || continue
    rank=1
    case "$PRIORITY" in *" $repo#$number "*) rank=0 ;; esac
    if [ -n "$PRIORITY_LABEL" ] && printf '%s' "$labels" | grep -qF "\"$PRIORITY_LABEL\""; then
        rank=0
    fi
    candidates+=("$rank $last $repo $number $head")
done <<< "$prs"

if [ "${#candidates[@]}" -eq 0 ]; then
    log "checked $checked open PR(s); none waiting on a skipped review"
    exit 0
fi

read -r rank _ repo number head < <(printf '%s\n' "${candidates[@]}" | sort -k1,1n -k2,2n | head -n 1)
why=""
[ "$rank" -eq 0 ] && why=", release blocker"

if [ "$DRY_RUN" -eq 1 ]; then
    log "dry-run: would trigger $repo#$number at ${head:0:7} (${#candidates[@]} waiting$why)"
    exit 0
fi

gh pr comment "$number" -R "$repo" --body "@coderabbitai review" >/dev/null
# The mark of the PR that was PICKED — not whichever the loop scanned last,
# which recorded the retry against the wrong PR.
printf '%s\n' "$now" > "$(mark_for "$repo" "$number" "$head")"
log "triggered $repo#$number at ${head:0:7} (${#candidates[@]} waiting$why)"
