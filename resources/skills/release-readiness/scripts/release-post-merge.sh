#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd php
load_release_session

CURRENT_STAGE="release-master-recovery"

on_exit() {
    local status=$?
    if [ "$status" -ne 0 ]; then
        write_failure_report "$CURRENT_STAGE" "$(report_failure_reason)"
        printf '\nRELEASE READINESS: NOT READY\n'
    fi
}

trap on_exit EXIT

info "Recovery path delegates directly to the UTC tag-based finalize flow for ${RELEASE_VERSION} (${RELEASE_CHANNEL})..."
tag_status=0
RELEASE_ROOT="$RELEASE_ROOT" php "$SCRIPT_DIR/bump-packages.php" --yes --summary-json "$RELEASE_SUMMARY_FILE" || tag_status=$?

# 3: the tags are out and Packagist was still indexing, as in release-finalize.sh.
if [ "$tag_status" -ne 0 ] && [ "$tag_status" -ne 3 ]; then
    exit "$tag_status"
fi

mark_release_finalized
write_success_report

if [ "$tag_status" -eq 3 ]; then
    printf '\nRELEASE READINESS: RELEASED, Packagist UNVERIFIED — check it lists the version before calling it done\n'
    exit 0
fi

printf '\nRELEASE READINESS: READY\n'
