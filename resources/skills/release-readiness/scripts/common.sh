#!/usr/bin/env bash
set -euo pipefail

RELEASE_ROOT="${RELEASE_ROOT:-/home/taras/Documents/Projects/semitexa.rls}"
DEV_ROOT="${DEV_ROOT:-/home/taras/Documents/Projects/semitexa.dev}"
SWOOLE_PORT="${SWOOLE_PORT:-9502}"
RLS_DOMAIN_NAMESPACE="${RLS_DOMAIN_NAMESPACE:-rls.semitexa.test}"
RLS_SITE_HOST="${RLS_SITE_HOST:-site.${RLS_DOMAIN_NAMESPACE}}"
RLS_DEMO_HOST="${RLS_DEMO_HOST:-demo.${RLS_DOMAIN_NAMESPACE}}"
RLS_OS_HOST="${RLS_OS_HOST:-os.${RLS_DOMAIN_NAMESPACE}}"
RLS_PLATFORM_HOST="${RLS_PLATFORM_HOST:-platform.${RLS_DOMAIN_NAMESPACE}}"
RLS_ENV_FILE="${RLS_ENV_FILE:-$RELEASE_ROOT/.env}"
REPORT_DIR="${REPORT_DIR:-$DEV_ROOT/var/docs/release}"
RELEASE_CODENAME_DIR="${RELEASE_CODENAME_DIR:-$REPORT_DIR/codenames}"
RELEASE_STATE_DIR="${RELEASE_STATE_DIR:-${TMPDIR:-/tmp}/semitexa-release-readiness}"
RELEASE_SESSION_FILE="${RELEASE_SESSION_FILE:-$RELEASE_STATE_DIR/session.env}"
RELEASE_FAILURE_FILE="${RELEASE_FAILURE_FILE:-$RELEASE_STATE_DIR/failure.txt}"
RELEASE_SUMMARY_FILE="${RELEASE_SUMMARY_FILE:-$RELEASE_STATE_DIR/summary.json}"
RELEASE_DIVERGENCE_FILE="${RELEASE_DIVERGENCE_FILE:-$RELEASE_STATE_DIR/develop-divergence.txt}"

info() { printf '[INFO] %s\n' "$*"; }
ok() { printf '[OK] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*" >&2; }
fail() {
    ensure_release_state_dir
    printf '%s\n' "$*" >"$RELEASE_FAILURE_FILE"
    printf '[FAIL] %s\n' "$*" >&2
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

set_env_value() {
    local key="$1"
    local value="$2"
    local file="$3"
    local tmp

    [ -f "$file" ] || fail "Missing env file: $file"

    tmp="$(mktemp)"
    awk -v key="$key" -v value="$value" '
        BEGIN { updated = 0 }
        $0 ~ "^[[:space:]]*" key "[[:space:]]*=" {
            print key "=" value
            updated = 1
            next
        }
        { print }
        END {
            if (!updated) {
                print key "=" value
            }
        }
    ' "$file" >"$tmp"
    mv "$tmp" "$file"
}

ensure_release_domain_namespace() {
    [ -f "$RLS_ENV_FILE" ] || fail "Missing release env file: $RLS_ENV_FILE"

    set_env_value "TENANCY_BASE_DOMAIN" "$RLS_DOMAIN_NAMESPACE" "$RLS_ENV_FILE"
    set_env_value "TENANT_SEMITEXA_DOMAIN" "$RLS_SITE_HOST" "$RLS_ENV_FILE"
    set_env_value "TENANT_DEMO_DOMAIN" "$RLS_DEMO_HOST" "$RLS_ENV_FILE"
    set_env_value "TENANT_OS_DOMAIN" "$RLS_OS_HOST" "$RLS_ENV_FILE"
    set_env_value "TENANT_PLATFORM_DOMAIN" "$RLS_PLATFORM_HOST" "$RLS_ENV_FILE"
}

compose() {
    docker compose \
        -f "$RELEASE_ROOT/docker-compose.yml" \
        -f "$RELEASE_ROOT/docker-compose.rabbitmq.yml" \
        -f "$RELEASE_ROOT/docker-compose.mysql.yml" \
        -f "$RELEASE_ROOT/docker-compose.redis.yml" \
        "$@"
}

list_release_repos() {
    if [ -d "$RELEASE_ROOT/.git" ]; then
        printf '%s\n' "$RELEASE_ROOT"
    fi

    for dir in "$RELEASE_ROOT"/packages/*; do
        [ -d "$dir/.git" ] || continue
        printf '%s\n' "$dir"
    done
}

project_name() {
    basename "$1"
}

assert_release_root() {
    [ "$RELEASE_ROOT" = "/home/taras/Documents/Projects/semitexa.rls" ] || \
        fail "This skill is pinned to /home/taras/Documents/Projects/semitexa.rls in v1"
}

ensure_release_state_dir() {
    mkdir -p "$RELEASE_STATE_DIR"
}

ensure_report_dir() {
    mkdir -p "$REPORT_DIR"
    mkdir -p "$RELEASE_CODENAME_DIR"
}

generate_release_codename() {
    local prefixes=(lu no ve so ca mi ta ri el auri)
    local middles=(ma ri le no va se li do mi ra)
    local suffixes=(ra len dor va rel nia sa lio vera)
    local seed
    local timestamp

    timestamp="$(date +%s%N)"
    seed="$(printf '%s' "$timestamp" | cksum | awk '{print $1}')"

    local p="${prefixes[$((seed % ${#prefixes[@]}))]}"
    local m="${middles[$(((seed / 7) % ${#middles[@]}))]}"
    local s="${suffixes[$(((seed / 17) % ${#suffixes[@]}))]}"

    printf '%s%s%s' "$p" "$m" "$s"
}

current_utc_month() {
    date -u +%Y-%m
}

current_utc_version_seed() {
    date -u +%Y.%m.%d.%H%M
}

current_utc_timestamp() {
    date -u +%Y-%m-%dT%H:%M:00Z
}

release_channel_prompt() {
    local answer

    if [ -n "${RELEASE_CHANNEL:-}" ]; then
        return 0
    fi

    if [ -t 0 ] && [ -t 1 ]; then
        printf 'Release channel [stable/beta] (stable recommended): '
        IFS= read -r answer
        answer="${answer,,}"
        if [ -z "$answer" ]; then
            answer="stable"
        fi
        case "$answer" in
            stable|beta)
                RELEASE_CHANNEL="$answer"
                export RELEASE_CHANNEL
                return 0
                ;;
        esac
    fi

    fail "Release channel is required. Set RELEASE_CHANNEL=stable or RELEASE_CHANNEL=beta before running the release workflow."
}

normalize_release_channel() {
    case "${RELEASE_CHANNEL:-}" in
        stable|beta)
            return 0
            ;;
        *)
            fail "Unsupported RELEASE_CHANNEL='${RELEASE_CHANNEL:-}'. Expected 'stable' or 'beta'."
            ;;
    esac
}

release_version_for_channel() {
    local base="$1"
    local channel="$2"

    case "$channel" in
        stable) printf '%s' "$base" ;;
        beta) printf '%s-beta' "$base" ;;
        *) fail "Unsupported release channel: $channel" ;;
    esac
}

monthly_release_codename() {
    local month_key="$1"
    local path="$RELEASE_CODENAME_DIR/${month_key}.txt"
    local value

    if [ -f "$path" ]; then
        value="$(tr -d '\r\n' <"$path")"
        if [ -n "$value" ]; then
            printf '%s' "$value"
            return 0
        fi
    fi

    value="$(generate_release_codename)"
    printf '%s\n' "$value" >"$path"
    printf '%s' "$value"
}

init_release_session() {
    assert_release_root
    ensure_release_state_dir
    ensure_report_dir

    release_channel_prompt
    normalize_release_channel

    local report_date
    local utc_month
    local codename
    local version_seed
    local version_value
    local generated_at

    report_date="$(date -u +%F)"
    utc_month="$(current_utc_month)"
    codename="$(monthly_release_codename "$utc_month")"
    version_seed="${RELEASE_VERSION_SEED:-$(current_utc_version_seed)}"
    version_value="${RELEASE_VERSION:-$(release_version_for_channel "$version_seed" "$RELEASE_CHANNEL")}"
    generated_at="$(current_utc_timestamp)"

    REPORT_DATE="$report_date"
    REPORT_CODENAME="$codename"
    RELEASE_CODENAME="$codename"
    RELEASE_VERSION_SEED="$version_seed"
    RELEASE_VERSION="$version_value"
    RELEASE_GENERATED_AT_UTC="$generated_at"
    REPORT_PATH="$REPORT_DIR/${report_date}-${codename}-${version_seed//./-}.md"

    export REPORT_DATE REPORT_CODENAME REPORT_PATH RELEASE_CHANNEL RELEASE_CODENAME RELEASE_VERSION_SEED RELEASE_VERSION RELEASE_GENERATED_AT_UTC

    : >"$RELEASE_FAILURE_FILE"
    rm -f "$RELEASE_SUMMARY_FILE"
    rm -f "$RELEASE_DIVERGENCE_FILE"

    cat >"$RELEASE_SESSION_FILE" <<EOF
REPORT_DATE=$REPORT_DATE
REPORT_CODENAME=$REPORT_CODENAME
REPORT_PATH=$REPORT_PATH
RELEASE_CHANNEL=$RELEASE_CHANNEL
RELEASE_CODENAME=$RELEASE_CODENAME
RELEASE_VERSION_SEED=$RELEASE_VERSION_SEED
RELEASE_VERSION=$RELEASE_VERSION
RELEASE_GENERATED_AT_UTC=$RELEASE_GENERATED_AT_UTC
RELEASE_ROOT=$RELEASE_ROOT
DEV_ROOT=$DEV_ROOT
RELEASE_SUMMARY_FILE=$RELEASE_SUMMARY_FILE
EOF
}

load_release_session() {
    [ -f "$RELEASE_SESSION_FILE" ] || fail "No active release session found. Run release-preflight.sh first."

    # shellcheck disable=SC1090
    source "$RELEASE_SESSION_FILE"

    export REPORT_DATE REPORT_CODENAME REPORT_PATH RELEASE_CHANNEL RELEASE_CODENAME RELEASE_VERSION_SEED RELEASE_VERSION RELEASE_GENERATED_AT_UTC RELEASE_ROOT DEV_ROOT RELEASE_SUMMARY_FILE
}

report_title() {
    local value="${RELEASE_CODENAME:-${REPORT_CODENAME:-}}"
    printf '%s %s' "${value^}" "${RELEASE_VERSION:-unknown}"
}

report_failure_reason() {
    if [ -s "$RELEASE_FAILURE_FILE" ]; then
        cat "$RELEASE_FAILURE_FILE"
        return
    fi

    printf 'Release checks failed without a captured reason.\n'
}

append_develop_divergence_section() {
    [ -s "$RELEASE_DIVERGENCE_FILE" ] || return 0

    printf '## ⚠ develop Ahead of master\n\n'
    printf 'These packages have unmerged `develop` work. Finalize tags the `master` HEAD only — merge `develop`→`master` (GitHub PR or `/review-prep`) before finalize, or finalize will be a no-op for them:\n\n'
    while IFS="$(printf '\t')" read -r name ahead develop_sha master_sha; do
        [ -n "$name" ] || continue
        printf -- '- `%s`: develop @ `%s` is %s commit(s) ahead of master @ `%s`\n' \
            "$name" "$develop_sha" "$ahead" "$master_sha"
    done <"$RELEASE_DIVERGENCE_FILE"
    printf '\n'
}

append_playwright_artifacts_section() {
    local screenshots
    local videos
    local has_entries=0

    printf '## Playwright Artifacts\n\n'
    printf -- '- Test results root: `%s`\n' "$RELEASE_ROOT/test-results"

    if [ -d "$RELEASE_ROOT/test-results" ]; then
        screenshots="$(find "$RELEASE_ROOT/test-results" -type f -name '*.png' | sort || true)"
        videos="$(find "$RELEASE_ROOT/test-results" -type f -name '*.webm' | sort || true)"

        if [ -n "$screenshots" ]; then
            has_entries=1
            printf -- '- Screenshots:\n'
            while IFS= read -r path; do
                [ -n "$path" ] || continue
                printf '  - `%s`\n' "$path"
            done <<EOF
$screenshots
EOF
        fi

        if [ -n "$videos" ]; then
            has_entries=1
            printf -- '- Videos:\n'
            while IFS= read -r path; do
                [ -n "$path" ] || continue
                printf '  - `%s`\n' "$path"
            done <<EOF
$videos
EOF
        fi
    fi

    if [ "$has_entries" -eq 0 ]; then
        printf -- '- No Playwright screenshots or videos were generated.\n'
    fi

    printf '\n'
}

write_pending_report() {
    load_release_session

    local title
    title="$(report_title)"

    cat >"$REPORT_PATH" <<EOF
# Release Report: $REPORT_DATE / $title

- Status: preflight passed
- Release channel: \`${RELEASE_CHANNEL}\`
- Release codename: \`${RELEASE_CODENAME^}\`
- Planned package version: \`${RELEASE_VERSION}\`
- Release root: \`$RELEASE_ROOT\`
- Release branch: \`master\`
- Generated at: \`${RELEASE_GENERATED_AT_UTC}\`

## Automated Verification

- Package repos inside the \`semitexa.rls\` release set were refreshed with \`git pull --ff-only origin master\`
- The release stack in \`$RELEASE_ROOT\` was fully restarted before checks
- Automated HTTP route checks, SSR helper checks, Playwright browser smoke, logs, static analysis, and test suites passed

Manual browser QA is fallback-only and can use \`references/RELEASE_CHECKLIST.md\` when needed.
EOF

    append_develop_divergence_section >>"$REPORT_PATH"
    append_playwright_artifacts_section >>"$REPORT_PATH"
}

write_failure_report() {
    load_release_session

    local stage="$1"
    local reason="${2:-}"
    local title

    title="$(report_title)"
    if [ -z "$reason" ]; then
        reason="$(report_failure_reason)"
    fi

    {
        printf '# Release Report: %s / %s\n\n' "$REPORT_DATE" "$title"
        printf -- '- Status: postponed\n'
        printf -- '- Release channel: `%s`\n' "$RELEASE_CHANNEL"
        printf -- '- Release codename: `%s`\n' "${RELEASE_CODENAME^}"
        printf -- '- Planned package version: `%s`\n' "$RELEASE_VERSION"
        printf -- '- Release root: `%s`\n' "$RELEASE_ROOT"
        printf -- '- Failed stage: `%s`\n' "$stage"
        printf -- '- Generated at: `%s`\n\n' "$RELEASE_GENERATED_AT_UTC"
        printf '## Problems to Resolve\n\n'
        while IFS= read -r line; do
            [ -n "$line" ] || continue
            printf -- '- %s\n' "$line"
        done <<EOF
$reason
EOF
    } >"$REPORT_PATH"

    append_playwright_artifacts_section >>"$REPORT_PATH"
}

write_success_report() {
    load_release_session
    require_cmd python3

    [ -f "$RELEASE_SUMMARY_FILE" ] || fail "Release summary file not found: $RELEASE_SUMMARY_FILE"

    python3 - "$RELEASE_SUMMARY_FILE" "$REPORT_PATH" "$REPORT_DATE" "$(report_title)" "$RELEASE_ROOT" "$RELEASE_CHANNEL" "$RELEASE_CODENAME" "$RELEASE_VERSION" "$RELEASE_GENERATED_AT_UTC" <<'PY'
import json
import pathlib
import sys

summary_path, report_path, report_date, title, release_root, release_channel, release_codename, release_version, generated_at_utc = sys.argv[1:10]
data = json.loads(pathlib.Path(summary_path).read_text())
packages = data.get("packages", [])

lines = [
    f"# Release Report: {report_date} / {title}",
    "",
    "- Status: released",
    f"- Release channel: `{release_channel}`",
    f"- Release codename: `{release_codename.capitalize()}`",
    f"- Package version seed: `{release_version}`",
    f"- Release root: `{release_root}`",
    f"- Generated at: `{generated_at_utc}`",
    "",
    "## Changes in This Release",
    "",
]

if not packages:
    lines.append("- No new package tags were required after the validation pass.")
else:
    for package in packages:
        version = package.get("tag", package.get("version", "unknown"))
        previous_tag = package.get("previous_tag") or "none"
        lines.append(f"### {package['name']} `{version}`")
        lines.append("")
        lines.append(f"- Previous release tag: `{previous_tag}`")
        lines.append(f"- Release commit: `{package.get('head_sha_short', '')}`")
        changes = package.get("changes") or []
        if changes:
            for change in changes:
                subject = change.get("subject", "").strip() or "(no subject)"
                sha_short = (change.get("sha") or "")[:8]
                lines.append(f"- {subject} (`{sha_short}`)")
        else:
            lines.append("- No commit summary was available for this package.")
        lines.append("")

pathlib.Path(report_path).write_text("\n".join(lines).rstrip() + "\n")
PY

    append_playwright_artifacts_section >>"$REPORT_PATH"
}
