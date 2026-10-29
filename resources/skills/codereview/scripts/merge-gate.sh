# shellcheck shell=bash
# The merge verdict of one PR, from facts already fetched: sourced by
# pr-merge-sweep.sh, kept free of gh and jq so it can be tested anywhere.
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
# READY needs every one of: no unresolved thread, no "Outside diff range"
# finding left unanswered in a review body (those live outside the threads and
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
