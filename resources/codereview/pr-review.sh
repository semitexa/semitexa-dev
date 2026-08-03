#!/usr/bin/env bash
# Collect open PR review state across Semitexa package repositories.
# Default output: enriched JSON with raw data + actionable summaries.
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: pr-review.sh [options]

Options:
  --repo <pkg-or-slug>     Limit to one repo (e.g. semitexa-platform-wm or semitexa/platform-wm)
  --pr <number>            Limit to one PR number
  --compact                Output compact/actionable JSON without raw diff/comments
  --actionable-only        Output only repos/PRs that still have actionable review comments
  --no-diff                Skip PR diff fetch
  --help                   Show this help

Environment:
  SEMITEXA_REVIEW_ROOT     Absolute path to the Semitexa project root

Examples:
  pr-review.sh
  pr-review.sh --compact
  pr-review.sh --repo semitexa/platform-user --actionable-only
  pr-review.sh --repo semitexa-platform-wm --pr 5 --compact
USAGE
}

resolve_root_dir() {
    if [[ -n "${SEMITEXA_REVIEW_ROOT:-}" ]]; then
        printf '%s\n' "$SEMITEXA_REVIEW_ROOT"
        return 0
    fi

    local candidate="$PWD"
    while true; do
        if [[ -d "$candidate/packages" || -d "$candidate/pakages" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi

        if [[ "$candidate" == "/" ]]; then
            break
        fi

        candidate="$(dirname "$candidate")"
    done

    echo "Could not locate Semitexa project root from current directory. Run from the project root or set SEMITEXA_REVIEW_ROOT." >&2
    exit 1
}

REPO_FILTER=""
PR_FILTER=""
COMPACT=0
ACTIONABLE_ONLY=0
INCLUDE_DIFF=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        --repo)
            REPO_FILTER="${2:-}"
            shift 2
            ;;
        --pr)
            PR_FILTER="${2:-}"
            shift 2
            ;;
        --compact)
            COMPACT=1
            shift
            ;;
        --actionable-only)
            ACTIONABLE_ONLY=1
            shift
            ;;
        --no-diff)
            INCLUDE_DIFF=0
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

if [[ -n "$PR_FILTER" && ! "$PR_FILTER" =~ ^[0-9]+$ ]]; then
    echo "--pr expects a numeric PR number" >&2
    exit 1
fi

ROOT_DIR="$(resolve_root_dir)"
if [[ -d "$ROOT_DIR/packages" ]]; then
    PACKAGES_DIR="$ROOT_DIR/packages"
elif [[ -d "$ROOT_DIR/pakages" ]]; then
    PACKAGES_DIR="$ROOT_DIR/pakages"
else
    echo "Expected packages/ or pakages/ directory under $ROOT_DIR" >&2
    exit 1
fi
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

RESULT_JSON="$TMPDIR/result.json"
echo '{"repos":[]}' > "$RESULT_JSON"

repo_matches_filter() {
    local pkg_name="$1"
    local repo_slug="$2"
    if [[ -z "$REPO_FILTER" ]]; then
        return 0
    fi
    [[ "$REPO_FILTER" == "$pkg_name" || "$REPO_FILTER" == "$repo_slug" ]]
}

for dir in "$PACKAGES_DIR"/*/; do
    [[ -d "$dir/.git" ]] || continue

    remote_url="$(git -C "$dir" remote get-url origin 2>/dev/null || true)"

    if [[ "$remote_url" != *"github.com:semitexa/"* && "$remote_url" != *"github.com/semitexa/"* ]]; then
        continue
    fi

    repo_slug="$(echo "$remote_url" | sed -E 's#.*github\.com[:/]##; s/\.git$//')"
    pkg_name="$(basename "$dir")"
    repo_path="${dir%/}"
    local_branch="$(git -C "$repo_path" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")"
    worktree_dirty=0
    if [[ -n "$(git -C "$repo_path" status --short 2>/dev/null || true)" ]]; then
        worktree_dirty=1
    fi

    if ! repo_matches_filter "$pkg_name" "$repo_slug"; then
        continue
    fi

    prs_json="$(gh pr list \
        --repo "$repo_slug" \
        --state open \
        --json number,title,author,baseRefName,headRefName,url,createdAt,updatedAt,body,labels,reviewDecision \
        2>/dev/null || echo '[]')"

    if [[ -n "$PR_FILTER" ]]; then
        prs_json="$(echo "$prs_json" | jq --argjson pr "$PR_FILTER" '[.[] | select(.number == $pr)]')"
    fi

    pr_count="$(echo "$prs_json" | jq 'length')"
    [[ "$pr_count" -eq 0 ]] && continue

    jq -n \
        --arg name "$pkg_name" \
        --arg slug "$repo_slug" \
        --arg path "$repo_path" \
        --arg branch "$local_branch" \
        --argjson dirty "$worktree_dirty" \
        '{
            name: $name,
            slug: $slug,
            path: $path,
            localBranch: $branch,
            worktreeDirty: $dirty,
            prs: []
        }' > "$TMPDIR/repo.json"

    while IFS= read -r pr_number; do
        echo "$prs_json" | jq ".[] | select(.number == $pr_number)" > "$TMPDIR/pr_meta.json"
        gh pr view "$pr_number" --repo "$repo_slug" --json mergeable,isDraft 2>/dev/null > "$TMPDIR/pr_extra.json" || echo '{}' > "$TMPDIR/pr_extra.json"

        if [[ "$INCLUDE_DIFF" -eq 1 ]]; then
            gh api "repos/$repo_slug/pulls/$pr_number" -H "Accept: application/vnd.github.v3.diff" > "$TMPDIR/diff.txt" 2>/dev/null || : > "$TMPDIR/diff.txt"
        else
            : > "$TMPDIR/diff.txt"
        fi

        gh api "repos/$repo_slug/pulls/$pr_number/comments" --paginate 2>/dev/null \
            | jq '[.[] | {
                id,
                user: .user.login,
                body,
                path,
                line: .original_line,
                side,
                createdAt: .created_at,
                updatedAt: .updated_at,
                inReplyToId: .in_reply_to_id,
                commitId: .commit_id,
                originalCommitId: .original_commit_id,
                htmlUrl: .html_url
            }]' > "$TMPDIR/rc.json" 2>/dev/null || echo '[]' > "$TMPDIR/rc.json"

        gh api "repos/$repo_slug/issues/$pr_number/comments" --paginate 2>/dev/null \
            | jq '[.[] | {
                id,
                user: .user.login,
                body,
                createdAt: .created_at,
                updatedAt: .updated_at,
                htmlUrl: .html_url
            }]' > "$TMPDIR/ic.json" 2>/dev/null || echo '[]' > "$TMPDIR/ic.json"

        gh api "repos/$repo_slug/pulls/$pr_number/reviews" --paginate 2>/dev/null \
            | jq '[.[] | {
                id,
                user: .user.login,
                state,
                body,
                submittedAt: .submitted_at,
                commitId: .commit_id,
                htmlUrl: .html_url
            }]' > "$TMPDIR/rv.json" 2>/dev/null || echo '[]' > "$TMPDIR/rv.json"

        jq --rawfile diff "$TMPDIR/diff.txt" \
           --slurpfile rc "$TMPDIR/rc.json" \
           --slurpfile ic "$TMPDIR/ic.json" \
           --slurpfile rv "$TMPDIR/rv.json" \
           --slurpfile extra "$TMPDIR/pr_extra.json" '
            def text: (.body // "");
            # Tight review-signal pattern. Bare words like "medium" or "major"
            # appear in unrelated places (URL params, file paths, commit
            # messages) and used to misclassify auto-generated walkthroughs as
            # actionable. Multi-word phrases drop \b boundaries because
            # CodeRabbit wraps them in markdown italics (`_Potential issue_`),
            # and `_` is a word character — so the trailing \b fails to match.
            def review_signal:
                text | test("(?i)potential issue|bug risk|security (issue|concern|risk|problem|vulnerability)|performance (issue|concern|risk|problem|regression)|correctness (issue|concern|bug)|(major|medium|high|critical|low) (severity|priority|issue|concern)|blocking issue|\\bblocker\\b|must change|action required|refactor suggestion|nitpick (suggestion|comment)|functional correctness|quick win|logic error|data (loss|corruption)|resource leak|_(minor|moderate|major|critical)_|[🔴🟠🟡🎯⚡] ?(minor|moderate|major|critical|quick win|functional correctness)");
            # Strict severity marker — Codex/CodeRabbit P-badge image or
            # explicit P1/P2/P3 token. Used to decide whether a review-summary
            # body is a real finding versus a wrapper.
            def severity_marker:
                (text | test("Badge\\]\\(https://img\\.shields\\.io"))
                or (text | test("(?i)\\bP[1-3]\\b"));
            # A review summary or issue comment looks substantive (not a
            # walkthrough/wrapper) when it carries a severity marker, an
            # inline keyword like "nitpick"/"refactor", or matches
            # review_signal keywords.
            def has_critique_marker:
                severity_marker
                or (text | test("(?i)\\b(nitpick|refactor|caution|warning|critical|blocking issue)\\b"))
                or review_signal;
            # The Codex review wrapper ("Here are some automated review
            # suggestions for this pull request") is noise on its own; only
            # surface when an inline severity marker is also present.
            def codex_wrapper_only:
                (text | test("(?i)automated review suggestions for this pull request"))
                and (severity_marker | not);
            # Noise that is noise regardless of where it appears. Deliberately
            # narrow: everything here is a wrapper, a status notice, or an
            # acknowledgement, identifiable without guessing at vocabulary.
            def hard_noise:
                (text == "")
                or (text | test("(?i)pull request overview|walkthrough|rate limit exceeded|review status"))
                or (text | test("(?i)usage limits for code reviews"))
                or (text | test("(?i)^\\*\\*actionable comments posted:"))
                or (text | test("(?i)^✅ Addressed"))
                or (text | test("(?i)^📝 Noted"))
                or (text | test("(?i)^👀 Valid point"));
            # An inline comment anchored to a specific line is targeted by
            # construction — a human or a bot chose that line on purpose. So it
            # is actionable unless it is hard_noise. It must NOT additionally
            # have to match a keyword whitelist: CodeRabbit changed its badge
            # vocabulary ("Functional Correctness / Minor / Quick win") and the
            # old predicate silently dropped every inline finding it made.
            def line_noise:
                hard_noise;
            # Review summaries and issue comments genuinely do contain
            # walkthroughs and wrappers, so those two kinds keep the stricter
            # test. Only line comments bypass it.
            def noise_comment:
                hard_noise
                or ((text | test("<!-- This is an auto-generated (comment|reply)"; "i")) and (review_signal | not))
                or codex_wrapper_only;
            . + {
                diff: $diff,
                reviewComments: $rc[0],
                issueComments: $ic[0],
                reviews: $rv[0],
                mergeable: ($extra[0].mergeable // "UNKNOWN"),
                isDraft: ($extra[0].isDraft // false)
            }
            | .author.login as $author
            # Author issue comments are the strongest acknowledgement signal
            # for issue-conversation items (any later author comment counts).
            # NB: parens around each comma operand are required — the comma
            # binds *below* `|` in jq, so the unparenthesized form mis-routes
            # selects across mixed shapes.
            | ([
                ((.issueComments // [])[] | select(.user == $author) | .createdAt)
              ] | map(select(. != null))) as $authorIssueCommentTimes
            # Author issue-comment bodies — used to detect explicit references
            # to a review by short SHA ("Fixed in 8a0950d", "addressed in caa141b…").
            | ([(.issueComments // [])[] | select(.user == $author) | (.body // "")]) as $authorIssueCommentBodies
            # Distinct commit_ids seen on non-author reviews (each new commit
            # by the author triggers a fresh bot review). If a review exists
            # with a later submittedAt against a different commit, the
            # earlier review target commit was superseded by an author push.
            | ([(.reviews // [])[] | select(.user != $author and (.commitId // "") != "")
                | {commitId, submittedAt}]) as $nonAuthorReviewCommits
            | .actionableLineComments = (
                .reviewComments as $all
                | [
                    $all[]
                    | select(.inReplyToId == null)
                    # The PR author is NOT excluded here. In this workflow the
                    # operator opens the PR and reviews it, so their own
                    # top-level line comments are the review. Excluding them
                    # made every operator instruction invisible to the queue.
                    # Author *replies* are still filtered out by the
                    # inReplyToId check above, which is what acknowledgements
                    # actually are.
                    | select(line_noise | not)
                    | . as $comment
                    | {
                        kind: "line",
                        id: $comment.id,
                        user: $comment.user,
                        body: $comment.body,
                        path: $comment.path,
                        line: $comment.line,
                        createdAt: $comment.createdAt,
                        htmlUrl: $comment.htmlUrl,
                        replies: [ $all[] | select(.inReplyToId == $comment.id) ],
                        repliedByAuthor: (([ $all[] | select(.inReplyToId == $comment.id and .user == $author) ] | length) > 0)
                    }
                  ]
            )
            | .actionableReviewBodies = (
                [
                    (.reviews // [])[]
                    | select(.user != $author)
                    | select(.state != "PENDING")
                    | select((.body // "") != "")
                    | select(noise_comment | not)
                    | select(has_critique_marker)
                    | . as $r
                    | ($r.commitId // "") as $rcommit
                    | (if $rcommit == "" then "" else $rcommit[0:7] end) as $rshort
                    # Resolved when:
                    #  (a) a later non-author review exists against a different
                    #      commit (the author pushed and the bot re-reviewed), OR
                    #  (b) an author issue-comment body explicitly mentions
                    #      the short SHA of the review-target commit.
                    | (any($nonAuthorReviewCommits[];
                            .submittedAt > $r.submittedAt
                            and (.commitId // "") != $rcommit)) as $supersededByLaterReview
                    | (
                        ($rshort | length) >= 7
                        and any($authorIssueCommentBodies[]; test($rshort; "i"))
                      ) as $referencedByAuthor
                    | {
                        kind: "review",
                        id: $r.id,
                        user: $r.user,
                        body: $r.body,
                        path: null,
                        line: null,
                        createdAt: $r.submittedAt,
                        htmlUrl: $r.htmlUrl,
                        commitId: $rcommit,
                        replies: [],
                        repliedByAuthor: ($supersededByLaterReview or $referencedByAuthor)
                    }
                ]
            )
            | .actionableIssueComments = (
                [
                    (.issueComments // [])[]
                    | select(.user != $author)
                    | select((.body // "") != "")
                    | select(noise_comment | not)
                    | select(has_critique_marker)
                    | . as $i
                    | {
                        kind: "issue",
                        id: $i.id,
                        user: $i.user,
                        body: $i.body,
                        path: null,
                        line: null,
                        createdAt: $i.createdAt,
                        htmlUrl: $i.htmlUrl,
                        replies: [],
                        repliedByAuthor: any($authorIssueCommentTimes[]; . > $i.createdAt)
                    }
                ]
            )
            | .actionableComments = (.actionableLineComments + .actionableReviewBodies + .actionableIssueComments)
            # Backwards-compat alias: older consumers expect this field with line-only entries.
            | .actionableReviewComments = .actionableLineComments
            | .summary = {
                reviewComments: (.reviewComments | length),
                issueComments: (.issueComments | length),
                reviews: (.reviews | length),
                actionableLineComments: (.actionableLineComments | length),
                actionableReviewBodies: (.actionableReviewBodies | length),
                actionableIssueComments: (.actionableIssueComments | length),
                actionableComments: (.actionableComments | length),
                unresolvedActionableComments: ([.actionableComments[] | select(.repliedByAuthor | not)] | length),
                actionableReviewComments: (.actionableLineComments | length),
                unresolvedActionableReviewComments: ([.actionableLineComments[] | select(.repliedByAuthor | not)] | length)
            }
           ' "$TMPDIR/pr_meta.json" > "$TMPDIR/pr.json"

        jq --slurpfile pr "$TMPDIR/pr.json" '.prs += $pr' "$TMPDIR/repo.json" > "$TMPDIR/repo2.json"
        mv "$TMPDIR/repo2.json" "$TMPDIR/repo.json"
    done < <(echo "$prs_json" | jq -r '.[].number')

    jq --slurpfile repo "$TMPDIR/repo.json" '.repos += $repo' "$RESULT_JSON" > "$TMPDIR/result2.json"
    mv "$TMPDIR/result2.json" "$RESULT_JSON"
done

FILTERED_JSON="$TMPDIR/final.json"
cp "$RESULT_JSON" "$FILTERED_JSON"

if [[ "$ACTIONABLE_ONLY" -eq 1 ]]; then
    jq '
        .repos |= map(
            .prs |= map(select(.summary.actionableComments > 0))
        )
        | .repos |= map(select((.prs | length) > 0))
    ' "$FILTERED_JSON" > "$TMPDIR/final2.json"
    mv "$TMPDIR/final2.json" "$FILTERED_JSON"
fi

if [[ "$COMPACT" -eq 1 ]]; then
    jq '
        def project_comment:
            {
                kind,
                id,
                user,
                path,
                line,
                createdAt,
                htmlUrl,
                repliedByAuthor,
                body
            };
        {
            repos: [
                .repos[]
                | {
                    name,
                    slug,
                    path,
                    localBranch,
                    worktreeDirty,
                    prs: [
                        .prs[]
                        | {
                            number,
                            title,
                            url,
                            author: .author.login,
                            baseRefName,
                            headRefName,
                            mergeable,
                            isDraft,
                            reviewDecision,
                            updatedAt,
                            summary,
                            actionableComments: [ .actionableComments[] | project_comment ],
                            actionableReviewComments: [ .actionableReviewComments[] | project_comment ]
                        }
                    ]
                }
            ]
        }
    ' "$FILTERED_JSON"
else
    jq . "$FILTERED_JSON"
fi
