#!/usr/bin/env bash
#
# Fan the workspace's PHPStan configuration out from its versioned home.
#
# Usage:
#   phpstan-sync.sh            copy the canonical files to the project root
#   phpstan-sync.sh --check    report drift and exit 1 (pre-push / release gate)
#   phpstan-sync.sh --adopt    copy the ROOT files back over the canonical ones
#
# Where the canonical copy lives
# ------------------------------
# packages/semitexa-dev/resources/phpstan/ — the same shelf, and for the same
# reason, as resources/skills/: the project root is not a git repository, so
# phpstan.neon and phpstan-baseline.neon at the root were versioned by nothing
# at all. Measured 2026-09-13: a 147-entry baseline cleanup and a rule sweep
# existed on exactly one machine, with no history, nothing to review and
# nothing to restore from.
#
# The copies exist because PHPStan resolves `paths:` RELATIVE TO THE CONFIG
# FILE. A root phpstan.neon that merely `includes:` the canonical one does not
# help — the included file's own paths would resolve next to itself, four
# directories deep. So the root holds real files and this keeps them honest.
#
# --adopt is the direction you want after a real analysis session: the tools
# rewrite phpstan-baseline.neon AT THE ROOT (that is where phpstan runs), and
# adopt carries the result back to the versioned copy for review.
set -uo pipefail

# Walk up to the project root — the directory holding both bin/semitexa and
# packages/ — rather than counting `../` from here. This script runs from two
# depths: its home under packages/…/resources/, and the bin/ copy it makes of
# itself. Same finder as skills-sync.sh, for the same reason.
find_project_root() {
    local dir
    dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    while [ "$dir" != "/" ]; do
        if [ -f "$dir/bin/semitexa" ] && [ -d "$dir/packages" ]; then
            printf '%s' "$dir"
            return 0
        fi
        dir="$(dirname "$dir")"
    done
    return 1
}

if ! PROJECT_ROOT="$(find_project_root)"; then
    printf 'Not inside a Semitexa project (no bin/semitexa + packages/ above %s).\n' "$(dirname "${BASH_SOURCE[0]}")" >&2
    exit 1
fi

CANONICAL="$PROJECT_ROOT/packages/semitexa-dev/resources/phpstan"
SELF="phpstan-sync.sh"

if [ ! -d "$CANONICAL" ]; then
    printf 'Canonical phpstan directory missing: %s\n' "$CANONICAL" >&2
    exit 1
fi

# canonical-relative path : root-relative path
PAIRS=(
    "phpstan.neon:phpstan.neon"
    "phpstan-strict.neon:phpstan-strict.neon"
    "phpstan-baseline.neon:phpstan-baseline.neon"
    "phpstan-bootstrap.php:phpstan-bootstrap.php"
    "bin/strip-stale-baseline.php:bin/phpstan/strip-stale-baseline.php"
    "bin/strip-unmatched.php:bin/phpstan/strip-unmatched.php"
    "bin/sync-counts.php:bin/phpstan/sync-counts.php"
)

MODE="sync"
for arg in "$@"; do
    case "$arg" in
        --check) MODE="check" ;;
        --adopt) MODE="adopt" ;;
        *) printf 'Unknown argument: %s\n' "$arg" >&2; exit 2 ;;
    esac
done

drifted=0
moved=0
failed=0

copy_pair() {
    local src="$1" dst="$2" label="$3"

    # A missing source is a FAILURE in a mutating mode, not a note. `drifted`
    # is only consulted by --check, so sync and adopt used to print "already in
    # sync" and exit 0 after a copy that never happened — running --adopt
    # before the root phpstan-strict.neon exists did exactly that. And this
    # script runs under `set -uo pipefail`, not `set -e`, so a failed mkdir or
    # cp does not end it either: both are tracked and checked after the loop.
    # Raised in review of dev#84, by both reviewers.
    if [ ! -f "$src" ]; then
        printf 'MISSING: %s\n' "$label" >&2
        drifted=$((drifted + 1))
        [ "$MODE" = "check" ] || failed=$((failed + 1))
        return 1
    fi

    if [ -f "$dst" ] && cmp -s "$src" "$dst"; then
        return 0
    fi

    drifted=$((drifted + 1))
    if [ "$MODE" = "check" ]; then
        printf 'DRIFT: %s\n' "$label"
        return 0
    fi

    if ! mkdir -p "$(dirname "$dst")"; then
        printf 'FAILED (mkdir): %s\n' "$label" >&2
        failed=$((failed + 1))
        return 1
    fi

    if ! cp "$src" "$dst"; then
        printf 'FAILED (cp): %s\n' "$label" >&2
        failed=$((failed + 1))
        return 1
    fi

    # Counted only once both commands have succeeded.
    moved=$((moved + 1))
    printf 'SYNCED: %s\n' "$label"
    return 0
}

for pair in "${PAIRS[@]}"; do
    canon="$CANONICAL/${pair%%:*}"
    root="$PROJECT_ROOT/${pair##*:}"

    if [ "$MODE" = "adopt" ]; then
        copy_pair "$root" "$canon" "${pair##*:} -> resources/phpstan/${pair%%:*}" || true
    else
        copy_pair "$canon" "$root" "${pair##*:}" || true
    fi
done

# The script syncs itself, the way skills-sync.sh does — otherwise the one file
# that keeps the others versioned is the one nothing versions.
if [ "$MODE" != "adopt" ]; then
    copy_pair "$PROJECT_ROOT/packages/semitexa-dev/resources/$SELF" "$PROJECT_ROOT/bin/$SELF" "bin/$SELF" || true
fi

if [ "$MODE" = "check" ]; then
    if [ "$drifted" -gt 0 ]; then
        printf '\n%d file(s) out of sync with resources/phpstan.\n' "$drifted" >&2
        printf 'Root is authoritative after an analysis run: bin/phpstan-sync.sh --adopt\n' >&2
        printf 'Canonical is authoritative otherwise:        bin/phpstan-sync.sh\n' >&2
        exit 1
    fi
    printf 'All phpstan files match resources/phpstan.\n'
    exit 0
fi

if [ "$failed" -gt 0 ]; then
    printf '%d file(s) could not be synced. Nothing here is in a known state.\n' "$failed" >&2
    exit 1
fi

if [ "$moved" -eq 0 ]; then
    printf 'All phpstan files already in sync.\n'
else
    printf 'Synced %d file(s).\n' "$moved"
fi
