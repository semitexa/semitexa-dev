#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-}"

if [ -z "${PROJECT_ROOT}" ]; then
    echo "Usage: run-auto-deploy-systemd.sh <project-root>" >&2
    exit 1
fi

cd "${PROJECT_ROOT}"

OUTPUT="$(./bin/semitexa deploy:auto --json)"
STATUS=$?

printf '%s\n' "${OUTPUT}"

if [ "${STATUS}" -ne 0 ]; then
    exit "${STATUS}"
fi

RESTART_REQUIRED="$(printf '%s' "${OUTPUT}" | php -r '
$data = json_decode(stream_get_contents(STDIN), true);
if (!is_array($data)) {
    fwrite(STDERR, "Failed to decode deploy:auto JSON output.\n");
    exit(2);
}

$updated = ($data["status"] ?? null) === "updated";
$restartRequired = (bool) ($data["restart_required"] ?? false);
echo ($updated && $restartRequired) ? "1" : "0";
')"

if [ "${RESTART_REQUIRED}" = "1" ]; then
    chmod +x ./bin/semitexa
    ./bin/semitexa server:start
fi
