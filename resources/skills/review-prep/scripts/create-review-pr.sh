#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo=""
checks_file=""
base_branch=""
title=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --checks-file)
            checks_file="${2:-}"
            shift 2
            ;;
        --base)
            base_branch="${2:-}"
            shift 2
            ;;
        --title)
            title="${2:-}"
            shift 2
            ;;
        *)
            if [ -z "$repo" ]; then
                repo="$1"
            else
                printf 'Unexpected argument: %s\n' "$1" >&2
                exit 1
            fi
            shift
            ;;
    esac
done

[ -n "$repo" ] || { printf 'Usage: %s /absolute/path/to/repo [--checks-file /tmp/file] [--base branch] [--title title]\n' "$0" >&2; exit 1; }

branch="$(git -C "$repo" rev-parse --abbrev-ref HEAD)"
case "$branch" in
    master|main)
        printf 'Refusing to create a PR from protected branch: %s\n' "$branch" >&2
        exit 1
        ;;
esac

if [ -z "$base_branch" ]; then
    if [ "$branch" = "develop" ]; then
        for candidate in master main; do
            if git -C "$repo" show-ref --verify --quiet "refs/remotes/origin/$candidate"; then
                base_branch="$candidate"
                break
            fi
        done
    else
        for candidate in develop master main; do
            if git -C "$repo" show-ref --verify --quiet "refs/remotes/origin/$candidate"; then
                base_branch="$candidate"
                break
            fi
        done
    fi
fi

[ -n "$base_branch" ] || { printf 'Could not determine base branch for %s\n' "$repo" >&2; exit 1; }

repo_slug="$(gh repo view --json nameWithOwner --jq '.nameWithOwner' --repo "$(git -C "$repo" remote get-url origin)" 2>/dev/null || true)"
if [ -z "$repo_slug" ]; then
    remote_url="$(git -C "$repo" remote get-url origin)"
    repo_slug="$(printf '%s' "$remote_url" | sed -E 's#.*github.com[:/]([^/]+/[^/.]+)(\.git)?#\1#')"
fi

[ -n "$repo_slug" ] || { printf 'Could not determine GitHub repo slug for %s\n' "$repo" >&2; exit 1; }

if [ -z "$title" ]; then
    title="$(python3 "$script_dir/generate-review-summary.py" "$repo" | python3 -c 'import json,sys; print(json.load(sys.stdin)["pr_title"])')"
fi

body_file="$(mktemp)"
trap 'rm -f "$body_file"' EXIT
"$script_dir/render-pr-body.sh" "$repo" "$checks_file" "$base_branch" > "$body_file"

git -C "$repo" push -u origin HEAD

existing_pr="$(gh pr list --repo "$repo_slug" --head "$branch" --base "$base_branch" --state open --json number --jq '.[0].number' 2>/dev/null || true)"
if [ -n "$existing_pr" ] && [ "$existing_pr" != "null" ]; then
    # REST rather than `gh pr edit`: on orgs that still carry Projects (classic),
    # gh's edit path issues a GraphQL query touching projectCards and fails with
    # a deprecation error even though the edit itself is valid.
    python3 - "$repo_slug" "$existing_pr" "$title" "$body_file" <<'PY'
import json, subprocess, sys
slug, number, title, body_file = sys.argv[1:5]
payload = {"title": title, "body": open(body_file).read()}
p = subprocess.run(
    ["gh", "api", "-X", "PATCH", f"repos/{slug}/pulls/{number}", "--input", "-", "--jq", ".html_url"],
    input=json.dumps(payload), capture_output=True, text=True,
)
sys.stdout.write(p.stdout or p.stderr)
sys.exit(p.returncode)
PY
    printf 'Updated PR #%s for %s\n' "$existing_pr" "$repo_slug"
    pr_number="$existing_pr"
    created=0
else
    pr_url="$(gh pr create --repo "$repo_slug" --base "$base_branch" --head "$branch" --title "$title" --body-file "$body_file")"
    printf '%s\n' "$pr_url"
    pr_number="${pr_url##*/}"
    created=1
fi

# CodeRabbit review request — the one that actually reviews these PRs.
#
# MEASURED across a full working session on this ecosystem's only merge path,
# develop -> master:
#
#   * "Review skipped — auto reviews are disabled on base/target branches other
#     than the default branch." Every PR here has a non-default base, so NONE of
#     them is reviewed unless a human asks. The largest PR of that session sat
#     unreviewed until triggered by hand and then returned six findings, two of
#     them data loss.
#   * "Already reviewed the last commit" came back on PRs that had received NEW
#     commits since the previous pass, so an updated PR needs `full review`
#     rather than `review` to cover the commits added after it.
#
# The failure mode is the dangerous kind: "no findings" and "never looked" are
# the same silence. Asking costs one comment.
#
# Set SEMITEXA_PR_CODERABBIT=0 to skip. Only that exact value skips: matching
# on "= 1" instead would let SEMITEXA_PR_CODERABBIT=true silently disable the
# request, and a review trigger that turns itself off without saying so is the
# failure this block exists to prevent.
if [ "${SEMITEXA_PR_CODERABBIT:-1}" != "0" ] && [ -n "${pr_number:-}" ]; then
    if [ "$created" -eq 1 ]; then
        coderabbit_command="@coderabbitai review"
    else
        # An updated PR carries commits a previous pass did not see, and the
        # incremental reviewer declines to look at them on a plain `review`.
        coderabbit_command="@coderabbitai full review"
    fi

    if gh pr comment "$pr_number" --repo "$repo_slug" --body "$coderabbit_command" >/dev/null 2>&1; then
        printf 'Asked CodeRabbit for a review on #%s (%s)\n' "$pr_number" "$coderabbit_command"
    else
        # Loud, not silent: an unreviewed PR that nobody knows is unreviewed is
        # exactly what this block exists to prevent.
        printf 'WARNING: could not ask CodeRabbit to review #%s — this PR may go unreviewed\n' "$pr_number" >&2
    fi
fi

# Cursor Bugbot review request.
#
# Once the Cursor GitHub App is installed and the repository is enabled in the
# Bugbot dashboard, Bugbot reviews every PR update on its own — this comment is
# only needed when it is configured to run "only when mentioned", and is
# otherwise one harmless line. It cannot install or enable anything; if Cursor
# is not on the org the comment simply sits there unanswered.
#
# Set SEMITEXA_PR_BUGBOT=0 to skip — same contract as the block above, and it
# had the same mismatch between the documented opt-out and the code.
if [ "${SEMITEXA_PR_BUGBOT:-1}" != "0" ] && [ "$created" -eq 1 ] && [ -n "${pr_number:-}" ]; then
    if gh pr comment "$pr_number" --repo "$repo_slug" --body "bugbot run" >/dev/null 2>&1; then
        printf 'Requested a Bugbot review on #%s\n' "$pr_number"
    else
        printf 'Could not post the Bugbot trigger on #%s\n' "$pr_number" >&2
    fi
fi
