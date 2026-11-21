# shellcheck shell=bash
# The merge verdict of one PR: sourced by pr-merge-sweep.sh. Kept free of jq
# (gh's own --jq does the JSON) so it can be tested anywhere with a fake gh.
#
#   merge_verdict <unresolved threads> <unanswered outside-diff reviews> \
#                 <CodeRabbit status> <conclusion> <title> <merge state> \
#                 [<threads CodeRabbit's last reply calls not addressed>]
#
# CodeRabbit is the reviewer (Greptile stopped reviewing on 2026-10-14). Its
# result on the CURRENT head is a check run (status completed / in_progress,
# title "Finished", "Review rate limited", "Review in progress"); an older head
# may carry the commit status it used to set ("success" / "Review completed"),
# passed here as status completed. "none" means no result on this head at all.
#
# READY needs every one of: no unresolved thread, no thread whose last
# CodeRabbit reply says the finding is not addressed (bot_unaddressed below),
# no finding left unanswered
# in a review body ("Outside diff range", the "Follow-up findings" /
# "Follow-up Comments" blocks CodeRabbit writes since 2026-10-29, a "Minor /
# Major / Critical comments: <n>" section, or a Major or Critical entry of an
# "Additional comments" section) (those live outside the threads and
# passed as clean when only threads were counted), a finished review, and a
# CLEAN merge state. "Review rate limited" is a skipped review, never a pass.
merge_verdict() {
    local unresolved=$1 outside=$2 status=$3 conclusion=$4 title=$5 merge_state=$6 unaddressed=${7:-0}

    if (( unresolved > 0 )); then
        echo "FIX:$unresolved"
    elif (( unaddressed > 0 )); then
        echo "FIX:bot-unaddressed-$unaddressed"
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
# --kind=review writes a link to it). So does a review with a "Minor comments:
# <n>", "Major comments: <n>" or "Critical comments: <n>" section, findings
# CodeRabbit writes in the body and nowhere else: semitexa-ssr#192 was merged
# on 2026-11-21 with review 6002171521's Minor (SsePendingSweep.php 94) never
# answered, and #193 read READY over 6002907626. An "Additional comments:
# <n>" section counts for its Major and Critical entries alone (operator,
# 2026-11-21): CodeRabbit marks what it puts there "Unverified finding ... not
# a verified issue", and six of the 97 reviews with such a section read in
# nine Semitexa repositories that day held a Major, none answered where looked
# (semitexa-core#242's review 5931344628: a read refused by the breaker
# another request opened).
# An entry is the line that opens with its line range, and the severity is the
# bold badge on that line, the icon and the word as CodeRabbit writes them now,
# or the emoji form of its older reviews, after the category:
#   `215-215`: **<picture><img src=".../major-16.svg" ...></picture> Major** · **Stability & Availability** · **Heavy lift**
#   `84-84`: **🗄️ Data Integrity & Integration** | **🟠 Major** | **⚡ Quick win**
# A Minor or Trivial badge, an entry with a category and no severity, and
# "LGTM!" match none of it. No Critical was among them: it is read by the same
# rule, on the word. The pattern looks from the heading to the first such line
# and not for where the section ends: what can follow it with a Major on an
# entry line is a section counted anyway, or "Nitpick comments: <n>", so the
# body is read in the pieces between those headings and a Major nitpick after
# a Minor additional entry is in another piece (review of semitexa-dev#303;
# gh's jq has no way to say "up to that heading" inside the pattern).
# "[^\x00]" is any character, a line
# break too: jq 1.7 fails on "[\s\S]" under the "i" flag, and gh's own jq
# knows no lookahead, so the pattern keeps to what both and PCRE read alike.
# Not counted: "Nitpick comments" (CodeRabbit's own word for optional; none of
# the five such reviews of November was answered). Both lists are read to the end: a PR
# with more reviews or comments than one page hid the later ones (40 reviews
# and 100 comments before 2026-10-29). A failed read fails the caller.
outside_unanswered() {
    local slug=$1 number=$2 reviews answered
    reviews="$(gh api --paginate "repos/$slug/pulls/$number/reviews?per_page=100" \
        --jq '.[] | select(.user.login == "coderabbitai[bot]" and any((.body // "") | split("Nitpick comments:? \\(?[0-9]"; "i")[]; test("Outside diff range|Follow-up (findings|comments)|(Minor|Major|Critical) comments:? \\(?[0-9]|Additional comments:? \\(?[0-9][^\\x00]*?\\n`[0-9]+(-[0-9]+)?`: (\\*\\*[^*\\n]+\\*\\* [^*\\n]{1,3} ){0,3}\\*\\*[^*\\n]* (Major|Critical)\\*\\*"; "i"))) | .id')" || return 1
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

# The review threads of <owner/repo> PR <n> that CodeRabbit's own last reply
# calls not addressed. A thread counted as done once the author had replied on
# it, and GitHub shows it resolved because CodeRabbit resolves its own thread
# when the commented lines change, so semitexa-ssr#192 was merged on READY on
# 2026-11-21 with a Major open: the last reply on thread 4649964866 named a
# lost-frame case and said "I'm not marking the finding as addressed yet".
# What the reply says decides, whoever resolved the thread and whether it is
# resolved at all. A failed read fails the caller.
bot_unaddressed() {
    local slug=$1 number=$2 rows
    rows="$(thread_comments "$slug" "$number")" || return 1
    unaddressed_threads "$rows"
}

# The last ten comments of every review thread, one per line, tab-separated:
# thread id, comments in the thread, comments read, "root" (the finding that
# opens the thread) or "reply", author as <type>/<login>, and the text on one
# line without the HTML comments. Every page of threads; ten
# comments rather than the whole thread, because a hundred threads of fifty
# cost fifty rate-limit points a pull request. gh only reshapes here: what the
# rows mean is decided in unaddressed_threads.
thread_comments() {
    local slug=$1 number=$2
    # shellcheck disable=SC2016  # $owner, $name ... are GraphQL variables
    gh api graphql --paginate -F owner="${slug%%/*}" -F name="${slug#*/}" -F number="$number" \
        -f query='query($owner: String!, $name: String!, $number: Int!, $endCursor: String) { repository(owner: $owner, name: $name) { pullRequest(number: $number) { reviewThreads(first: 100, after: $endCursor) { pageInfo { hasNextPage endCursor } nodes { id isResolved resolvedBy { login } comments(last: 10) { totalCount nodes { databaseId author { __typename login } replyTo { databaseId } body } } } } } } }' \
        --jq '.data.repository.pullRequest.reviewThreads.nodes[] | .id as $thread | .comments as $c | $c.nodes[] | [$thread, $c.totalCount, ($c.nodes | length), (if .replyTo == null then "root" else "reply" end), "\(.author.__typename)/\(.author.login)", ((.body // "") | gsub("(?s)<!--.*?-->"; " ") | gsub("\\s+"; " "))] | @tsv'
}

# How many threads in those rows are not addressed by CodeRabbit's own word:
# its last reply read on the thread matches UNADDRESSED_REPLY. CodeRabbit is
# the Bot whose GraphQL login is "coderabbitai" (REST calls it
# "coderabbitai[bot]"), exactly: "coderabbitai-helper" is somebody else, and
# their "this addresses it" after the bot's "not addressed" settles nothing.
# A thread longer than what was read, with no CodeRabbit reply among the
# comments read, may hold a "not addressed" before them, so it counts too.
unaddressed_threads() {
    local rows=$1 thread total seen kind author body current='' state='' count=0
    while IFS=$'\t' read -r thread total seen kind author body; do
        [[ -z "$thread" ]] && continue
        if [[ "$thread" != "$current" ]]; then
            [[ "$state" == open ]] && count=$((count + 1))
            current=$thread
            state=done
            (( total > seen )) && state=open
        fi
        if [[ "$kind" == reply && "$author" == "Bot/coderabbitai" ]]; then
            state=done
            without_details "$body"
            grep -qiE -- "$UNADDRESSED_REPLY" <<<"$PLAIN" && state=open
        fi
    done <<<"$rows"
    [[ "$state" == open ]] && count=$((count + 1))
    echo "$count"
}

# PLAIN: the text without its <details> blocks. CodeRabbit folds its "Analysis
# chain" into them, script output and file contents that may hold any phrase,
# and nests them. Each closing tag takes the nearest opening one before it, so
# whole blocks go innermost first until none is left; a non-greedy cut ended
# an outer block at the inner closing tag and left the rest to be read as the
# reply. A closing tag with no opening one is dropped; an opening one never
# closed stays with its text (what follows may be the answer itself).
without_details() {
    local text=$1 before after
    while [[ "$text" == *'</details>'* ]]; do
        before=${text%%'</details>'*}
        after=${text#*'</details>'}
        if [[ "$before" == *'<details'* ]]; then
            before=${before%'<details'*}
        fi
        text="$before $after"
    done
    PLAIN=$text
}

# The wording CodeRabbit uses for "not addressed", read from its 584 replies
# on the last 300 review comments of nine Semitexa repositories (2026-11-21).
# The first family is a rule, not a list of sentences: "not", "cannot" or a
# "...n't" followed by "addressed" within seven words of the same sentence:
#   "I'm not marking the finding as addressed yet." / "I am not marking ..."
#   (59 replies), "the finding is not fully addressed", "has not yet been
#   addressed", "isn't addressed"; also "unaddressed", "yet to be addressed".
# The others are its remaining ways of saying so:
#   "I can't confirm that the Major is closed yet",
#   "This finding remains open." / "Two findings remain open" / "The version
#   check remains open" / "I'll leave this finding open",
#   "one failure case remains" / "One gap remains" / "The risk remains" /
#   "One part of the Major remains" / "A related data-loss path remains",
#   "I found one remaining failure case", "Verification is inconclusive",
#   "The fix is still pending".
# Its confirmations ("This addresses the finding.", "I withdraw the finding.",
# "Review thread resolved.", "I'm marking the finding as addressed.") match
# none of it, and neither do "The finding remains withdrawn" or "#214 is still
# open" (a pull request). A false FIX costs a look at the thread and a false
# READY merges a defect, so a reply holding both kinds counts as not addressed.
# "[^a-z ]{1,3}" and ".{0,3}" are the apostrophe, typographic or plain, in any
# locale.
UNADDRESSED_REPLY='(^|[^a-z])(not|cannot|[a-z]+n[^a-z ]{1,3}t)( [^ ]*[^ .!?;:]){0,7} addressed'
UNADDRESSED_REPLY+='|(^|[^a-z])unaddressed|yet to be addressed'
UNADDRESSED_REPLY+='|(can.{0,3}t|cannot|can not|could not|couldn.{1,3}t|unable to) (yet )?confirm (that|the fix|this fix|the finding)'
UNADDRESSED_REPLY+='|(remains?|stays?) (open|unaddressed|unresolved|unfixed|unverified)'
UNADDRESSED_REPLY+='|(finding|thread|major|critical|issue|concern)s? (in this thread )?(is|are) still (open|unaddressed|unresolved|unfixed)'
UNADDRESSED_REPLY+='|leave (this|the|it)( finding| thread)? open'
UNADDRESSED_REPLY+='|(case|gap|scenario|race|finding|problem|risk|issue|path|part|bug|defect|failure)s? (of the [a-z]+ )?(still )?remains?([.,:;]|$| (for|until|in|when|if|on) )'
UNADDRESSED_REPLY+='|(one|two|three|a|another) (remaining|related|more|further|narrower) [^.]{0,40}(case|gap|scenario|race|failure|problem|path)'
UNADDRESSED_REPLY+='|remaining (risk|case|gap|scenario|race|failure)'
UNADDRESSED_REPLY+='|inconclusive|still pending'
