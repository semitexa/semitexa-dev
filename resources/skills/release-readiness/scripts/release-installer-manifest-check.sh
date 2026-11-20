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
# "stale" answer (exit 1) is called stale. Anything the stage cannot read (the
# check tool, the tags, the manifest at a tag, update's manifest) fails it with
# exit 2: a check that could not run must not pass (review, dev#291).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

cd "$DEV_ROOT"

installer="packages/semitexa-installer"
check_tool="$installer/tools/check-manifest.php"
derive_tool="$installer/tools/derive-manifest.php"

# $1 what could not be read; $2 the exit status to end with (default 2).
cannot_check() {
    printf '\nCould not check semitexa/installer'"'"'s scaffold manifest: %s\nThis is not a stale manifest; the stage could not run the check.\n' "$1" >&2
    exit "${2:-2}"
}

if [ ! -f "$check_tool" ]; then
    cannot_check "$check_tool is missing."
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

# The transition guard, while update ships the runtime entries itself. Whether it
# does is read from update's manifest as JSON: exit 0 when one of its paths is a
# file of the installer's scaffold, 1 when none is, 2 when it cannot be read.
ships_status=0
php -- "$DEV_ROOT" <<'PHP' || ships_status=$?
<?php
$root = $argv[1];
if (!is_dir($root . '/packages/semitexa-update')) {
    exit(1);
}
$path = $root . '/packages/semitexa-update/resources/scaffold-manifest.json';
$raw = is_file($path) ? file_get_contents($path) : false;
$manifest = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
    fwrite(STDERR, "Cannot read semitexa/update's manifest as a scaffold manifest: {$path}\n");
    exit(2);
}
foreach ($manifest['files'] as $index => $entry) {
    if (!is_array($entry) || !is_string($entry['path'] ?? null) || $entry['path'] === '') {
        fwrite(STDERR, "files[{$index}] of {$path} has no path\n");
        exit(2);
    }
    if (is_file($root . '/packages/semitexa-installer/scaffold/' . $entry['path'])) {
        exit(0);
    }
}
exit(1);
PHP
case "$ships_status" in
    0)
        [ -f "$derive_tool" ] || cannot_check "semitexa/update still ships the runtime entries, and $derive_tool is missing."
        run_check "Derive it again and commit it with the scaffold change:

    bin/semitexa update:scaffold:rebuild
    php packages/semitexa-installer/tools/derive-manifest.php" \
            php "$derive_tool" --check
        ;;
    1)
        ;;
    *)
        cannot_check "whether semitexa/update still ships the runtime entries could not be read (exit $ships_status)."
        ;;
esac
