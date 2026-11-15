#!/usr/bin/env bash
set -euo pipefail

# Undo what release-stop-semitexa.sh did to the framework workspace: if its dev
# stack was running when the release began, take the release clone down (they
# share host ports) and start the dev stack again. Does nothing when dev was not
# running, so a run on a machine without a dev stack stays as it was.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

DEV_MARKER="$RELEASE_STATE_DIR/dev-stack-was-running"
[ -e "$DEV_MARKER" ] || exit 0

info "Restoring the dev stack the release stopped..."
if [ -f "$RELEASE_ROOT/docker-compose.yml" ]; then
    (cd "$RELEASE_ROOT" && "$RELEASE_ROOT/bin/semitexa" server:stop >/dev/null 2>&1) || true
fi
(cd "$DEV_ROOT" && "$DEV_ROOT/bin/semitexa" server:start)
rm -f "$DEV_MARKER"
ok "Dev stack is running again"
