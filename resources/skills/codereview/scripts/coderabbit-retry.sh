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
# (default 3600); among eligible heads the least recently triggered goes first.
# A new push is a new head, so it starts fresh.
#
# Environment:
#   CODERABBIT_RETRY_OWNER            GitHub org/user to scan (default: semitexa)
#   CODERABBIT_RETRY_MATCH            status text that means "skipped, wake me"
#                                     (case-insensitive ERE, default: rate limited)
#   CODERABBIT_RETRY_MIN_GAP_SECONDS  per-head back-off (default: 3600)
#   CODERABBIT_RETRY_STATE_DIR        where trigger times are kept
#                                     (default: $XDG_STATE_HOME/semitexa/coderabbit-retry)
set -euo pipefail

OWNER="${CODERABBIT_RETRY_OWNER:-semitexa}"
MATCH="${CODERABBIT_RETRY_MATCH:-rate limited}"
MIN_GAP="${CODERABBIT_RETRY_MIN_GAP_SECONDS:-3600}"
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

while read -r repo number; do
    [ -n "$repo" ] || continue
    head=$(gh api "repos/$repo/pulls/$number" --jq 'if .draft then "" else .head.sha end' 2>/dev/null) || continue
    [ -n "$head" ] || continue
    checked=$((checked + 1))
    status=$(gh api "repos/$repo/commits/$head/status" \
        --jq '[.statuses[] | select(.context | test("coderabbit"; "i")) | .description][0] // ""' 2>/dev/null) || continue
    printf '%s' "$status" | grep -qiE "$MATCH" || continue

    mark="$STATE_DIR/$(printf '%s' "$repo" | tr '/' '_')-$number-$head"
    last=$(cat "$mark" 2>/dev/null || echo 0)
    [ $((now - last)) -ge "$MIN_GAP" ] || continue
    candidates+=("$last $repo $number $head")
done < <(gh search prs --owner="$OWNER" --state=open --limit 100 \
    --json repository,number --jq '.[] | "\(.repository.nameWithOwner) \(.number)"')

if [ "${#candidates[@]}" -eq 0 ]; then
    log "checked $checked open PR(s); none waiting on a skipped review"
    exit 0
fi

read -r _ repo number head < <(printf '%s\n' "${candidates[@]}" | sort -n | head -n 1)

if [ "$DRY_RUN" -eq 1 ]; then
    log "dry-run: would trigger $repo#$number at ${head:0:7} (${#candidates[@]} waiting)"
    exit 0
fi

gh pr comment "$number" -R "$repo" --body "@coderabbitai review" >/dev/null
printf '%s\n' "$now" > "$mark"
log "triggered $repo#$number at ${head:0:7} (${#candidates[@]} waiting)"
