# shellcheck shell=bash
# The merge verdict of one PR: sourced by pr-merge-sweep.sh. Kept free of jq
# (gh's own --jq does the JSON) so it can be tested anywhere with a fake gh.
#
#   merge_verdict <unresolved threads> <unanswered outside-diff reviews> \
#                 <CodeRabbit status> <conclusion> <title> <merge state>
#
# CodeRabbit is the reviewer (Greptile stopped reviewing on 2026-10-14). Its
# result on the CURRENT head is a check run (status completed / in_progress,
# title "Finished", "Review rate limited", "Review in progress"); an older head
# may carry the commit status it used to set ("success" / "Review completed"),
# passed here as status completed. "none" means no result on this head at all.
#
# READY needs every one of: no unresolved thread, no finding left unanswered
# in a review body ("Outside diff range", or the "Follow-up findings" /
# "Follow-up Comments" blocks CodeRabbit writes since 2026-10-29) (those live outside the threads and
# passed as clean when only threads were counted), a finished review, and a
# CLEAN merge state. "Review rate limited" is a skipped review, never a pass.
merge_verdict() {
    local unresolved=$1 outside=$2 status=$3 conclusion=$4 title=$5 merge_state=$6

    if (( unresolved > 0 )); then
        echo "FIX:$unresolved"
    elif (( outside > 0 )); then
        echo "FIX:outside-$outside"
    elif [[ "$status" == "none" ]]; then
        echo "WAIT:coderabbit-none"
    elif [[ "$status" != "completed" ]]; then
        echo "WAIT:coderabbit-in-progress"
    elif [[ "$title" =~ [Rr]ate\ limited ]]; then
        echo "WAIT:coderabbit-rate-limited"
    elif [[ "$conclusion" != "success" || ! "$title" =~ ^(Finished|Review\ completed)$ ]]; then
        echo "WAIT:coderabbit-${conclusion:-unknown}"
    elif [[ "$merge_state" != "CLEAN" ]]; then
        echo "WAIT:merge-state-$merge_state"
    else
        echo "READY"
    fi
}

# The findings in review bodies nobody answered on <owner/repo> PR <n>:
# "Outside diff range", and "Follow-up findings" / "Follow-up Comments"
# (CodeRabbit's newer blocks for the same thing — semitexa-dev#163 merged past
# two of them on 2026-10-29 because only the first was matched, and #164's own
# review used the second heading the same night). Those live in review bodies, not threads: a CodeRabbit review carrying them
# counts until a comment of someone else names its id (pr-reply.sh
# --kind=review writes a link to it). Both lists are read to the end: a PR
# with more reviews or comments than one page hid the later ones (40 reviews
# and 100 comments before 2026-10-29). A failed read fails the caller.
outside_unanswered() {
    local slug=$1 number=$2 reviews answered
    reviews="$(gh api --paginate "repos/$slug/pulls/$number/reviews?per_page=100" \
        --jq '.[] | select(.user.login == "coderabbitai[bot]" and ((.body // "") | test("Outside diff range|Follow-up (findings|comments)"; "i"))) | .id')" || return 1
    answered="$(gh api --paginate "repos/$slug/issues/$number/comments?per_page=100" \
        --jq '.[] | select(.user.login != "coderabbitai[bot]") | (.body // "") | scan("[0-9]{6,}")')" || return 1
    outside_count "$reviews" "$answered"
}

# How many of the review ids (one per line) no answered id (one per line) names.
outside_count() {
    local reviews=$1 answered=$2 id count=0
    while read -r id; do
        [[ -z "$id" ]] && continue
        grep -qxF -- "$id" <<<"$answered" || count=$((count + 1))
    done <<<"$reviews"
    echo "$count"
}
