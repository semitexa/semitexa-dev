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

# One listing decides both what is stopped and whether dev is remembered: a dev
# container that started between two listings was stopped and never restarted
# (review, semitexa-dev#264). A listing that fails stops the run.
running="$(docker ps --format '{{.Names}}')" || fail "docker ps failed: the Semitexa stacks were not stopped"
mapfile -t names < <(printf '%s\n' "$running" | grep -E '^semitexarls-|^semitexadev-' || true)
if [ ! -e "$DEV_MARKER" ] && printf '%s\n' "${names[@]}" | grep -qE '^semitexadev-'; then
    mkdir -p "$(dirname "$DEV_MARKER")"
    : >"$DEV_MARKER"
fi

if [ "${#names[@]}" -gt 0 ]; then
    docker stop "${names[@]}" >/dev/null
fi

ok "Release and dev Semitexa stacks stopped"
