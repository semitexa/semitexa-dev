#!/usr/bin/env bash
set -euo pipefail

package_dir="${1:?package path is required}"
repo_name="${2:?repo name is required}"
description="${3:?repo description is required}"
topics_csv="${4:-}"
visibility="${VISIBILITY:-public}"
homepage_url="${HOMEPAGE_URL:-https://semitexa.com}"
repo_slug=""
created_repo="no"

if [[ ! -d "$package_dir/.git" ]]; then
    echo "blocked: $package_dir is not a git repository" >&2
    exit 2
fi

remote_url=""
if git -C "$package_dir" remote get-url origin >/dev/null 2>&1; then
    remote_url="$(git -C "$package_dir" remote get-url origin)"
    repo_slug="$(printf '%s' "$remote_url" | sed -E 's#.*github.com[:/]([^/]+/[^/.]+)(\.git)?#\1#')"
fi

if [[ -z "$repo_slug" ]]; then
    repo_slug="semitexa/$repo_name"
    gh repo create "$repo_slug" \
        "--$visibility" \
        --source "$package_dir" \
        --description "$description" \
        --homepage "$homepage_url" \
        --remote origin
    created_repo="yes"
fi

gh repo edit "$repo_slug" \
    --description "$description" \
    --homepage "$homepage_url"

if [[ -n "$topics_csv" ]]; then
    IFS=',' read -r -a topics <<< "$topics_csv"
    topic_args=()
    for topic in "${topics[@]}"; do
        topic="${topic// /}"
        [[ -z "$topic" ]] && continue
        topic_args+=("-f" "names[]=$topic")
    done
    if [[ ${#topic_args[@]} -gt 0 ]]; then
        gh api \
            --method PUT \
            -H "Accept: application/vnd.github+json" \
            "/repos/$repo_slug/topics" \
            "${topic_args[@]}" >/dev/null
    fi
fi

if [[ "$created_repo" == "yes" ]]; then
    echo "provisioned: created $repo_slug, configured origin, and synchronized GitHub metadata"
else
    echo "provisioned: synchronized GitHub metadata for $repo_slug"
fi
