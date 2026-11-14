#!/usr/bin/env bash
# What make-it may start next: the open Semitexa PRs and the two limits on them.
#
# Usage:
#   wip.sh                     list open PRs, then "open=N limit=W free=F"
#   wip.sh --can-open <repo>   exit 0 when <repo> may get a new PR, 1 when not,
#                              with one line saying why ("semitexa-orm" or
#                              "semitexa/semitexa-orm")
#
# The limits:
#   - one open PR per repository: work happens on develop, and a PR from develop
#     carries all of it, so a second change joins the PR under review and starts
#     its review over;
#   - at most MAKE_IT_WIP PRs in review at once (default 3): CodeRabbit gives
#     about one review per 30 minutes, and more open PRs only queue.
#
# The listing asks GitHub's API for every repository's open PRs, not its search: the
# search index lags, and a PR opened seconds earlier was missing from it, so a burst
# of PRs opened a fourth one past the limit (tk-wip-count-misses-just-opened-prs).
#
# A listing that fails exits 2 and says so: "nothing open" read from a failed
# listing would open a PR on top of one already in review.
#
# Environment:
#   MAKE_IT_OWNER   GitHub org/user to scan (default: semitexa)
#   MAKE_IT_WIP     review slots (default: 3)
set -euo pipefail

OWNER="${MAKE_IT_OWNER:-semitexa}"
WIP="${MAKE_IT_WIP:-3}"
# Digits only, and no leading zero: bash arithmetic reads 010 as 8 and 08 as an error.
[[ "$WIP" =~ ^(0|[1-9][0-9]*)$ ]] || { echo "ERROR MAKE_IT_WIP must be a whole number without leading zeros, got: $WIP" >&2; exit 2; }

# Checked before the search: a mistyped option read as "list" answered a
# --can-open question with exit 0.
case "${1:-}" in
    '') (( $# == 0 )) || { echo "usage: wip.sh [--can-open <repo>]" >&2; exit 2; } ;;
    --can-open) (( $# == 2 )) && [ -n "$2" ] || { echo "usage: wip.sh --can-open <repo>" >&2; exit 2; } ;;
    *) echo "unknown option: $1 (usage: wip.sh [--can-open <repo>])" >&2; exit 2 ;;
esac

QUERY='query($owner: String!, $endCursor: String) {
  repositoryOwner(login: $owner) {
    repositories(first: 100, after: $endCursor, isArchived: false) {
      pageInfo { hasNextPage endCursor }
      nodes { nameWithOwner pullRequests(states: OPEN, first: 100) { totalCount nodes { number } } }
    }
  }
}'
# A repository with more open PRs than one page holds is named, not cut short.
if ! open=$(gh api graphql --paginate -f owner="$OWNER" -f query="$QUERY" \
    --jq '.data.repositoryOwner.repositories.nodes[] | .nameWithOwner as $r
          | if .pullRequests.totalCount > 100 then "TRUNCATED \($r)" else (.pullRequests.nodes[] | "\($r) \(.number)") end'); then
    echo "ERROR listing open PRs failed for owner $OWNER; nothing is known about what is open"
    exit 2
fi

prs=()
while read -r repo number; do
    if [ "$repo" = TRUNCATED ]; then
        echo "ERROR $number has more than 100 open PRs; nothing is known about what is open"
        exit 2
    fi
    [ -n "$repo" ] && prs+=("$repo#$number")
done <<< "$open"
count=${#prs[@]}
free=$(( WIP > count ? WIP - count : 0 ))

if [ "${1:-}" != "--can-open" ]; then
    [ "$count" -gt 0 ] && printf '%s\n' "${prs[@]}" | sort
    echo "open=$count limit=$WIP free=$free"
    exit 0
fi

want=$2
case "$want" in */*) ;; *) want="$OWNER/$want" ;; esac
# GitHub names are case-insensitive: Semitexa/Semitexa-Core is the same repo.
for pr in ${prs[@]+"${prs[@]}"}; do
    repo=${pr%#*}
    if [ "${repo,,}" = "${want,,}" ]; then
        echo "no: $want already has $pr in review"
        exit 1
    fi
done
if [ "$free" -eq 0 ]; then
    echo "no: $count of $WIP review slots are taken"
    exit 1
fi
echo "yes: $want has no open PR, $free of $WIP slots free"
