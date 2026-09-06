#!/usr/bin/env bash
#
# Fan the agent skills out from their versioned home.
#
# Usage:
#   skills-sync.sh            copy the canonical skills over every runtime
#   skills-sync.sh --check    report drift and exit 1 (CI / pre-push gate)
#   skills-sync.sh --list     show where the copies live and their state
#
# Where the canonical copy lives
# ------------------------------
# packages/semitexa-dev/resources/skills/ — inside a real git repository, in the
# package that already owns ai:verify and the rest of the tooling.
#
# It used to be bin/, which looked reasonable and was not: the project root is
# not a git repository, so bin/ and the agent skill directories are versioned by
# nothing at all. Every fix to these lived on one machine, with no history, no
# review, and nothing to restore from after a reinstall. resources/ is the right
# shelf — the structure validator treats that subtree as non-code assets, and
# semitexa-os and semitexa-update already ship shell scripts there.
#
# Why the copies exist at all
# ---------------------------
# Each agent runtime loads skills from its own directory: Claude reads
# .claude/skills, Codex reads ~/.codex/skills. The duplication is forced. What
# was not forced was leaving them to be kept in step by hand.
#
# That failed exactly as you would expect. Measured 2026-08-03: five locations
# holding three versions of pr-reply.sh. Measured again 2026-08-04, after the
# first fix covered only the five codereview scripts and left everything else
# unguarded: four of five skills had drifted, in BOTH directions at once —
# .claude held the newer skill bodies, the Packagist env-var guard and the REST
# workaround in create-review-pr.sh, while ~/.codex held release-sync-masters.sh
# with develop→master divergence detection and the reply-pacing rules that keep
# Claude from bursting at GitHub's secondary rate limiter. Each copy again held
# something the others needed.
#
# So the unit of sync is the whole skill, not a hand-listed set of scripts. A
# file that is not on somebody's list is exactly the file that drifts.
#
# What is deliberately NOT synced
# -------------------------------
# Runtime-specific files. `agents/openai.yaml` is Codex's, has no meaning under
# .claude, and is absent from the canonical tree — and because this script only
# ever copies canonical files over, never prunes what it finds, those survive
# untouched in the runtime that owns them.
#
# --check is the part that matters. The sync is just what you run after it fails.
set -euo pipefail

# Walk up to the project root: the directory holding both bin/semitexa and
# packages/. Resolved rather than assumed, because this script runs from two
# depths — its home under packages/…/resources/, and the bin/ copy.
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

CANONICAL="$PROJECT_ROOT/packages/semitexa-dev/resources/skills"

if [ ! -d "$CANONICAL" ]; then
    printf 'Canonical skills directory missing: %s\n' "$CANONICAL" >&2
    exit 1
fi

# One root per agent runtime. A root that does not exist is skipped, not created
# — this syncs agent configuration that already exists, it does not decide that a
# runtime should be installed.
SKILL_ROOTS=(
    "$PROJECT_ROOT/.claude/skills"
    "$PROJECT_ROOT/.codex/skills"
    "$HOME/.codex/skills"
    # Gemini runs these skills too and was not listed, so its copies drifted
    # while --check reported every copy matching. MEASURED 2026-09-06:
    # ~/.gemini/skills/review-prep/scripts/create-review-pr.sh still called
    # `gh pr edit` — which fails outright on this org — and carried none of the
    # review-request block added since. A sync gate that reports on a subset is
    # worse than none: it answers the question it was asked without covering it.
    "$HOME/.gemini/skills"
)

# The codereview helpers are additionally invoked as bin/<script> — the workflow
# reference tells the agent to run them from there — so bin/ keeps flat copies.
BIN_SCRIPT_SKILL="codereview"
SELF="skills-sync.sh"

MODE="sync"
case "${1:-}" in
    --check) MODE="check" ;;
    --list)  MODE="list" ;;
    --help|-h) sed -n '2,47p' "$0"; exit 0 ;;
    "") ;;
    *) printf 'Unknown option: %s (expected --check, --list, or no argument)\n' "$1" >&2; exit 1 ;;
esac

drifted=0
missing_roots=0
synced=0

# Copy through a temp file in the destination directory, then rename. bin/ holds
# a copy of this very script, so a plain `cp` can rewrite the file bash is still
# reading and make it execute garbage from the byte offset it had reached.
# rename(2) within one filesystem is atomic and swaps the inode instead.
install_file() {
    local src="$1" dst="$2" tmp
    mkdir -p "$(dirname "$dst")"
    tmp="$(mktemp "${dst}.XXXXXX")"
    cat "$src" > "$tmp"
    # Match the source's executable bit rather than forcing +x: a reference
    # document copied as executable is drift the check would then report forever.
    if [ -x "$src" ]; then chmod +x "$tmp"; else chmod 0644 "$tmp"; fi
    mv -f "$tmp" "$dst"
}

process_pair() {
    local src="$1" dst="$2" label="$3"

    # Executable bit as well as content. A copy that lost its execute bit is
    # byte-identical, so cmp alone calls it in sync while every attempt to run it
    # fails with "Permission denied" — drift the check would report as health.
    local exec_ok=1
    if [ -x "$src" ] && [ ! -x "$dst" ]; then exec_ok=0; fi

    if [ -f "$dst" ] && [ "$exec_ok" -eq 1 ] && cmp -s "$src" "$dst"; then
        [ "$MODE" = "list" ] && printf 'ok     %s\n' "$label"
        return 0
    fi

    local state="DRIFT"
    [ -f "$dst" ] || state="ABSENT"
    if [ -f "$dst" ] && [ "$exec_ok" -eq 0 ] && cmp -s "$src" "$dst"; then
        state="NOEXEC"
    fi
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

# Every file under the canonical tree, relative to it. Whole-tree rather than a
# hand-listed set: a file nobody remembered to list is the one that drifts.
canonical_files() {
    (cd "$CANONICAL" && find . -type f -printf '%P\n' | sort)
}

for root in "${SKILL_ROOTS[@]}"; do
    if [ ! -d "$root" ]; then
        printf 'skip   %s (not present)\n' "${root/#$HOME/\~}"
        missing_roots=$((missing_roots + 1))
        continue
    fi

    while IFS= read -r rel; do
        # A file at the top of the canonical tree documents the tree itself
        # (README.md) and belongs to no skill. Skipped explicitly rather than by
        # accident of the -d test below, so adding another one stays predictable.
        case "$rel" in */*) ;; *) continue ;; esac

        skill="${rel%%/*}"
        # Only sync into skills this runtime already has. Installing a skill a
        # runtime never opted into is a different decision from keeping the ones
        # it has in step, and this script only makes the second one.
        [ -d "$root/$skill" ] || continue
        process_pair "$CANONICAL/$rel" "$root/$rel" "${root/#$HOME/\~}/$rel"
    done < <(canonical_files)
done

# Flat bin/ copies: the codereview helpers plus this script itself.
if [ -d "$PROJECT_ROOT/bin" ]; then
    while IFS= read -r rel; do
        case "$rel" in
            "$BIN_SCRIPT_SKILL"/scripts/*)
                base="${rel##*/}"
                process_pair "$CANONICAL/$rel" "$PROJECT_ROOT/bin/$base" "bin/$base"
                ;;
        esac
    done < <(canonical_files)

    process_pair "$CANONICAL/../$SELF" "$PROJECT_ROOT/bin/$SELF" "bin/$SELF"
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

if [ "$missing_roots" -gt 0 ]; then
    printf '%d runtime root(s) absent and skipped.\n' "$missing_roots"
fi
