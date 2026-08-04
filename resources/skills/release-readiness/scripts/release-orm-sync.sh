#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

info "Synchronising ORM schema in release clone..."

(
    cd "$RELEASE_ROOT"
    "$RELEASE_ROOT/bin/semitexa" orm:sync
)

ok "ORM schema is synchronised"
