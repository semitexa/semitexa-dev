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

mapfile -t names < <(docker ps --format '{{.Names}}' | grep -E '^semitexarls-|^semitexadev-' || true)
if [ "${#names[@]}" -gt 0 ]; then
    docker stop "${names[@]}" >/dev/null
fi

ok "Release and dev Semitexa stacks stopped"
