#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd git
assert_release_root

info "Refreshing local ${RELEASE_PREPARE_BRANCH} branches only inside the release clone repo set under $RELEASE_ROOT..."

while IFS= read -r repo; do
    [ -n "$repo" ] || continue
    info "Repo: $(project_name "$repo")"

    if [ -n "$(git -C "$repo" status --porcelain)" ]; then
        git -C "$repo" status --short >&2 || true
        fail "Dirty worktree in $repo"
    fi

    git -C "$repo" fetch origin --prune

    if ! git -C "$repo" show-ref --verify --quiet "refs/remotes/origin/${RELEASE_PREPARE_BRANCH}"; then
        if git -C "$repo" show-ref --verify --quiet "refs/remotes/origin/${RELEASE_TARGET_BRANCH}"; then
            warn "origin/${RELEASE_PREPARE_BRANCH} is missing in $repo; using origin/${RELEASE_TARGET_BRANCH} as the local ${RELEASE_PREPARE_BRANCH} base"
            git -C "$repo" branch -f "$RELEASE_PREPARE_BRANCH" "refs/remotes/origin/${RELEASE_TARGET_BRANCH}" >/dev/null
            ok "$(project_name "$repo"): ${RELEASE_PREPARE_BRANCH} -> origin/${RELEASE_TARGET_BRANCH} (fallback)"
            continue
        fi
        fail "origin/${RELEASE_PREPARE_BRANCH} is missing in $repo"
    fi

    current_branch="$(git -C "$repo" rev-parse --abbrev-ref HEAD)"
    if [ "$current_branch" = "$RELEASE_PREPARE_BRANCH" ]; then
        git -C "$repo" reset --hard "refs/remotes/origin/${RELEASE_PREPARE_BRANCH}" >/dev/null
    else
        git -C "$repo" branch -f "$RELEASE_PREPARE_BRANCH" "refs/remotes/origin/${RELEASE_PREPARE_BRANCH}" >/dev/null
    fi

    ok "$(project_name "$repo"): ${RELEASE_PREPARE_BRANCH} -> origin/${RELEASE_PREPARE_BRANCH}"
done < <(list_release_repos)
