#!/usr/bin/env bash
set -euo pipefail

package_dir="${1:?package path is required}"

if [[ ! -d "$package_dir/.git" ]]; then
    echo "blocked: $package_dir is not a git repository" >&2
    exit 2
fi

if [[ -n "$(git -C "$package_dir" status --porcelain)" ]]; then
    echo "blocked: $package_dir has uncommitted changes" >&2
    exit 3
fi

current_branch="$(git -C "$package_dir" rev-parse --abbrev-ref HEAD)"
if [[ "$current_branch" == "develop" ]]; then
    echo "ok: $package_dir already on develop"
    exit 0
fi

if git -C "$package_dir" show-ref --verify --quiet refs/heads/develop; then
    git -C "$package_dir" checkout develop
else
    base_branch="master"
    if ! git -C "$package_dir" show-ref --verify --quiet "refs/heads/$base_branch"; then
        base_branch="$(git -C "$package_dir" symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null | sed 's#^origin/##' || true)"
    fi
    if [[ -z "$base_branch" ]]; then
        echo "blocked: $package_dir has no master or detectable default branch" >&2
        exit 4
    fi
    git -C "$package_dir" checkout -b develop "$base_branch"
fi

if git -C "$package_dir" remote get-url origin >/dev/null 2>&1; then
    if ! git -C "$package_dir" ls-remote --exit-code --heads origin develop >/dev/null 2>&1; then
        git -C "$package_dir" push -u origin develop
    fi
fi

echo "fixed: $package_dir now on develop"
