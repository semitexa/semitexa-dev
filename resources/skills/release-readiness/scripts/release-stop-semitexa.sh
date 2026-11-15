#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd docker

info "Stopping Semitexa release and dev Docker stacks..."

if [ -f "$RELEASE_ROOT/docker-compose.yml" ]; then
    (
        cd "$RELEASE_ROOT"
        "$RELEASE_ROOT/bin/semitexa" server:stop >/dev/null 2>&1 || true
    )
fi

# The clone binds the same host ports as the dev stack, so dev has to go down for
# the run. Remember that it was up: release-finalize.sh brings it back, because a
# release that leaves the workspace's app stopped was seen twice on 2026-11-12.
# A second stop in the same session keeps the first answer (dev is already down).
DEV_MARKER="${RELEASE_STATE_DIR:-/tmp}/dev-stack-was-running"
if [ ! -e "$DEV_MARKER" ] && docker ps --format '{{.Names}}' | grep -qE '^semitexadev-'; then
    mkdir -p "$(dirname "$DEV_MARKER")"
    : >"$DEV_MARKER"
fi

mapfile -t names < <(docker ps --format '{{.Names}}' | grep -E '^semitexarls-|^semitexadev-' || true)
if [ "${#names[@]}" -gt 0 ]; then
    docker stop "${names[@]}" >/dev/null
fi

ok "Release and dev Semitexa stacks stopped"
