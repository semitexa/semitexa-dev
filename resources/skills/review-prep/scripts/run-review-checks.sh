#!/usr/bin/env bash
set -euo pipefail

repo=""
summary_file=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --summary-file)
            summary_file="${2:-}"
            shift 2
            ;;
        *)
            if [ -z "$repo" ]; then
                repo="$1"
            else
                printf 'Unexpected argument: %s\n' "$1" >&2
                exit 1
            fi
            shift
            ;;
    esac
done

[ -n "$repo" ] || { printf 'Usage: %s /absolute/path/to/repo [--summary-file /tmp/file]\n' "$0" >&2; exit 1; }
[ -d "$repo" ] || { printf 'Repo not found: %s\n' "$repo" >&2; exit 1; }

if [ -n "$summary_file" ]; then
    : > "$summary_file"
fi

append_summary() {
    local line="$1"
    printf '%s\n' "$line"
    if [ -n "$summary_file" ]; then
        printf '%s\n' "$line" >> "$summary_file"
    fi
}

run_check() {
    local label="$1"
    shift
    append_summary "- [x] ${label}"
    (
        cd "$repo"
        "$@"
    )
}

has_composer_script() {
    local script_name="$1"
    [ -f "$repo/composer.json" ] || return 1
    python3 - "$repo/composer.json" "$script_name" <<'PY'
import json, sys, pathlib
path = pathlib.Path(sys.argv[1])
name = sys.argv[2]
try:
    data = json.loads(path.read_text())
except Exception:
    raise SystemExit(1)
scripts = data.get('scripts') or {}
raise SystemExit(0 if name in scripts else 1)
PY
}

has_package_script() {
    local script_name="$1"
    [ -f "$repo/package.json" ] || return 1
    python3 - "$repo/package.json" "$script_name" <<'PY'
import json, sys, pathlib
path = pathlib.Path(sys.argv[1])
name = sys.argv[2]
try:
    data = json.loads(path.read_text())
except Exception:
    raise SystemExit(1)
scripts = data.get('scripts') or {}
raise SystemExit(0 if name in scripts else 1)
PY
}

checks_run=0

if has_composer_script phpstan; then
    run_check "composer phpstan" composer phpstan
    checks_run=$((checks_run + 1))
fi

if has_composer_script test; then
    run_check "composer test" composer test
    checks_run=$((checks_run + 1))
fi

if has_package_script lint; then
    run_check "npm run lint" npm run lint
    checks_run=$((checks_run + 1))
fi

if has_package_script test; then
    run_check "npm test" npm test
    checks_run=$((checks_run + 1))
fi

if has_package_script test:e2e; then
    run_check "npm run test:e2e" npm run test:e2e
    checks_run=$((checks_run + 1))
fi

if has_package_script build; then
    run_check "npm run build" npm run build
    checks_run=$((checks_run + 1))
fi

if [ "$checks_run" -eq 0 ]; then
    append_summary "- [ ] No repo-defined automated checks were found"
fi
