#!/usr/bin/env bash
set -euo pipefail

# DOES WHAT WE SHIP STILL BOOT THE SITES THAT RUN ON IT.
#
# The private packages behind semitexa.com (site, os-site, platform-site) live
# outside this workspace, so no preflight stage and no stack smoke ever loaded
# them against a release candidate. MEASURED 2026-10-08: core began refusing an
# SSE feed without an explicit name; platform-site had one, so discovery dropped
# its route, orm:sync failed, and the semitexa.com auto-deploy rolled back on
# every run for three days. The site kept answering 200 on the old release, so
# nothing looked wrong.
#
# This stage runs the real AttributeDiscovery of each downstream project in a
# throwaway container of that project's own image: the project mounted read-only,
# every released package swapped for the release clone's copy, and the private
# packages checked out at origin/master — what the host's auto-deploy would pull.
# Reproduced against platform-site 2026.10.06.1023 before it was trusted: the
# stage fails with the exact error the host logged.
#
# Not covered: a package the candidate needs that the downstream's vendor/ lacks
# (a new composer dependency). That fails here as an autoload error — red, never
# a silent pass — and the fix is a composer update in the downstream project.
#
# SEMITEXA_DOWNSTREAM_ROOTS: space-separated project roots. Unset means the
# semitexa.portal checkout; an EMPTY value is the only way to skip the stage, so
# a missing checkout fails rather than passes.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd docker
require_cmd git

if [ "${SEMITEXA_DOWNSTREAM_ROOTS+set}" = set ] && [ -z "$SEMITEXA_DOWNSTREAM_ROOTS" ]; then
    warn "SEMITEXA_DOWNSTREAM_ROOTS is empty — downstream discovery deliberately skipped."
    exit 0
fi
roots="${SEMITEXA_DOWNSTREAM_ROOTS:-$(dirname "$RELEASE_ROOT")/semitexa.portal}"

work="$(mktemp -d)"
worktrees=()
cleanup() {
    local wt
    for wt in "${worktrees[@]}"; do
        git -C "${wt%%|*}" worktree remove --force "${wt#*|}" >/dev/null 2>&1 || true
    done
    rm -rf "$work"
}
trap cleanup EXIT

# Where a vendor/semitexa entry really lives inside the container. A symlinked
# entry points into the project's packages/ (../../packages/semitexa-x/); the
# bind must land on that directory, because one over the link is not followed.
mount_point() {
    local entry="$1" link
    if [ -L "$entry" ]; then
        link="$(readlink "$entry")"
        link="${link#../../}"
        printf '/var/www/html/%s' "${link%/}"
    else
        printf '/var/www/html/vendor/semitexa/%s' "$(basename "$entry")"
    fi
}

failed=0
for root in $roots; do
    if [ ! -f "$root/vendor/autoload.php" ]; then
        printf '✗ %s: no installed project (vendor/autoload.php missing) — cannot check, so not passing (SEMITEXA_DOWNSTREAM_ROOTS= skips deliberately)\n' "$root"
        failed=1
        continue
    fi

    project="$(basename "$root" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
    image="${SEMITEXA_DOWNSTREAM_IMAGE:-${project}-app}"
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        printf '✗ %s: image %s not found — build the project once (bin/semitexa server:start)\n' "$root" "$image"
        failed=1
        continue
    fi

    mounts=(-v "$root:/var/www/html:ro" --tmpfs /var/www/html/var)
    released=() private=()
    for entry in "$root"/vendor/semitexa/*; do
        name="$(basename "$entry")"
        if [ -d "$RELEASE_ROOT/packages/semitexa-$name" ]; then
            mounts+=(-v "$RELEASE_ROOT/packages/semitexa-$name:$(mount_point "$entry"):ro")
            released+=("$name")
        elif [ -L "$entry" ]; then
            src="$(cd "$entry" && pwd -P)"
            if ! git -C "$src" fetch -q origin master; then
                printf '✗ %s: cannot fetch origin/master of %s — not passing on an unchecked package\n' "$root" "$name"
                failed=1
                continue 2
            fi
            wt="$work/$project-$name"
            git -C "$src" worktree add -q --detach "$wt" origin/master
            worktrees+=("$src|$wt")
            mounts+=(-v "$wt:$(mount_point "$entry"):ro")
            private+=("$name@$(git -C "$wt" rev-parse --short HEAD)")
        fi
    done

    info "$project: released ${released[*]:-none}; private ${private[*]:-none}"
    if docker run --rm --network none "${mounts[@]}" \
        -v "$SCRIPT_DIR/release-downstream-discovery.php:/discover.php:ro" \
        "$image" php /discover.php; then
        ok "$project boots on the release candidate"
    else
        printf '✗ %s does not boot on the release candidate (output above)\n' "$project"
        failed=1
    fi
done

exit "$failed"
