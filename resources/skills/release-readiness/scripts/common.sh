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

# The clone's overlay set, chosen by what is actually on disk.
#
# This used to hardcode four files, one of them docker-compose.rabbitmq.yml —
# a transport the scaffold stopped shipping two migrations ago. Any clone
# created after that would have failed here on a missing file, and the NATS
# overlay we do ship was never passed at all. Naming files that may not exist
# is how a helper outlives the layout it was written for.
compose() {
    local args=()
    local overlay

    for overlay in \
        docker-compose.yml \
        docker-compose.mysql.yml \
        docker-compose.redis.yml \
        docker-compose.nats.yml \
        docker-compose.rabbitmq.yml \
        docker-compose.ollama.yml
    do
        [ -f "$RELEASE_ROOT/$overlay" ] && args+=(-f "$RELEASE_ROOT/$overlay")
    done

    docker compose "${args[@]}" "$@"
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
        RELEASE_CHANNEL_SOURCE="${RELEASE_CHANNEL_SOURCE:-explicit}"
        export RELEASE_CHANNEL_SOURCE
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
                RELEASE_CHANNEL_SOURCE="prompt"
                export RELEASE_CHANNEL RELEASE_CHANNEL_SOURCE
                return 0
                ;;
        esac
    fi

    # Reached whenever stdin/stdout is not a terminal -- which is every agent-driven
    # run, since the output is captured. This used to be a hard failure, on the
    # reasoning that the channel is sticky (beta cannot be promoted to stable on the
    # same master commit) and so must never be guessed. In practice the release is
    # stable every time, the refusal fired on the FIRST command of every release, and
    # the cost was a wasted round trip rather than a considered decision.
    #
    # So: default to stable, and make the default loud instead of silent. Choosing
    # beta stays a deliberate act -- RELEASE_CHANNEL=beta -- and the report records
    # which of the two ways the channel was picked, so "did anyone actually choose
    # this?" is answerable later.
    RELEASE_CHANNEL="stable"
    RELEASE_CHANNEL_SOURCE="default"
    export RELEASE_CHANNEL RELEASE_CHANNEL_SOURCE
    warn "No RELEASE_CHANNEL set and no terminal to ask: defaulting to 'stable'.
Pass RELEASE_CHANNEL=beta to cut a -beta version instead. Note that the choice is
sticky -- beta cannot be promoted to stable on the same master commit."
    return 0
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
    # A CUT IS ONE VERSION, however many preflight runs it takes.
    #
    # This used to mint a fresh UTC stamp on every run. That is wrong the moment
    # anything durable is written with the version -- and something always is:
    # release-resolve-floors.php --confirm --commit writes `>=$RELEASE_VERSION`
    # into a package's require and PUSHES it to master. A second preflight then
    # minted a later stamp, and check-internal-constraints reported the floor it
    # had just committed as naming "a release that does not exist", because no
    # such tag would ever be created. Measured 2026-09-19: floors dated at
    # .1020, next run minted .1024, gate red on a floor the flow itself wrote.
    #
    # So the session is sticky. It is re-minted only when there is no open cut:
    # no session file, or the last one was finalized. RELEASE_NEW_CUT=1 forces a
    # fresh one, and an explicit RELEASE_VERSION still wins over everything.
    local reused_version=""
    if [ -z "${RELEASE_VERSION:-}" ] \
        && [ "${RELEASE_NEW_CUT:-0}" != "1" ] \
        && [ -f "$RELEASE_SESSION_FILE" ]; then
        local prior_version prior_seed prior_finalized prior_channel
        prior_version="$(sed -n 's/^RELEASE_VERSION=//p' "$RELEASE_SESSION_FILE" | tail -n1)"
        prior_seed="$(sed -n 's/^RELEASE_VERSION_SEED=//p' "$RELEASE_SESSION_FILE" | tail -n1)"
        prior_finalized="$(sed -n 's/^RELEASE_FINALIZED=//p' "$RELEASE_SESSION_FILE" | tail -n1)"
        prior_channel="$(sed -n 's/^RELEASE_CHANNEL=//p' "$RELEASE_SESSION_FILE" | tail -n1)"
        # A channel change is a different cut: the channel decides the -beta
        # suffix, so reusing across it would hand back the wrong version.
        # EXPLICITLY "0", not merely "not 1". A session file written before this
        # field existed has no RELEASE_FINALIZED at all, and its version may
        # already be tagged — reusing it would re-cut a released version. An
        # absent or malformed flag means "unknown", and unknown mints fresh.
        if [ -n "$prior_version" ] && [ "$prior_finalized" = "0" ] && [ "$prior_channel" = "$RELEASE_CHANNEL" ]; then
            RELEASE_VERSION="$prior_version"
            RELEASE_VERSION_SEED="${prior_seed:-$prior_version}"
            reused_version="$prior_version"
        fi
    fi

    version_seed="${RELEASE_VERSION_SEED:-$(current_utc_version_seed)}"
    version_value="${RELEASE_VERSION:-$(release_version_for_channel "$version_seed" "$RELEASE_CHANNEL")}"
    generated_at="$(current_utc_timestamp)"

    if [ -n "$reused_version" ]; then
        info "Continuing the open cut at ${reused_version} (RELEASE_NEW_CUT=1 starts a new one)."
    fi

    REPORT_DATE="$report_date"
    REPORT_CODENAME="$codename"
    RELEASE_CODENAME="$codename"
    RELEASE_VERSION_SEED="$version_seed"
    RELEASE_VERSION="$version_value"
    RELEASE_GENERATED_AT_UTC="$generated_at"
    REPORT_PATH="$REPORT_DIR/${report_date}-${codename}-${version_seed//./-}.md"

    RELEASE_CHANNEL_SOURCE="${RELEASE_CHANNEL_SOURCE:-explicit}"

    export REPORT_DATE REPORT_CODENAME REPORT_PATH RELEASE_CHANNEL RELEASE_CHANNEL_SOURCE RELEASE_CODENAME RELEASE_VERSION_SEED RELEASE_VERSION RELEASE_GENERATED_AT_UTC

    : >"$RELEASE_FAILURE_FILE"
    rm -f "$RELEASE_SUMMARY_FILE"
    rm -f "$RELEASE_DIVERGENCE_FILE"

    cat >"$RELEASE_SESSION_FILE" <<EOF
REPORT_DATE=$REPORT_DATE
REPORT_CODENAME=$REPORT_CODENAME
REPORT_PATH=$REPORT_PATH
RELEASE_CHANNEL=$RELEASE_CHANNEL
RELEASE_CHANNEL_SOURCE=$RELEASE_CHANNEL_SOURCE
RELEASE_CODENAME=$RELEASE_CODENAME
RELEASE_VERSION_SEED=$RELEASE_VERSION_SEED
RELEASE_VERSION=$RELEASE_VERSION
RELEASE_GENERATED_AT_UTC=$RELEASE_GENERATED_AT_UTC
RELEASE_ROOT=$RELEASE_ROOT
DEV_ROOT=$DEV_ROOT
RELEASE_SUMMARY_FILE=$RELEASE_SUMMARY_FILE
RELEASE_FINALIZED=0
EOF
}

# Closes the cut, so the NEXT preflight mints a fresh version instead of
# handing back the one that was already tagged.
mark_release_finalized() {
    [ -f "$RELEASE_SESSION_FILE" ] || return 0
    if grep -q '^RELEASE_FINALIZED=' "$RELEASE_SESSION_FILE"; then
        sed -i 's/^RELEASE_FINALIZED=.*/RELEASE_FINALIZED=1/' "$RELEASE_SESSION_FILE"
    else
        printf 'RELEASE_FINALIZED=1\n' >>"$RELEASE_SESSION_FILE"
    fi
}

load_release_session() {
    [ -f "$RELEASE_SESSION_FILE" ] || fail "No active release session found. Run release-preflight.sh first."

    # shellcheck disable=SC1090
    source "$RELEASE_SESSION_FILE"

    RELEASE_CHANNEL_SOURCE="${RELEASE_CHANNEL_SOURCE:-explicit}"
    export REPORT_DATE REPORT_CODENAME REPORT_PATH RELEASE_CHANNEL RELEASE_CHANNEL_SOURCE RELEASE_CODENAME RELEASE_VERSION_SEED RELEASE_VERSION RELEASE_GENERATED_AT_UTC RELEASE_ROOT DEV_ROOT RELEASE_SUMMARY_FILE
}

# `stable` is the default when nothing chose one, so the report has to say which of
# the two it was -- otherwise a sticky decision looks identical whether it was made
# or merely fell out.
report_channel_line() {
    case "${RELEASE_CHANNEL_SOURCE:-explicit}" in
        default) printf '`%s` (defaulted — nothing passed RELEASE_CHANNEL)' "$RELEASE_CHANNEL" ;;
        prompt)  printf '`%s` (chosen at the prompt)' "$RELEASE_CHANNEL" ;;
        *)       printf '`%s` (explicitly passed)' "$RELEASE_CHANNEL" ;;
    esac
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
- Release channel: $(report_channel_line)
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
        printf -- '- Release channel: %s\n' "$(report_channel_line)"
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

    python3 - "$RELEASE_SUMMARY_FILE" "$REPORT_PATH" "$REPORT_DATE" "$(report_title)" "$RELEASE_ROOT" "$(report_channel_line)" "$RELEASE_CODENAME" "$RELEASE_VERSION" "$RELEASE_GENERATED_AT_UTC" <<'PY'
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
    f"- Release channel: {release_channel}",
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
