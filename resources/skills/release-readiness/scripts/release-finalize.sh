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
    if [ "$status" -ne 0 ]; then
        write_failure_report "$CURRENT_STAGE" "$(report_failure_reason)"
        printf '
RELEASE READINESS: NOT READY
'
    fi
}

trap on_exit EXIT

info "Tagging untagged package master heads with the UTC release version ${RELEASE_VERSION} (${RELEASE_CHANNEL})..."
RELEASE_ROOT="$RELEASE_ROOT" php "$SCRIPT_DIR/bump-packages.php" --yes --summary-json "$RELEASE_SUMMARY_FILE"

write_success_report

printf '
RELEASE READINESS: READY
'
