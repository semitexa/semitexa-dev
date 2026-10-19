#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd php
load_release_session

CURRENT_STAGE="release-tag-master"

on_exit() {
    local status=$?
    # After the cut the release exists: a published-install verdict is added
    # to the success report, never written over it.
    if [ "$status" -ne 0 ] && [ "$CURRENT_STAGE" != "published-install" ]; then
        write_failure_report "$CURRENT_STAGE" "$(report_failure_reason)"
        printf '
RELEASE READINESS: NOT READY
'
    fi
}

trap on_exit EXIT

info "Tagging untagged package master heads with the UTC release version ${RELEASE_VERSION} (${RELEASE_CHANNEL})..."
RELEASE_ROOT="$RELEASE_ROOT" php "$SCRIPT_DIR/bump-packages.php" --yes --summary-json "$RELEASE_SUMMARY_FILE"

# The cut is done: close the session so the next preflight mints a fresh
# version instead of handing back one that is already tagged.
mark_release_finalized

write_success_report

# Did it reach people? The ultimate tag starts a clean-runner install from
# public sources (semitexa-ultimate published-install.yml); wait for its verdict.
CURRENT_STAGE="published-install"
published_status=0
"$SCRIPT_DIR/release-published-install.sh" || published_status=$?

if [ "$published_status" -eq 1 ]; then
    printf '
RELEASE READINESS: RELEASED, BUT NEW INSTALLS FAIL — see the Published install section of the report
'
    exit 1
fi
if [ "$published_status" -ne 0 ]; then
    printf '
RELEASE READINESS: RELEASED, published install UNVERIFIED — check the run before calling it done
'
    exit 2
fi

printf '
RELEASE READINESS: READY
'
