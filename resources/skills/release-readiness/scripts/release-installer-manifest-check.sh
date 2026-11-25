#!/usr/bin/env bash
# Fail the release when semitexa/installer's scaffold manifest is wrong.
#
# From step 2 of "one source for bin/semitexa" (dec-one-source-for-bin-semitexa)
# semitexa/update reads the scaffold and this manifest from
# vendor/semitexa/installer, so a defect in it reaches every project.
# tools/check-manifest.php says the manifest describes scaffold/ in a form every
# released update reads, and still knows every hash the manifest at the
# installer's last release tag knew. A dropped hash tells a project on that
# version that it edited the file (Fable's review of the step 2 plan, D2).
#
# It only reads files, so it runs on the host like the other structural
# stages: this stage runs before any container is up, and an exec into a stopped
# app container read as a stale manifest (review, dev#283). Only a tool's own
# "stale" answer (exit 1) is called stale. Anything the stage cannot read (the
# check tool, the tags, the manifest at a tag) fails it with
# exit 2: a check that could not run must not pass (review, dev#291).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

cd "$DEV_ROOT"

installer="packages/semitexa-installer"
check_tool="$installer/tools/check-manifest.php"

# $1 what could not be read; $2 the exit status to end with (default 2).
cannot_check() {
    printf '\nCould not check semitexa/installer'"'"'s scaffold manifest: %s\nThis is not a stale manifest; the stage could not run the check.\n' "$1" >&2
    exit "${2:-2}"
}

if [ ! -f "$check_tool" ]; then
    cannot_check "$check_tool is missing."
fi

# Runs the check; on a failure prints what it means and exits with the tool's status.
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
            cannot_check "$(basename "$2") exited $status; its message above says what failed." "$status"
            ;;
    esac
}

# The manifest at the installer's newest release tag. Tags sit on master's merge
# commits, which develop does not contain, so the newest is found by version, not
# by `git describe`. No tag, or a tag from before the installer had a manifest,
# is an answer; git failing to answer is not.
previous_args=()
tags_status=0
tags="$(git -C "$installer" tag -l '20*' --sort=-version:refname)" || tags_status=$?
[ "$tags_status" -eq 0 ] || cannot_check "git could not list the release tags of $installer (exit $tags_status)."
last_tag="$(printf '%s\n' "$tags" | head -n 1)"
if [ -z "$last_tag" ]; then
    echo "The installer has no release tag: the history is not checked against a release."
else
    listed_status=0
    listed="$(git -C "$installer" ls-tree --name-only "$last_tag" -- resources/scaffold-manifest.json)" || listed_status=$?
    [ "$listed_status" -eq 0 ] || cannot_check "git could not read tag $last_tag of $installer (exit $listed_status)."
    if [ -z "$listed" ]; then
        echo "The installer's last tag ${last_tag} has no manifest: the history is not checked against a release."
    else
        previous="$(mktemp)"
        trap 'rm -f "$previous"' EXIT
        show_status=0
        git -C "$installer" show "${last_tag}:resources/scaffold-manifest.json" >"$previous" || show_status=$?
        [ "$show_status" -eq 0 ] || cannot_check "git could not read the manifest at $last_tag (exit $show_status)."
        echo "History checked against ${last_tag}."
        previous_args=("--previous=$previous")
    fi
fi

run_check "Rebuild it with the scaffold change and commit both: bin/semitexa scaffold:sync" \
    php "$check_tool" "${previous_args[@]}"

