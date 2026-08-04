#!/usr/bin/env bash
# Build a human-friendly processing queue for actionable PR review comments.
set -euo pipefail

usage() {
    # Unquoted heredoc so $0 expands: this script is fanned out to bin/ and to
    # the agent skill directories, and a hard-coded bin/ path in the examples
    # sends an operator running the copy straight to "No such file or directory".
    self="$0"
    cat <<EOF
Usage: ${self} [options]

Options:
  --repo <pkg-or-slug>     Limit to one repo (e.g. semitexa-core or semitexa/semitexa-core)
  --pr <number>            Limit to one PR number
  --ready-only             Show only PRs that are ready for processing
  --fail-on-warnings       Exit non-zero when warnings are present
  --json                   Output enriched JSON instead of a text queue
  --help                   Show this help

Examples:
  ${self}
  ${self} --repo semitexa/semitexa-core
  ${self} --ready-only
  ${self} --repo semitexa-auth --pr 3 --json
EOF
}

REPO_FILTER=""
PR_FILTER=""
OUTPUT_JSON=0
READY_ONLY=0
FAIL_ON_WARNINGS=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --repo)
            # $# is checked before shift 2. With the flag last, ${2:-} hides the
            # missing value and `shift 2` fails under `set -e`, so the script
            # exits 1 having printed nothing at all.
            [ "$#" -ge 2 ] || { printf -- '--repo expects a value\n' >&2; exit 1; }
            REPO_FILTER="$2"
            shift 2
            ;;
        --pr)
            [ "$#" -ge 2 ] || { printf -- '--pr expects a value\n' >&2; exit 1; }
            PR_FILTER="$2"
            shift 2
            ;;
        --ready-only)
            READY_ONLY=1
            shift
            ;;
        --fail-on-warnings)
            FAIL_ON_WARNINGS=1
            shift
            ;;
        --json)
            OUTPUT_JSON=1
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

REVIEW_JSON="$TMPDIR/review.json"
# NOT --actionable-only. That flag drops every PR whose actionable count is
# zero, upstream in pr-review.sh, which is precisely the shape the
# all-line-comments-classified-as-noise warning exists to detect: inline
# comments present, none surviving classification. Filtering there would
# delete the evidence before this script could compute the warning, leaving a
# guard that can never fire. The equivalent filter is applied below instead,
# after warnings are evaluated, so the case stays visible.
ARGS=(--compact --no-diff)
if [[ -n "$REPO_FILTER" ]]; then
    ARGS+=(--repo "$REPO_FILTER")
fi
if [[ -n "$PR_FILTER" ]]; then
    ARGS+=(--pr "$PR_FILTER")
fi

"$SCRIPT_DIR/pr-review.sh" "${ARGS[@]}" > "$REVIEW_JSON"

ENRICHED_JSON="$TMPDIR/enriched.json"
jq '
    def blocked_reasons($repo; $pr):
        [
            if ($repo.worktreeDirty == 1) then "dirty-worktree" else empty end,
            if ($pr.mergeable == "CONFLICTING") then "merge-conflicts" else empty end,
            if ($pr.isDraft == true) then "draft-pr" else empty end,
            # Both default-branch names, not just master. Every Semitexa repo
            # uses master today, so this changes nothing now — but the cost of
            # being wrong differs sharply by direction: accepting main where no
            # repo uses it is harmless, while rejecting it would block every PR
            # in such a repo permanently, and the queue reports that as a fault
            # of the PR rather than of this check. commit.sh already treats the
            # two names alike.
            #
            # NB: this jq program is single-quoted, so an apostrophe anywhere in
            # these comments terminates the shell string and breaks the file.
            if ($pr.baseRefName != "master" and $pr.baseRefName != "main") then "wrong-base-branch" else empty end
        ];
    def warnings($repo; $pr):
        [
            if ($repo.localBranch != $pr.headRefName) then "checked-out-branch-differs-from-pr-head" else empty end,
            if ($pr.mergeable == "UNKNOWN") then "mergeability-unknown" else empty end,
            # A PR carrying inline comments where NONE survive classification is
            # the signature of a stale predicate, not of a clean PR. This queue
            # once reported "no actionable comments" against three real ones
            # because a bot changed its badge vocabulary. Make that case audible
            # instead of letting it read as silence.
            if (($pr.summary.reviewComments // 0) > 0
                and ($pr.summary.actionableLineComments // 0) == 0)
            then "all-line-comments-classified-as-noise" else empty end
        ];
    .repos |= map(
        . as $repo
        | .prs |= map(
            . as $pr
            | .process = {
                blockedReasons: blocked_reasons($repo; $pr),
                warnings: warnings($repo; $pr),
                ready: ((blocked_reasons($repo; $pr) | length) == 0 and .summary.unresolvedActionableComments > 0)
            }
        )
    )
' "$REVIEW_JSON" > "$ENRICHED_JSON"

# Applied here rather than upstream, so warnings computed above survive into the
# output. A PR with no actionable comments is still dropped from the queue - it
# just gets the chance to be reported as suspicious first.
jq '
    .repos |= map(
        .prs |= map(select(
            (.summary.actionableComments // 0) > 0
            or ((.process.warnings // []) | length) > 0
        ))
    )
    | .repos |= map(select((.prs | length) > 0))
' "$ENRICHED_JSON" > "$TMPDIR/enriched-actionable.json"
mv "$TMPDIR/enriched-actionable.json" "$ENRICHED_JSON"

if [[ "$READY_ONLY" -eq 1 ]]; then
    jq '
        .repos |= map(
            .prs |= map(select(.process.ready))
        )
        | .repos |= map(select((.prs | length) > 0))
    ' "$ENRICHED_JSON" > "$TMPDIR/enriched-ready.json"
    mv "$TMPDIR/enriched-ready.json" "$ENRICHED_JSON"
fi

if [[ "$OUTPUT_JSON" -eq 1 ]]; then
    cat "$ENRICHED_JSON"
else
    jq -r --arg script_dir "$SCRIPT_DIR" '
        def fmt_list($items):
            if ($items | length) == 0 then
                "none"
            else
                ($items | join(", "))
            end;
        def location($comment):
            if $comment.kind == "line" then
                "at \($comment.path // "n/a"):\($comment.line // 0)"
            else
                "→ \($comment.htmlUrl // "")"
            end;
        def comment_line($comment):
            "- comment #\($comment.id) [\($comment.kind)] by \($comment.user) \(location($comment))";
        def reply_command($repo; $pr; $comment):
            "  \($script_dir)/pr-reply.sh \($repo.slug) \($pr.number) \($comment.id) \"<reply after push>\" --kind=\($comment.kind)";
        if (.repos | length) == 0 then
            "No actionable review comments found."
        else
            (
                .repos[]
                | . as $repo
                | $repo.prs[]
                | . as $pr
                | ($pr.actionableComments | map(select(.repliedByAuthor | not))) as $unresolved
                | ($unresolved | length) as $unresolvedCount
                | ($unresolved | map(select(.kind == "line")) | length) as $unresolvedLineCount
                | [
                    "Repo: \($repo.slug)",
                    "Path: \($repo.path)",
                    "Local branch: \($repo.localBranch)",
                    "PR: #\($pr.number) \($pr.title)",
                    "Branches: \($pr.headRefName) -> \($pr.baseRefName)",
                    "Mergeable: \($pr.mergeable)",
                    "Draft: \($pr.isDraft)",
                    "Dirty worktree: \($repo.worktreeDirty)",
                    "Open actionable comments: \($unresolvedCount) (line=\($unresolved | map(select(.kind == "line")) | length), review=\($unresolved | map(select(.kind == "review")) | length), issue=\($unresolved | map(select(.kind == "issue")) | length))",
                    "Blocked by: \(fmt_list($pr.process.blockedReasons))",
                    "Warnings: \(fmt_list($pr.process.warnings))",
                    (
                        if $unresolvedCount > 0 then
                            "Next commands:"
                        else
                            "Next commands: none"
                        end
                    ),
                    (
                        if $unresolvedCount > 0 and $repo.localBranch != $pr.headRefName then
                            "  git -C \($repo.path) checkout \($pr.headRefName)"
                        else
                            empty
                        end
                    ),
                    (
                        if $unresolvedCount > 0 then
                            "  git -C \($repo.path) status --short"
                        else
                            empty
                        end
                    ),
                    (
                        if $unresolvedLineCount > 0 then
                            "  # apply the required code changes"
                        else
                            empty
                        end
                    ),
                    (
                        if $unresolvedLineCount > 0 then
                            "  composer phpstan"
                        else
                            empty
                        end
                    ),
                    (
                        if $unresolvedLineCount > 0 then
                            "  \($script_dir)/commit.sh \($repo.path)"
                        else
                            empty
                        end
                    ),
                    (
                        if $unresolvedLineCount > 0 then
                            "  git -C \($repo.path) commit -m \"<review fix message>\""
                        else
                            empty
                        end
                    ),
                    (
                        if $unresolvedLineCount > 0 then
                            "  git -C \($repo.path) push origin HEAD"
                        else
                            empty
                        end
                    ),
                    (
                        if $unresolvedCount > 0 then
                            ($unresolved[] | reply_command($repo; $pr; .))
                        else
                            empty
                        end
                    ),
                    (
                        if $unresolvedCount > 0 then
                            "Unresolved comments:"
                        else
                            "Unresolved comments: none"
                        end
                    ),
                    (
                        if $unresolvedCount > 0 then
                            ($unresolved[] | comment_line(.))
                        else
                            empty
                        end
                    )
                ]
                | map(select(. != ""))
                | join("\n") + "\n" 
            )
        end
    ' "$ENRICHED_JSON"
fi

if jq -e '
    any(
        .repos[]?.prs[]?;
        (.process.blockedReasons | length) > 0
    )
' "$ENRICHED_JSON" >/dev/null; then
    exit 2
fi

if [[ "$FAIL_ON_WARNINGS" -eq 1 ]] && jq -e '
    any(
        .repos[]?.prs[]?;
        (.process.warnings | length) > 0
    )
' "$ENRICHED_JSON" >/dev/null; then
    exit 3
fi
