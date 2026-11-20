#!/usr/bin/env bash
# Fail the release when semitexa/installer's scaffold manifest is wrong.
#
# From step 2 of "one source for bin/semitexa" (dec-one-source-for-bin-semitexa)
# semitexa/update reads the scaffold and this manifest from
# vendor/semitexa/installer, so a defect in it reaches every project. Two checks:
#
#   1. tools/check-manifest.php: the manifest describes scaffold/ in a form every
#      released update reads, and still knows every hash the manifest at the
#      installer's last release tag knew. A dropped hash tells a project on that
#      version that it edited the file (Fable's review of the step 2 plan, D2).
#   2. tools/derive-manifest.php --check, while semitexa/update still ships the
#      runtime entries itself: this manifest says the same about them. Once
#      update's manifest lists none of the installer's files, there is nothing
#      to compare, and the tool goes away (step 2d).
#
# Both only read files, so they run on the host like the other structural
# stages: this stage runs before any container is up, and an exec into a stopped
# app container read as a stale manifest (review, dev#283). Only a tool's own
# "stale" answer (exit 1) is called stale; any other failure is reported as the
# check failing. A release whose installer has neither tool (before step 1)
# passes.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

cd "$DEV_ROOT"

installer="packages/semitexa-installer"
check_tool="$installer/tools/check-manifest.php"
derive_tool="$installer/tools/derive-manifest.php"
update_manifest="packages/semitexa-update/resources/scaffold-manifest.json"

if [ ! -f "$check_tool" ] && [ ! -f "$derive_tool" ]; then
    echo "semitexa/installer has no manifest tools yet: nothing to check."
    exit 0
fi

# Runs one check; on a failure prints what it means and exits with the tool's status.
#   $1  what to run again, shown when the manifest is stale
#   $@  the command
run_check() {
    local remedy="$1"
    shift
    local status=0
    "$@" || status=$?
    case "$status" in
        0)
            return 0
            ;;
        1)
            printf '\nsemitexa/installer'"'"'s scaffold manifest does not hold (findings above).\n%s\n\n' "$remedy" >&2
            exit 1
            ;;
        *)
            printf '\nCould not check semitexa/installer'"'"'s scaffold manifest: %s exited %s.\nThis is not a stale manifest; its message above says what failed.\n' "$(basename "$2")" "$status" >&2
            exit "$status"
            ;;
    esac
}

if [ -f "$check_tool" ]; then
    previous_args=()
    last_tag="$(git -C "$installer" tag -l '20*' --sort=-version:refname 2>/dev/null | head -n 1 || true)"
    if [ -n "$last_tag" ]; then
        previous="$(mktemp)"
        trap 'rm -f "$previous"' EXIT
        if git -C "$installer" show "${last_tag}:resources/scaffold-manifest.json" >"$previous" 2>/dev/null; then
            echo "History checked against ${last_tag}."
            previous_args=("--previous=$previous")
        else
            echo "The installer's last tag ${last_tag} has no manifest: the history is not checked against a release."
        fi
    else
        echo "The installer has no release tag: the history is not checked against a release."
    fi
    run_check "Rebuild it with the scaffold change and commit both: bin/semitexa scaffold:sync" \
        php "$check_tool" "${previous_args[@]}"
fi

# The transition guard, while update ships the runtime entries itself.
if [ -f "$derive_tool" ] && [ -f "$update_manifest" ] && grep -q '"path": "bin/semitexa"' "$update_manifest"; then
    run_check "Derive it again and commit it with the scaffold change:

    bin/semitexa update:scaffold:rebuild
    php packages/semitexa-installer/tools/derive-manifest.php" \
        php "$derive_tool" --check
fi
