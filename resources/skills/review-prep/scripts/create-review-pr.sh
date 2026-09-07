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
# CodeRabbit review nudge — narrowed 2026-09-07 to the case that still needs it.
#
# This used to fire on EVERY pull request. The reasoning was sound when written:
# auto reviews were disabled on base branches other than the default, every PR
# here targets master from develop, and so none was reviewed unless a human
# asked. "No findings" and "never looked" are the same silence.
#
# That stopped being true. MEASURED 2026-09-06: thirteen PRs opened with this
# trigger suppressed were auto-reviewed anyway, within a minute, two of them
# with inline findings. So on a NEWLY OPENED PR the comment adds nothing — and
# it is not free. It is a command, and commands are rate limited separately from
# automatic reviews: "Review rate limited. Note: CodeRabbit is an incremental
# review system... This command is applicable only when automatic reviews are
# paused." Every PR opened in this session drew that reply, spending quota to
# ask for a review that was already running.
#
# The UPDATED-PR case is different and is kept. An incremental reviewer declines
# to re-read commits it has already passed over, so a PR that gained commits
# needs `full review` rather than `review`. Nothing has been measured to show
# that a plain push triggers a fresh pass on its own, and there is live evidence
# the other way: commit 2b15989 pushed to semitexa-dev#69 sat unreviewed.
#
# Set SEMITEXA_PR_CODERABBIT=0 to skip. Only that exact value skips: matching on
# "= 1" instead would let SEMITEXA_PR_CODERABBIT=true silently disable the
# request, and a review trigger that turns itself off without saying so is the
# failure this block exists to prevent.
if [ "${SEMITEXA_PR_CODERABBIT:-1}" != "0" ] && [ -n "${pr_number:-}" ] && [ "$created" -eq 0 ]; then
    if gh pr comment "$pr_number" --repo "$repo_slug" --body "@coderabbitai full review" >/dev/null 2>&1; then
        printf 'Asked CodeRabbit to re-review the new commits on #%s\n' "$pr_number"
    else
        # Loud, not silent: an unreviewed update that nobody knows is unreviewed
        # is exactly what this block exists to prevent.
        printf 'WARNING: could not ask CodeRabbit to re-review #%s — its new commits may go unreviewed\n' "$pr_number" >&2
    fi
fi

# The Cursor Bugbot trigger used to be posted here. Removed 2026-09-07.
#
# It rested on an assumption that stopped being true: "if Cursor is not on the
# org the comment simply sits there unanswered", i.e. one harmless line. Cursor
# IS on the semitexa org now, and the trigger is answered every time —
# MEASURED on semitexa-dev#69, two seconds after posting:
#
#   cursor[bot]: Skipping Bugbot: Bugbot is disabled for this repository.
#
# Bugbot is enabled per repository, and no repository is enabled: its dashboard
# reports 0/0 for every organisation, including two connected long before this.
# So the line no longer sat quietly unanswered — it guaranteed a reply on every
# single PR saying nothing was reviewed. Noise on a review channel is not
# neutral: it is how the findings that matter stop being read.
#
# Do not restore this without first confirming a repository is actually enabled
# in the Bugbot dashboard. Posting a trigger nothing acts on is worse than
# posting none.
