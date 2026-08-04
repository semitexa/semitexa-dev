#!/usr/bin/env bash
set -euo pipefail

if git_root="$(git rev-parse --show-toplevel 2>/dev/null)"; then
    printf '%s\n' "$git_root"
    exit 0
fi

workspace_root="${SEMITEXA_REVIEW_ROOT:-/home/taras/Documents/Projects/semitexa.dev}"
for repo in "$workspace_root"/packages/*; do
    [ -d "$repo/.git" ] || continue
    if [ -n "$(git -C "$repo" status --short 2>/dev/null)" ]; then
        printf '%s\n' "$repo"
    fi
done
