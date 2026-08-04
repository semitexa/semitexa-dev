#!/usr/bin/env bash
set -euo pipefail

repo="${1:-}"
checks_file="${2:-}"
base_branch="${3:-}"

[ -n "$repo" ] || { printf 'Usage: %s /absolute/path/to/repo [checks-file] [base-branch]\n' "$0" >&2; exit 1; }

if [ -z "$base_branch" ]; then
    for candidate in develop master main; do
        if git -C "$repo" show-ref --verify --quiet "refs/remotes/origin/$candidate"; then
            base_branch="$candidate"
            break
        fi
    done
fi

[ -n "$base_branch" ] || { printf 'Could not determine PR base branch for %s\n' "$repo" >&2; exit 1; }

branch="$(git -C "$repo" rev-parse --abbrev-ref HEAD)"
commit_count="$(git -C "$repo" rev-list --count "origin/$base_branch..HEAD" 2>/dev/null || printf '0')"
file_count="$(git -C "$repo" diff --name-only "origin/$base_branch...HEAD" 2>/dev/null | sed '/^$/d' | wc -l | tr -d ' ')"

printf '## Summary\n'
printf -- '- Review-ready branch `%s` targeting `%s`\n' "$branch" "$base_branch"
printf -- '- `%s` commit(s) ahead, `%s` changed file(s)\n\n' "$commit_count" "$file_count"

printf '## Changes\n'
log_output="$(git -C "$repo" log --format='- %s' "origin/$base_branch..HEAD" 2>/dev/null || true)"
if [ -n "$log_output" ]; then
    printf '%s\n\n' "$log_output"
else
    printf -- '- Diff summary unavailable\n\n'
fi

printf '## Checks\n'
if [ -n "$checks_file" ] && [ -f "$checks_file" ] && [ -s "$checks_file" ]; then
    cat "$checks_file"
    printf '\n'
else
    printf -- '- [ ] No recorded checks summary was provided\n\n'
fi

printf '## What to Review\n'
focus_output="$(git -C "$repo" diff --name-only "origin/$base_branch...HEAD" 2>/dev/null | awk -F/ 'NF==1 {print $1} NF>1 {print $1 "/" $2}' | awk '!seen[$0]++' | sed 's#^#- `#; s#$#`#' | sed -n '1,8p')"
if [ -n "$focus_output" ]; then
    printf '%s\n\n' "$focus_output"
else
    printf -- '- Review the changed files in this branch\n\n'
fi

printf '## What to Verify\n'
if [ -n "$checks_file" ] && grep -q 'test:e2e' "$checks_file" 2>/dev/null; then
    printf -- '- Re-run the browser smoke path if the touched flow is UI-facing\n'
fi
if [ -n "$checks_file" ] && grep -q 'composer test' "$checks_file" 2>/dev/null; then
    printf -- '- Confirm the changed PHP flow still matches the covered tests\n'
fi
printf -- '- Smoke-check the main behavior changed by this branch\n'
printf -- '- Watch logs or console output for regressions in touched paths\n'
