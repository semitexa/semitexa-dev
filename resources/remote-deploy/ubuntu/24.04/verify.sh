#!/usr/bin/env bash
set -euo pipefail

DEPLOY_PATH="${SEMITEXA_DEPLOY_PATH:?SEMITEXA_DEPLOY_PATH is required}"
MARKER_PATH="${DEPLOY_PATH}/.semitexa-deployment.json"

if [ "$(id -u)" -eq 0 ]; then
    SUDO=""
elif command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
else
    echo "Remote verification requires root or passwordless sudo." >&2
    exit 1
fi

docker_host_cmd() {
    if docker info >/dev/null 2>&1; then
        docker "$@"
    else
        "$SUDO" docker "$@"
    fi
}

docker_compose() {
    if docker_host_cmd compose version >/dev/null 2>&1; then
        docker_host_cmd compose "$@"
        return
    fi

    if command -v docker-compose >/dev/null 2>&1; then
        if [ -n "$SUDO" ]; then
            "$SUDO" docker-compose "$@"
        else
            docker-compose "$@"
        fi
        return
    fi

    echo "Docker Compose is not available on the remote host." >&2
    exit 1
}

[ -f "$MARKER_PATH" ] || {
    echo "Remote deployment marker not found at ${MARKER_PATH}." >&2
    exit 1
}

(
    cd "$DEPLOY_PATH"
    docker_compose ps --status running app | grep -q app
)

echo "[remote-bootstrap] Verification complete"
