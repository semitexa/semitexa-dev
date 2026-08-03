#!/usr/bin/env bash
#
# Fan the codereview helper scripts out from their versioned home.
#
# Usage:
#   skills-sync.sh            copy the canonical scripts over every target
#   skills-sync.sh --check    report drift and exit 1 (CI / pre-push gate)
#   skills-sync.sh --list     show where the copies live and their state
#
# Where the canonical copy lives
# ------------------------------
# packages/semitexa-dev/resources/codereview/ — inside a real git repository, in
# the package that already owns ai:verify and the rest of the tooling.
#
# It used to be bin/, which looked reasonable and was not: the project root is
# not a git repository, so bin/ and the agent skill directories are versioned by
# nothing at all. Every fix to these scripts lived on one machine, with no
# history, no review, and nothing to restore from after a reinstall. resources/
# is the right shelf — the structure validator treats that subtree as non-code
# assets, and semitexa-os and semitexa-update already ship shell scripts there.
#
# Why the copies exist at all
# ---------------------------
# Each agent runtime loads skills from its own directory: Claude reads
# .claude/skills, Codex reads ~/.codex/skills. The duplication is forced. What
# was not forced was leaving them to be kept in step by hand, with nothing but a
# sentence in a README asking for it.
#
# That failed exactly as you would expect. Measured 2026-08-03: five locations
# holding three versions of pr-reply.sh. .claude had --kind routing that ~/.codex
# lacked, so review-summary replies from Codex hit the wrong endpoint; ~/.codex
# had reply throttling that .claude lacked, so Claude burst its replies at
# GitHub's secondary rate limiter. Each copy held something the others needed.
#
# --check is the part that matters. The sync is just what you run after it fails.
set -euo pipefail

# Walk up to the project root: the directory holding both bin/semitexa and
# packages/. Resolved rather than assumed, because this script runs from two
# depths — its home under packages/…/resources/codereview/, and the bin/ copy.
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
    printf 'Cannot locate the project root (looked for a parent holding bin/semitexa and packages/).\n' >&2
    exit 1
fi

CANONICAL="$PROJECT_ROOT/packages/semitexa-dev/resources/codereview"

# Destinations for the five review helpers. A path that does not exist is
# skipped, not created — this syncs agent configuration that already exists, it
# does not decide that an agent should be installed.
TARGETS=(
    "$PROJECT_ROOT/bin"
    "$PROJECT_ROOT/.claude/skills/codereview/scripts"
    "$PROJECT_ROOT/.codex/skills/codereview/scripts"
    "$HOME/.codex/skills/codereview/scripts"
    "$PROJECT_ROOT/var/tmp/skills-porting/codereview/scripts"
)

FILES=(
    commit.sh
    pr-comment.sh
    pr-process.sh
    pr-reply.sh
    pr-review.sh
)

# This script itself goes to bin/ only — the agent skill directories invoke the
# review helpers, not the sync tool.
SELF="skills-sync.sh"
SELF_TARGET="$PROJECT_ROOT/bin"

MODE="sync"
case "${1:-}" in
    --check) MODE="check" ;;
    --list)  MODE="list" ;;
    --help|-h) sed -n '2,34p' "$0"; exit 0 ;;
    "") ;;
    *) printf 'Unknown option: %s (expected --check, --list, or no argument)\n' "$1" >&2; exit 1 ;;
esac

for f in "${FILES[@]}" "$SELF"; do
    if [ ! -f "$CANONICAL/$f" ]; then
        printf 'Canonical file missing: %s/%s\n' "$CANONICAL" "$f" >&2
        exit 1
    fi
done

# Copy through a temp file in the destination directory, then rename. bin/ holds
# a copy of this very script, so a plain `cp` can rewrite the file bash is still
# reading and make it execute garbage from the byte offset it had reached.
# rename(2) within one filesystem is atomic and swaps the inode instead.
install_file() {
    local src="$1" dst="$2" tmp
    tmp="$(mktemp "${dst}.XXXXXX")"
    cat "$src" > "$tmp"
    chmod +x "$tmp"
    mv -f "$tmp" "$dst"
}

drifted=0
missing_targets=0
synced=0

process_pair() {
    local src="$1" dst="$2" label="$3"

    if [ -f "$dst" ] && cmp -s "$src" "$dst"; then
        [ "$MODE" = "list" ] && printf 'ok     %s\n' "$label"
        return 0
    fi

    local state="DRIFT"
    [ -f "$dst" ] || state="ABSENT"
    drifted=$((drifted + 1))

    case "$MODE" in
        check|list) printf '%-6s %s\n' "$state" "$label" ;;
        sync)
            install_file "$src" "$dst"
            printf 'synced %s (%s)\n' "$label" "$state"
            synced=$((synced + 1))
            ;;
    esac
}

for target in "${TARGETS[@]}"; do
    if [ ! -d "$target" ]; then
        printf 'skip   %s (not present)\n' "${target/#$HOME/\~}"
        missing_targets=$((missing_targets + 1))
        continue
    fi

    for f in "${FILES[@]}"; do
        process_pair "$CANONICAL/$f" "$target/$f" "${target/#$HOME/\~}/$f"
    done
done

if [ -d "$SELF_TARGET" ]; then
    process_pair "$CANONICAL/$SELF" "$SELF_TARGET/$SELF" "${SELF_TARGET/#$HOME/\~}/$SELF"
fi

printf '\n'

case "$MODE" in
    check)
        if [ "$drifted" -gt 0 ]; then
            printf '%d file(s) out of sync with %s.\n' "$drifted" "${CANONICAL#"$PROJECT_ROOT/"}" >&2
            printf 'Edit the canonical copy, then run: bin/skills-sync.sh\n' >&2
            exit 1
        fi
        printf 'All copies match %s.\n' "${CANONICAL#"$PROJECT_ROOT/"}"
        ;;
    sync)
        if [ "$synced" -eq 0 ]; then
            printf 'Already in sync — nothing copied.\n'
        else
            printf 'Synced %d file(s) from %s.\n' "$synced" "${CANONICAL#"$PROJECT_ROOT/"}"
        fi
        ;;
    list)
        printf 'Canonical: %s\n' "$CANONICAL"
        if [ "$drifted" -gt 0 ]; then
            printf '%d file(s) differ.\n' "$drifted"
        else
            printf 'All copies match.\n'
        fi
        ;;
esac

if [ "$missing_targets" -gt 0 ]; then
    printf '%d target directory/ies absent and skipped.\n' "$missing_targets"
fi
