#!/usr/bin/env bash
# Reply to a PR review item — line comment, review summary, or issue comment.
#
# Usage:
#   pr-reply.sh <repo-slug> <pr-number> <comment-id> <body> [--kind=<kind>]
#
# Kinds:
#   line     (default) — replies to a line-anchored review comment via
#                        POST /repos/<repo>/pulls/<pr>/comments/<id>/replies.
#                        <id> must be the line comment id.
#   review              — posts a new conversation comment on the PR via
#                        POST /repos/<repo>/issues/<pr>/comments. <id> is the
#                        review id and is recorded as a Markdown reference at
#                        the top of the body for traceability.
#   issue               — same endpoint as `review`, but <id> is the existing
#                        issue/conversation comment being responded to.
#
# Throttle:
#   Sleeps a random 4-11s before posting, so a queue worked in a loop does not
#   trip GitHub's secondary rate limits. Override with
#   PR_REPLY_DELAY_MIN_SECONDS / PR_REPLY_DELAY_MAX_SECONDS; set both to 0 for none.
#
# Examples:
#   pr-reply.sh semitexa/platform-wm 5 12345 "Fixed — moved overflow to .content"
#   pr-reply.sh semitexa/semitexa-core 72 4234028496 "Already addressed in caa141b." --kind=review
#   pr-reply.sh semitexa/semitexa-core 72 4360079531 "Will revisit once Codex usage resets." --kind=issue
set -euo pipefail

KIND="line"
POSITIONAL=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --kind=*)
            KIND="${1#--kind=}"
            shift
            ;;
        --kind)
            KIND="${2:-}"
            shift 2
            ;;
        --help|-h)
            sed -n '2,26p' "$0"
            exit 0
            ;;
        --)
            shift
            while [[ $# -gt 0 ]]; do
                POSITIONAL+=("$1")
                shift
            done
            ;;
        *)
            POSITIONAL+=("$1")
            shift
            ;;
    esac
done

if [[ "${#POSITIONAL[@]}" -lt 4 ]]; then
    echo "Usage: pr-reply.sh <repo-slug> <pr-number> <comment-id> <body> [--kind=<kind>]" >&2
    exit 1
fi

# Throttle before posting.
#
# GitHub answers a burst of replies with secondary rate limits, and the caller is
# usually a loop working through a queue, so the pause belongs here rather than in
# an instruction the caller has to remember. Override with
# PR_REPLY_DELAY_MIN_SECONDS / PR_REPLY_DELAY_MAX_SECONDS; set both to 0 to
# disable. Non-numeric or inverted bounds skip the sleep rather than fail — a
# throttle is not worth failing a reply over.
MIN_DELAY_SECONDS="${PR_REPLY_DELAY_MIN_SECONDS:-4}"
MAX_DELAY_SECONDS="${PR_REPLY_DELAY_MAX_SECONDS:-11}"

if [[ "$MIN_DELAY_SECONDS" =~ ^[0-9]+$ ]] && [[ "$MAX_DELAY_SECONDS" =~ ^[0-9]+$ ]] && (( MAX_DELAY_SECONDS >= MIN_DELAY_SECONDS )); then
    DELAY_SECONDS=$(( RANDOM % (MAX_DELAY_SECONDS - MIN_DELAY_SECONDS + 1) + MIN_DELAY_SECONDS ))
    if (( DELAY_SECONDS > 0 )); then
        sleep "$DELAY_SECONDS"
    fi
fi

REPO="${POSITIONAL[0]}"
PR="${POSITIONAL[1]}"
COMMENT_ID="${POSITIONAL[2]}"
BODY="${POSITIONAL[3]}"

case "$KIND" in
    line)
        gh api "repos/$REPO/pulls/$PR/comments/$COMMENT_ID/replies" \
            -f body="$BODY" \
            --jq '{id: .id, body: .body, createdAt: .created_at, kind: "line"}'
        ;;
    review)
        REF_LINE="> Re: review summary [#$COMMENT_ID](https://github.com/$REPO/pull/$PR#pullrequestreview-$COMMENT_ID)"
        gh api "repos/$REPO/issues/$PR/comments" \
            -f body="$REF_LINE

$BODY" \
            --jq '{id: .id, body: .body, createdAt: .created_at, kind: "review"}'
        ;;
    issue)
        REF_LINE="> Re: comment [#$COMMENT_ID](https://github.com/$REPO/pull/$PR#issuecomment-$COMMENT_ID)"
        gh api "repos/$REPO/issues/$PR/comments" \
            -f body="$REF_LINE

$BODY" \
            --jq '{id: .id, body: .body, createdAt: .created_at, kind: "issue"}'
        ;;
    *)
        echo "Unknown --kind: $KIND (expected: line, review, issue)" >&2
        exit 1
        ;;
esac
