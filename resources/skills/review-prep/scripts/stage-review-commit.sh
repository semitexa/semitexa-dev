#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo=""
status_file=""
message=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --status-file)
            status_file="${2:-}"
            shift 2
            ;;
        --message)
            message="${2:-}"
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

[ -n "$repo" ] || { printf 'Usage: %s /absolute/path/to/repo [--status-file /tmp/file] [--message message]\n' "$0" >&2; exit 1; }
[ -d "$repo/.git" ] || { printf 'Git repo not found: %s\n' "$repo" >&2; exit 1; }

branch="$(git -C "$repo" rev-parse --abbrev-ref HEAD)"
case "$branch" in
    master|main)
        printf 'Refusing to commit on protected branch: %s\n' "$branch" >&2
        exit 1
        ;;
esac

status="$(git -C "$repo" status --short)"
if [ -z "$status" ]; then
    printf 'No changes to prepare in %s\n' "$repo"
    exit 0
fi

printf '%s\n' "$status"
if [ -n "$status_file" ]; then
    printf '%s\n' "$status" > "$status_file"
fi

git -C "$repo" add -A

if git -C "$repo" diff --cached --quiet; then
    printf 'Nothing reviewable was staged in %s\n' "$repo" >&2
    exit 1
fi

if [ -z "$message" ]; then
    message="$(python3 "$script_dir/generate-review-summary.py" "$repo" | python3 -c 'import json,sys; print(json.load(sys.stdin)["commit_message"])')"
fi

git -C "$repo" commit -m "$message"
printf 'Created commit in %s: %s\n' "$repo" "$message"
