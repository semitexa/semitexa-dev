#!/usr/bin/env bash
set -euo pipefail

package_dir="${1:?package path is required}"

workspace_root="$(cd "$package_dir/../.." && pwd)"
env_file="$workspace_root/.env"

if [[ -z "${PACKAGIST_USERNAME:-}" || -z "${PACKAGIST_API_TOKEN:-}" ]] && [[ -f "$env_file" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$env_file"
    set +a
fi

packagist_username="${PACKAGIST_USERNAME:-}"
packagist_api_token="${PACKAGIST_API_TOKEN:-}"

if [[ -z "$packagist_username" || -z "$packagist_api_token" ]]; then
    echo "blocked: PACKAGIST_USERNAME and PACKAGIST_API_TOKEN are required" >&2
    exit 2
fi

if [[ ! -d "$package_dir/.git" ]]; then
    echo "blocked: $package_dir is not a git repository" >&2
    exit 2
fi

if [[ ! -f "$package_dir/composer.json" ]]; then
    echo "blocked: $package_dir has no composer.json" >&2
    exit 2
fi

repo_slug="$(git -C "$package_dir" remote get-url origin 2>/dev/null | sed -E 's#.*github.com[:/]([^/]+/[^/.]+)(\.git)?#\1#')"
if [[ -z "$repo_slug" ]]; then
    echo "blocked: $package_dir has no resolvable origin remote" >&2
    exit 2
fi

package_name="$(python3 - "$package_dir/composer.json" <<'PY'
import json, sys, pathlib
path = pathlib.Path(sys.argv[1])
data = json.loads(path.read_text())
name = (data.get("name") or "").strip()
print(name)
PY
)"

if [[ -z "$package_name" ]]; then
    echo "blocked: composer.json has no package name" >&2
    exit 2
fi

packagist_package_url="https://packagist.org/packages/$package_name"
hook_url="https://packagist.org/api/github?username=$packagist_username"

http_code="$(
    curl -fsS -o /dev/null -w '%{http_code}' "$packagist_package_url" || true
)"
if [[ "$http_code" != "200" ]]; then
    echo "manual-review: $package_name is not available on Packagist; submit it before webhook sync" >&2
    exit 3
fi

hooks_json="$(gh api "/repos/$repo_slug/hooks")"

hook_id="$(
    HOOK_URL="$hook_url" python3 -c '
import json
import os
import sys

target = os.environ["HOOK_URL"]
hooks = json.load(sys.stdin)
for hook in hooks:
    config = hook.get("config") or {}
    if config.get("url") == target:
        print(hook["id"])
        break
' <<<"$hooks_json"
)"

common_fields=(
    -f "config[url]=$hook_url"
    -f "config[content_type]=json"
    -f "config[secret]=$packagist_api_token"
    -f "events[]=push"
    -F active=true
)

if [[ -n "$hook_id" ]]; then
    gh api --method PATCH "/repos/$repo_slug/hooks/$hook_id" "${common_fields[@]}" >/dev/null
    echo "provisioned: synchronized Packagist webhook for $repo_slug"
else
    gh api --method POST "/repos/$repo_slug/hooks" -f name=web "${common_fields[@]}" >/dev/null
    echo "provisioned: created Packagist webhook for $repo_slug"
fi
