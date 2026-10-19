#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# After the cut: did the release reach people? Waits for the "Published
# install" run that the semitexa/ultimate release tag starts on GitHub
# (semitexa-ultimate .github/workflows/published-install.yml), and writes its
# verdict into the release report.
#
# The run has two jobs, both from public sources only — semitexa.com/install.sh,
# the semitexa/installer image, Packagist: a NEW project installs exactly what
# the new ultimate pins, and a project on the release BEFORE, with code and an
# edit of its own, runs `bin/semitexa update` to it and keeps both. Its
# verdict is the run's, so either job failing fails it.
# Every gate before this one checks the release clone; this is the only one
# that checks what a newcomer gets.
#
# It runs after the tags exist, so it cannot stop the release: a FAIL here
# means the release is out and broken for new installs, and says so.
#
# Usage: release-published-install.sh           (reads the release session)
#        ULTIMATE_TAG=2026.10.19.0811 release-published-install.sh
# Exit: 0 PASS or nothing to check (ultimate not tagged), 1 FAIL, 2 unknown.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

require_cmd gh
require_cmd python3

REPO="semitexa/semitexa-ultimate"
WORKFLOW="published-install.yml"
WAIT_SECONDS="${PUBLISHED_INSTALL_WAIT_SECONDS:-3000}"

TAG="${ULTIMATE_TAG:-}"
if [ -z "$TAG" ]; then
    load_release_session
    [ -f "$RELEASE_SUMMARY_FILE" ] || { warn "published install: no release summary, nothing to check"; exit 0; }
    TAG="$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(next((p.get("tag","") for p in d.get("packages",[]) if p.get("name")=="semitexa/ultimate"), ""))' "$RELEASE_SUMMARY_FILE")"
fi
if [ -z "$TAG" ]; then
    info "published install: semitexa/ultimate was not tagged in this run, nothing new reached people"
    exit 0
fi

info "published install: waiting for the ${WORKFLOW} run on ultimate ${TAG} (up to $((WAIT_SECONDS / 60)) min)..."
deadline=$(( $(date +%s) + WAIT_SECONDS ))
run_id=''
conclusion=''
url=''
while :; do
    # A tag run's head branch is the tag name.
    line="$(gh run list -R "$REPO" -w "$WORKFLOW" -b "$TAG" -L 1 --json databaseId,status,conclusion,url \
        --jq '.[0] | "\(.databaseId) \(.status) \(.conclusion) \(.url)"' 2>/dev/null || true)"
    if [ -n "$line" ] && [ "$line" != "null null null null" ]; then
        read -r run_id status conclusion url <<<"$line"
        [ "$status" = "completed" ] && break
    fi
    if [ "$(date +%s)" -ge "$deadline" ]; then
        conclusion="${run_id:+still-running}"
        conclusion="${conclusion:-not-started}"
        break
    fi
    sleep 30
done

case "$conclusion" in
    success) verdict="PASS"; status_code=0 ;;
    failure|cancelled|timed_out|startup_failure) verdict="FAIL ($conclusion)"; status_code=1 ;;
    *) verdict="UNKNOWN ($conclusion)"; status_code=2 ;;
esac

if [ -n "${REPORT_PATH:-}" ] && [ -f "$REPORT_PATH" ]; then
    {
        printf '\n## Published install\n\n'
        printf -- '- Verdict: **%s**\n' "$verdict"
        printf -- '- What it checks: a new install of semitexa/ultimate `%s` (install.sh from semitexa.com, the semitexa/installer image, Packagist), and an upgrade to it from the release before with `bin/semitexa update`; both end with every semitexa/* package at the version ultimate pins\n' "$TAG"
        printf -- '- Run: %s\n' "${url:-none found}"
        if [ "$status_code" -eq 1 ]; then
            printf -- '- The release is tagged and published: new installs of `%s`, or upgrades to it (see which job failed), are broken until a fix is released.\n' "$TAG"
        fi
    } >> "$REPORT_PATH"
fi

case "$status_code" in
    0) ok "published install: PASS for ultimate ${TAG} (${url})" ;;
    1) printf '[FAIL] published install: %s for ultimate %s — the release is out and new installs do not work: %s\n' "$verdict" "$TAG" "$url" >&2 ;;
    *) warn "published install: ${verdict} for ultimate ${TAG}${url:+ — $url}" ;;
esac
exit "$status_code"
