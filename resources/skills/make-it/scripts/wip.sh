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
# A search that fails exits 2 and says so: "nothing open" read from a failed
# search would open a PR on top of one already in review.
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

if ! open=$(gh search prs --owner="$OWNER" --state=open --limit 1000 \
    --json repository,number --jq '.[] | "\(.repository.nameWithOwner) \(.number)"'); then
    echo "ERROR gh search prs failed for owner $OWNER; nothing is known about what is open"
    exit 2
fi

prs=()
while read -r repo number; do
    [ -n "$repo" ] && prs+=("$repo#$number")
done <<< "$open"
count=${#prs[@]}
# The search returns at most 1000: a full page may have left PRs out.
if [ "$count" -ge 1000 ]; then
    echo "ERROR gh search prs returned its 1000-result limit for owner $OWNER; nothing is known about what is open"
    exit 2
fi
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
