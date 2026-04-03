#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PACKAGE_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"
SERVICE_TEMPLATE="${PACKAGE_ROOT}/resources/auto-deploy/systemd/semitexa-auto-deploy.service"
TIMER_TEMPLATE="${PACKAGE_ROOT}/resources/auto-deploy/systemd/semitexa-auto-deploy.timer"

usage() {
    cat <<'EOF'
Usage:
  install-auto-deploy-systemd.sh <project-root>

Environment:
  SEMITEXA_AUTO_DEPLOY_UNIT_PREFIX      Systemd unit prefix. Default: semitexa-auto-deploy
  SEMITEXA_AUTO_DEPLOY_TIMER_INTERVAL   Timer interval. Default: 15m
  SEMITEXA_AUTO_DEPLOY_SYSTEMD_DIR      Destination dir. Default: /etc/systemd/system
  SEMITEXA_AUTO_DEPLOY_RUN_AS_USER      Service user. Default: root
  SEMITEXA_AUTO_DEPLOY_ENABLE           Enable and start timer when set to 1. Default: 0
EOF
}

if [ "${1:-}" = "" ]; then
    usage >&2
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "This installer must run as root." >&2
    exit 1
fi

PROJECT_ROOT="$(CDPATH= cd -- "$1" && pwd)"
UNIT_PREFIX="${SEMITEXA_AUTO_DEPLOY_UNIT_PREFIX:-semitexa-auto-deploy}"
INTERVAL="${SEMITEXA_AUTO_DEPLOY_TIMER_INTERVAL:-15m}"
SYSTEMD_DIR="${SEMITEXA_AUTO_DEPLOY_SYSTEMD_DIR:-/etc/systemd/system}"
RUN_AS_USER="${SEMITEXA_AUTO_DEPLOY_RUN_AS_USER:-root}"
ENABLE="${SEMITEXA_AUTO_DEPLOY_ENABLE:-0}"
SERVICE_PATH="${SYSTEMD_DIR}/${UNIT_PREFIX}.service"
TIMER_PATH="${SYSTEMD_DIR}/${UNIT_PREFIX}.timer"

if [ ! -d "${PROJECT_ROOT}" ]; then
    echo "Project root does not exist: ${PROJECT_ROOT}" >&2
    exit 1
fi

if [ ! -f "${PROJECT_ROOT}/bin/semitexa" ]; then
    echo "Project CLI not found at ${PROJECT_ROOT}/bin/semitexa" >&2
    exit 1
fi

if ! getent passwd "${RUN_AS_USER}" >/dev/null 2>&1; then
    echo "Service user does not exist: ${RUN_AS_USER}" >&2
    exit 1
fi

mkdir -p "${SYSTEMD_DIR}"

project_root_escaped="$(printf '%s' "${PROJECT_ROOT}" | sed 's/[&|]/\\&/g')"
unit_prefix_escaped="$(printf '%s' "${UNIT_PREFIX}" | sed 's/[&|]/\\&/g')"
interval_escaped="$(printf '%s' "${INTERVAL}" | sed 's/[&|]/\\&/g')"
run_as_user_escaped="$(printf '%s' "${RUN_AS_USER}" | sed 's/[&|]/\\&/g')"
home_dir="$(getent passwd "${RUN_AS_USER}" | cut -d: -f6)"
home_dir_escaped="$(printf '%s' "${home_dir}" | sed 's/[&|]/\\&/g')"

sed \
    -e "s|@@PROJECT_ROOT@@|${project_root_escaped}|g" \
    -e "s|@@RUN_AS_USER@@|${run_as_user_escaped}|g" \
    -e "s|@@HOME_DIR@@|${home_dir_escaped}|g" \
    "${SERVICE_TEMPLATE}" > "${SERVICE_PATH}"

sed \
    -e "s|@@INTERVAL@@|${interval_escaped}|g" \
    -e "s|@@UNIT_PREFIX@@|${unit_prefix_escaped}|g" \
    "${TIMER_TEMPLATE}" > "${TIMER_PATH}"

chmod 0644 "${SERVICE_PATH}" "${TIMER_PATH}"
systemctl daemon-reload

if [ "${ENABLE}" = "1" ]; then
    systemctl enable --now "${UNIT_PREFIX}.timer"
fi

printf 'Installed: %s\n' "${SERVICE_PATH}"
printf 'Installed: %s\n' "${TIMER_PATH}"

if [ "${ENABLE}" = "1" ]; then
    printf 'Enabled timer: %s.timer\n' "${UNIT_PREFIX}"
else
    printf 'Timer installed but not enabled. Set SEMITEXA_AUTO_DEPLOY_ENABLE=1 to enable it.\n'
fi
