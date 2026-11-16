#!/bin/sh
# Unpack the Semitexa agent skills where an agent reads them: in this project or
# in the agent's home directory. Any agent: name its skills directory with
# --into, or use a known runtime with --agent.
#
#   sh vendor/semitexa/dev/resources/skills-install.sh                list what can be installed
#   sh vendor/semitexa/dev/resources/skills-install.sh --agent=claude
#   sh vendor/semitexa/dev/resources/skills-install.sh --agent=codex --global
#   sh vendor/semitexa/dev/resources/skills-install.sh --into=.my-agent/skills --skill=make-it
#   sh vendor/semitexa/dev/resources/skills-install.sh --check --agent=claude
#   sh vendor/semitexa/dev/resources/skills-install.sh --remove --agent=claude
#
# Agents: claude (.claude/skills, ~/.claude/skills), codex (.codex/skills,
# ~/.codex/skills), gemini (.gemini/skills, ~/.gemini/skills), agents
# (.agents/skills, ~/.agents/skills). --global picks the home directory.
#
# NEVER COMMITTED. An installed skill is a copy of what semitexa/dev ships, so it
# belongs to no commit: each copy is added to the .git/info/exclude (never
# committed itself, unlike .gitignore) of whichever git checkout holds it, the
# project's or any other, as git itself names the path; a path that checkout
# already tracks is refused, --force or not, and --remove leaves it alone. A copy
# no checkout holds is outside every repository. Every copy carries a marker (.semitexa-skill) with the hash
# of its source and of what was installed, so --check tells "a newer version is
# available" from "edited here", and an edit is never overwritten silently.
#
# Host-side on purpose: bin/semitexa runs PHP commands in the app container,
# whose home directory is not yours, so a --global install from there would
# land in the container and vanish. Plain POSIX sh; on Windows, Git Bash.
#
# Options:
#   --agent=NAME | --into=DIR   where the skills go (DIR relative to the project)
#   --global                    the agent's home directory instead of the project
#   --skill=NAME                only this skill (repeatable); default: all offered here
#   --list                      what can be installed here, and what is (the default)
#   --check                     report installed copies that are stale or edited; exit 1 if any
#   --remove                    delete installed copies and their exclude lines; exit 1 if one is kept
#   --force                     replace a copy that was edited, or a directory not installed here
set -eu

MARKER='.semitexa-skill'

say()  { printf '%s\n' "$*"; }
fail() { printf 'skills-install: %s\n' "$*" >&2; exit 1; }

RES_DIR="$(cd "$(dirname "$0")" && pwd)"
MANIFEST="${SEMITEXA_SKILLS_MANIFEST:-$RES_DIR/skills.manifest}"
[ -f "$MANIFEST" ] || fail "manifest missing: $MANIFEST"
MANIFEST_DIR="$(cd "$(dirname "$MANIFEST")" && pwd)"

# Where this script lives says where it runs: the framework workspace has
# semitexa-dev under packages/, a project has it under vendor/.
case "$RES_DIR" in
    */packages/semitexa-dev/resources) CONTEXT=workspace; ROOT="${RES_DIR%/packages/semitexa-dev/resources}" ;;
    */vendor/semitexa/dev/resources)   CONTEXT=project;   ROOT="${RES_DIR%/vendor/semitexa/dev/resources}" ;;
    *) CONTEXT="${SEMITEXA_SKILLS_CONTEXT:-}"; ROOT="${SEMITEXA_PROJECT_ROOT:-}"
       [ -n "$CONTEXT" ] && [ -n "$ROOT" ] || fail "cannot tell the workspace from a project: $RES_DIR is under neither packages/semitexa-dev nor vendor/semitexa/dev" ;;
esac
ROOT="$(cd "$ROOT" && pwd)"

ACTION=''; AGENT=''; INTO=''; GLOBAL=0; FORCE=0; ONLY=''
for arg in "$@"; do
    case "$arg" in
        --agent=*)  AGENT="${arg#--agent=}" ;;
        --into=*)   INTO="${arg#--into=}" ;;
        --global)   GLOBAL=1 ;;
        --skill=*)  ONLY="$ONLY ${arg#--skill=} " ;;
        --list)     ACTION=list ;;
        --check)    ACTION=check ;;
        --remove)   ACTION=remove ;;
        --force)    FORCE=1 ;;
        --help|-h)  sed -n '2,37p' "$0"; exit 0 ;;
        *) fail "unknown option: $arg (see --help)" ;;
    esac
done
[ -z "$AGENT" ] || [ -z "$INTO" ] || fail "--agent and --into name the same thing: give one"
if [ -z "$ACTION" ]; then
    if [ -n "$AGENT$INTO" ]; then ACTION=install; else ACTION=list; fi
fi

agent_dir() {
    case "$1" in
        claude|codex|gemini|agents) ;;
        *) fail "unknown agent '$1': name its skills directory with --into=DIR instead" ;;
    esac
    if [ "$GLOBAL" = 1 ]; then printf '%s/.%s/skills' "$HOME" "$1"; else printf '%s/.%s/skills' "$ROOT" "$1"; fi
}

TARGET=''
if [ -n "$AGENT" ]; then
    TARGET="$(agent_dir "$AGENT")"
elif [ -n "$INTO" ]; then
    [ "$GLOBAL" = 0 ] || fail "--global goes with --agent; --into already names the directory"
    case "$INTO" in
        /*) TARGET="$INTO" ;;
        "~"/*) TARGET="$HOME/${INTO#\~/}" ;;
        *) TARGET="$ROOT/$INTO" ;;
    esac
fi
[ "$ACTION" = list ] || [ -n "$TARGET" ] || fail "where to? --agent=claude|codex|gemini|agents or --into=DIR (no runtime is guessed)"

in_project() { case "$1" in "$ROOT"/*) return 0 ;; *) return 1 ;; esac; }

# The git checkout that holds the skills directory, asked of git in the directory
# itself, so ./ and .. segments, symlinks, a project nested in a larger
# repository and a directory inside another checkout all come out as git sees
# them: REPO is that checkout's top, PREFIX the directory's path in it (with a
# trailing slash, or empty at the top). Both empty when no checkout holds it, or
# when the directory is not there (then nothing is installed in it either).
REPO=''; PREFIX=''
find_repo() {
    REPO=''; PREFIX=''
    [ -d "$TARGET" ] || return 0
    REPO="$(git -C "$TARGET" rev-parse --show-toplevel 2>/dev/null)" || { REPO=''; return 0; }
    PREFIX="$(git -C "$TARGET" rev-parse --show-prefix)"
}

hash_file() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1
    else shasum -a 256 | cut -d' ' -f1; fi
}
# One hash for a directory's files and their relative paths, the marker left out.
tree_hash() {
    (cd "$1" && find . -type f ! -name "$MARKER" | LC_ALL=C sort | while IFS= read -r f; do
        printf '%s %s\n' "$f" "$(hash_file < "$f")"
    done) | hash_file
}
marker_value() { sed -n "s/^$2=//p" "$1/$MARKER" 2>/dev/null | head -n 1; }

manifest_rows() { grep -v '^[[:space:]]*#' "$MANIFEST" | awk 'NF >= 3 {print $1, $2, $3}'; }

# The edition of <name> this place installs, as "scope source", or nothing. A
# project, and any --global install, takes the project edition only; the
# workspace takes its own edition, or the project one when there is no other.
pick() {
    _want="$1"
    _project="$(manifest_rows | awk -v n="$_want" '$1 == n && $2 == "project" {print $2, $3; exit}')"
    _workspace="$(manifest_rows | awk -v n="$_want" '$1 == n && $2 == "workspace" {print $2, $3; exit}')"
    if [ "$CONTEXT" = project ] || [ "$GLOBAL" = 1 ]; then
        printf '%s' "$_project"
    elif [ -n "$_workspace" ]; then
        printf '%s' "$_workspace"
    else
        printf '%s' "$_project"
    fi
}

# Every skill name this place can install, once each.
offered_names() {
    manifest_rows | awk '{print $1}' | awk '!seen[$0]++' | while IFS= read -r _n; do
        [ -n "$(pick "$_n")" ] && printf '%s\n' "$_n"
    done
}

# Names asked for that this place does not install: said, not skipped.
refuse_unknown() {
    for want in $ONLY; do
        [ -n "$(manifest_rows | awk -v n="$want" '$1 == n')" ] || fail "no skill named '$want' (run without options for the list)"
        [ -n "$(pick "$want")" ] && continue
        if [ "$GLOBAL" = 1 ]; then
            fail "'$want' is a workspace skill: installed in the workspace only, never globally, where it would act in every project"
        fi
        fail "'$want' belongs to the framework workspace (pull requests, review, releases), not to a project"
    done
}

exclude_file() {
    _ef="$(git -C "$REPO" rev-parse --git-path info/exclude)"
    case "$_ef" in /*) ;; *) _ef="$REPO/$_ef" ;; esac
    printf '%s' "$_ef"
}

# The copy of skill $1, ignored by the checkout that holds it.
exclude_add() {
    _ef="$(exclude_file)"; _line="/$PREFIX$1/"
    mkdir -p "$(dirname "$_ef")"
    touch "$_ef"
    grep -qxF "$_line" "$_ef" || printf '%s\n' "$_line" >> "$_ef"
}

exclude_drop() {
    _ef="$(exclude_file)"; _line="/$PREFIX$1/"
    [ -f "$_ef" ] || return 0
    _tmp="$_ef.tmp.$$"
    grep -vxF "$_line" "$_ef" > "$_tmp" || true
    mv -f "$_tmp" "$_ef"
}

# Whether the checkout that holds the skills directory tracks anything of skill $1.
tracked() {
    [ -n "$REPO" ] || return 1
    [ -n "$(git -C "$REPO" ls-files -- "$PREFIX$1" 2>/dev/null | head -n 1)" ]
}

install_one() {
    _name="$1"; _src="$MANIFEST_DIR/$2"; _dst="$TARGET/$_name"
    [ -d "$_src" ] || fail "source of '$_name' missing: $_src"
    if tracked "$_name"; then
        fail "$_dst is tracked by git ($REPO): an installed copy must not live in a commit; pick another directory"
    fi
    if [ -e "$_dst" ]; then
        if [ ! -f "$_dst/$MARKER" ] && [ "$FORCE" = 0 ]; then
            fail "$_dst exists and was not installed by this script; --force replaces it"
        fi
        if [ -f "$_dst/$MARKER" ] && [ "$(tree_hash "$_dst")" != "$(marker_value "$_dst" tree)" ] && [ "$FORCE" = 0 ]; then
            fail "$_dst was edited after it was installed; --force replaces the edit"
        fi
    fi
    _tmp="$TARGET/.$_name.installing.$$"
    rm -rf "$_tmp"
    cp -R "$_src" "$_tmp"
    rm -f "$_tmp/$MARKER"
    {
        printf 'source=%s\n' "$(tree_hash "$_src")"
        printf 'tree=%s\n' "$(tree_hash "$_tmp")"
        printf 'from=%s\n' "${2}"
    } > "$_tmp/$MARKER"
    rm -rf "$_dst"
    mv "$_tmp" "$_dst"
    if [ -n "$REPO" ]; then
        exclude_add "$_name"
    elif in_project "$_dst"; then
        say "  note: no git checkout holds $TARGET, so nothing marks $_name as ignored"
    fi
    say "installed $_name -> $_dst"
}

case "$ACTION" in
    list)
        say "Skills for this $CONTEXT ($ROOT):"
        _any=0
        for _n in $(offered_names); do
            _any=1; say "  $_n ($(pick "$_n" | awk '{print $1}'))"
        done
        [ "$_any" = 1 ] || say "  none yet"
        if [ -n "$TARGET" ]; then
            say "Installed in $TARGET:"
            for m in "$TARGET"/*/"$MARKER"; do
                [ -f "$m" ] || continue
                say "  $(basename "$(dirname "$m")")"
            done
        fi
        say "Install: --agent=claude|codex|gemini|agents [--global], or --into=DIR."
        ;;
    check)
        refuse_unknown
        _bad=0
        for m in "$TARGET"/*/"$MARKER"; do
            [ -f "$m" ] || continue
            _dir="$(dirname "$m")"; _name="$(basename "$_dir")"
            if [ -n "$ONLY" ]; then case "$ONLY" in *" $_name "*) ;; *) continue ;; esac; fi
            _from="$(marker_value "$_dir" from)"
            if [ "$(tree_hash "$_dir")" != "$(marker_value "$_dir" tree)" ]; then
                say "edited here: $_dir (an install will not replace it without --force)"; _bad=1
            fi
            if [ -d "$MANIFEST_DIR/$_from" ] && [ "$(tree_hash "$MANIFEST_DIR/$_from")" != "$(marker_value "$_dir" source)" ]; then
                say "newer version available: $_name (install again to update)"; _bad=1
            fi
        done
        [ "$_bad" = 0 ] && say "installed skills in $TARGET are current"
        exit "$_bad"
        ;;
    remove)
        refuse_unknown
        find_repo
        _kept=0
        for m in "$TARGET"/*/"$MARKER"; do
            [ -f "$m" ] || continue
            _dir="$(dirname "$m")"; _name="$(basename "$_dir")"
            if [ -n "$ONLY" ]; then case "$ONLY" in *" $_name "*) ;; *) continue ;; esac; fi
            if tracked "$_name"; then
                # Someone committed it: deleting it is a change to that repository, not ours to make.
                say "kept $_dir: git tracks it in $REPO, so removing it is that repository's change"; _kept=1
                continue
            fi
            rm -rf "$_dir"
            [ -z "$REPO" ] || exclude_drop "$_name"
            say "removed $_name from $TARGET"
        done
        exit "$_kept"
        ;;
esac

[ "$ACTION" = install ] || exit 0

refuse_unknown
_todo=''
for _n in $(offered_names); do
    if [ -n "$ONLY" ]; then case "$ONLY" in *" $_n "*) ;; *) continue ;; esac; fi
    _todo="$_todo $_n"
done
[ -n "$_todo" ] || { say "nothing to install here (run without options for the list)"; exit 0; }
# The directory first, so git can say which checkout holds it before anything lands there.
mkdir -p "$TARGET"
find_repo
for _n in $_todo; do
    install_one "$_n" "$(pick "$_n" | awk '{print $2}')"
done
