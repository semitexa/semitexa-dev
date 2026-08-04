#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd git
assert_release_root

info "Ensuring release package repo set matches $DEV_ROOT/packages..."

for source_repo in "$DEV_ROOT"/packages/*; do
    [ -d "$source_repo/.git" ] || continue
    [ -f "$source_repo/composer.json" ] || continue

    repo_name="$(project_name "$source_repo")"
    target_repo="$RELEASE_ROOT/packages/$repo_name"

    if [ -e "$target_repo" ] && [ ! -d "$target_repo/.git" ]; then
        fail "Expected $target_repo to be a git repo"
    fi

    [ -d "$target_repo/.git" ] && continue

    remote_url="$(git -C "$source_repo" remote get-url origin 2>/dev/null || true)"
    [ -n "$remote_url" ] || fail "Missing origin remote for $source_repo"

    info "Cloning missing release repo: $repo_name"
    git clone "$remote_url" "$target_repo" >/dev/null 2>&1 || fail "Failed to clone $repo_name into $target_repo"
    git -C "$target_repo" checkout -B master origin/master >/dev/null 2>&1 || \
        fail "Failed to align $repo_name to origin/master after clone"

    ok "$repo_name: cloned into release set"
done

info "Refreshing package repos inside $RELEASE_ROOT/packages via git pull only..."

ensure_release_state_dir
: >"$RELEASE_DIVERGENCE_FILE"

for repo in "$RELEASE_ROOT"/packages/*; do
    [ -d "$repo/.git" ] || continue

    repo_name="$(project_name "$repo")"
    info "Repo: $repo_name"

    if [ -n "$(git -C "$repo" status --porcelain)" ]; then
        git -C "$repo" status --short >&2 || true
        fail "Dirty worktree in $repo"
    fi

    branch="$(git -C "$repo" rev-parse --abbrev-ref HEAD)"
    [ "$branch" != "HEAD" ] || fail "Detached HEAD in $repo"
    [ "$branch" = "master" ] || fail "Expected master branch in $repo, got $branch"

    git -C "$repo" pull --ff-only origin master >/dev/null

    current_sha="$(git -C "$repo" rev-parse --short HEAD)"
    ok "$repo_name: master -> $current_sha"

    # Surface develop→master divergence so finalize can't silently no-op.
    # Releases are tag-driven from master; if develop has unmerged commits the
    # finalize tagger sees an already-tagged master HEAD and skips the package.
    if git -C "$repo" fetch --quiet origin develop 2>/dev/null; then
        ahead="$(git -C "$repo" rev-list --count master..origin/develop 2>/dev/null || printf '0')"
        if [ "${ahead:-0}" -gt 0 ] 2>/dev/null; then
            develop_sha="$(git -C "$repo" rev-parse --short origin/develop 2>/dev/null || printf 'unknown')"
            warn "$repo_name: develop is $ahead commit(s) ahead of master (develop @ $develop_sha, master @ $current_sha). Merge develop→master (GitHub PR or /review-prep) before finalize, or finalize will be a no-op for this package."
            printf '%s\t%s\t%s\t%s\n' "$repo_name" "$ahead" "$develop_sha" "$current_sha" >>"$RELEASE_DIVERGENCE_FILE"
        fi
    fi
done
