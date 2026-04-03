#!/usr/bin/env bash
set -euo pipefail

DEPLOY_PATH="${SEMITEXA_DEPLOY_PATH:?SEMITEXA_DEPLOY_PATH is required}"
ARTIFACT_PATH="${SEMITEXA_ARTIFACT_PATH:?SEMITEXA_ARTIFACT_PATH is required}"
REMOTE_ENV_PATH="${SEMITEXA_REMOTE_ENV_PATH:-}"
FORCE_REINITIALIZE="${SEMITEXA_FORCE_REINITIALIZE:-0}"
SCENARIO_ID="${SEMITEXA_SCENARIO_ID:-ubuntu/22.04}"
DEPLOY_DOMAIN="${SEMITEXA_DEPLOY_DOMAIN:-}"
MARKER_PATH="${DEPLOY_PATH}/.semitexa-deployment.json"

if [ "$(id -u)" -eq 0 ]; then
    SUDO=""
elif command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
else
    echo "Remote bootstrap requires root or passwordless sudo." >&2
    exit 1
fi

run_root() {
    if [ -n "$SUDO" ]; then
        "$SUDO" "$@"
    else
        "$@"
    fi
}

project_sh() {
    if [ -n "$SUDO" ]; then
        "$SUDO" -E sh "$@"
    else
        sh "$@"
    fi
}

docker_host_cmd() {
    if docker info >/dev/null 2>&1; then
        docker "$@"
    else
        run_root docker "$@"
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

compose_available() {
    docker_host_cmd compose version >/dev/null 2>&1 && return 0
    command -v docker-compose >/dev/null 2>&1 && docker-compose version >/dev/null 2>&1 && return 0
    return 1
}

ensure_bin() {
    if ! command -v "$1" >/dev/null 2>&1; then
        return 1
    fi

    return 0
}

ensure_apt_package() {
    run_root apt-get install -y "$@"
}

echo "[remote-bootstrap] Preparing Ubuntu host"
run_root apt-get update -y
ensure_apt_package ca-certificates curl tar git

if ! ensure_bin docker; then
    curl -fsSL https://get.docker.com | run_root sh
fi

if ! compose_available; then
    ensure_apt_package docker-compose-plugin || ensure_apt_package docker-compose-v2
fi

if command -v systemctl >/dev/null 2>&1; then
    run_root systemctl enable --now docker || true
fi

if [ "$FORCE_REINITIALIZE" = "1" ] && [ -d "$DEPLOY_PATH" ]; then
    if [ -f "${DEPLOY_PATH}/docker-compose.yml" ]; then
        (
            cd "$DEPLOY_PATH"
            docker_compose down --remove-orphans || true
        )
    fi
    run_root rm -rf "$DEPLOY_PATH"
fi

run_root mkdir -p "$DEPLOY_PATH"
run_root tar -xzf "$ARTIFACT_PATH" -C "$DEPLOY_PATH"
run_root chmod +x "${DEPLOY_PATH}/bin/semitexa"

if [ ! -f "${DEPLOY_PATH}/bin/semitexa" ] || [ ! -f "${DEPLOY_PATH}/composer.json" ] || [ ! -f "${DEPLOY_PATH}/docker-compose.yml" ]; then
    echo "Uploaded artifact does not look like a Semitexa project." >&2
    exit 1
fi

if [ -n "$REMOTE_ENV_PATH" ] && [ -f "$REMOTE_ENV_PATH" ]; then
    run_root cp "$REMOTE_ENV_PATH" "${DEPLOY_PATH}/.env.local"
    run_root cp "$REMOTE_ENV_PATH" "${DEPLOY_PATH}/.env"
elif [ ! -f "${DEPLOY_PATH}/.env.local" ]; then
    cat <<'EOF' | run_root tee "${DEPLOY_PATH}/.env.local" >/dev/null
APP_ENV=prod
APP_DEBUG=0
EOF
    cat <<'EOF' | run_root tee "${DEPLOY_PATH}/.env" >/dev/null
APP_ENV=prod
APP_DEBUG=0
EOF
fi

(
    cd "$DEPLOY_PATH"
    project_sh bin/semitexa install
    project_sh bin/semitexa server:start
    docker_compose exec -T app php vendor/bin/semitexa cache:clear
)

SOURCE_HOST="$(hostname 2>/dev/null || echo unknown)"
DEPLOYED_AT_UTC="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

jq -n \
    --arg artifact "semitexa.remote-bootstrap/v1" \
    --arg project_name "$(basename "$DEPLOY_PATH")" \
    --arg deployed_at_utc "$DEPLOYED_AT_UTC" \
    --arg source_host "$SOURCE_HOST" \
    --arg scenario "$SCENARIO_ID" \
    --arg deployment_path "$DEPLOY_PATH" \
    --arg domain "$DEPLOY_DOMAIN" \
    '{artifact: $artifact, project_name: $project_name, deployed_at_utc: $deployed_at_utc, source_host: $source_host, scenario: $scenario, deployment_path: $deployment_path, domain: $domain}' \
    | run_root tee "$MARKER_PATH" >/dev/null

echo "[remote-bootstrap] Bootstrap complete"
