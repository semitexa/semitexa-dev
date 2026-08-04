#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

assert_release_root
require_cmd rsync

# Two modes, because "sync the release root" bundles two very different things.
#
#   (default)     full runtime snapshot, including root config
#   --code-only   application code and scaffold docs: src/, public/, docs/, AGENTS.md et al
#
# The release clone deliberately owns its own .env (isolated *.rls.semitexa.test
# domains) and composer.json/lock (path-repos plus the semitexa/demo require the
# browser smoke depends on). Copying those over from the dev root breaks the
# clone, which is why preflight called none of this and consumer code under src/
# silently never reached the clone at all — the package tree was refreshed every
# release while the app exercising it stayed frozen. --code-only is the half that
# is safe to run on every preflight.
CODE_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --code-only) CODE_ONLY=1 ;;
        *) fail "Unknown argument: $arg (expected --code-only or nothing)" ;;
    esac
done

sync_file() {
    local relative_path="$1"
    mkdir -p "$(dirname "$RELEASE_ROOT/$relative_path")"
    rsync -a "$DEV_ROOT/$relative_path" "$RELEASE_ROOT/$relative_path"
}

# The scaffold docs the framework owns and overwrites, as listed in AGENTS.md §7.
# AI_NOTES.md is deliberately absent: it is created once per project and never
# overwritten, so copying the dev root's notes into the clone would destroy
# whatever the clone had.
SCAFFOLD_DOCS="AGENTS.md AGENTS_DOCTRINE.md AI_ENTRY.md AI_CONTEXT.md AI_REFERENCE.md CLAUDE.md"

# These belong to both modes. They are documentation, not clone-owned config, and
# semitexa-dev's ShippedDocsAnnounceMechanismsTest asserts the host root carries
# the current ones — a clone left on an older scaffold fails the release suite on
# a staleness that has nothing to do with the code being released. That is exactly
# what happened on 2026-08-04: the clone still had an April AI_ENTRY.md and no
# AGENTS.md at all, because --code-only never touched the root and the full sync
# only ever copied AI_ENTRY.md.
sync_scaffold_docs() {
    local doc
    for doc in $SCAFFOLD_DOCS; do
        if [ ! -f "$DEV_ROOT/$doc" ]; then
            warn "Skipping $doc: not present in $DEV_ROOT"
            continue
        fi
        sync_file "$doc"
    done
    ok "Scaffold docs synced ($SCAFFOLD_DOCS)"
}

sync_dir() {
    local relative_path="$1"
    # A directory listed here can legitimately disappear from the dev root (docs/
    # already has). rsync treats a missing source as a hard error, which took the
    # whole script down — and since nothing called it, that stayed invisible.
    # Skipping loudly keeps one retired directory from blocking the sync of src/.
    if [ ! -d "$DEV_ROOT/$relative_path" ]; then
        warn "Skipping $relative_path/: not present in $DEV_ROOT"
        return 0
    fi
    mkdir -p "$RELEASE_ROOT/$relative_path"
    rsync -a --delete "$DEV_ROOT/$relative_path/" "$RELEASE_ROOT/$relative_path/"
}

if [ "$CODE_ONLY" -eq 0 ]; then
    info "Syncing release root runtime snapshot from $DEV_ROOT to $RELEASE_ROOT..."

    sync_file "Dockerfile"
    sync_file "README.md"
    sync_file ".env"
    sync_file ".env.example"
    sync_file "composer.json"
    sync_file "composer.lock"
    sync_file "docker-compose.mysql.yml"
    sync_file "docker-compose.rabbitmq.yml"
    sync_file "docker-compose.redis.yml"
    sync_file "phpstan.neon"
    sync_file "phpunit.xml.dist"
    sync_file "server.php"
    mkdir -p "$RELEASE_ROOT/bin"
    rsync -a "$DEV_ROOT/packages/semitexa-ultimate/bin/semitexa" "$RELEASE_ROOT/bin/semitexa"
else
    info "Syncing release clone application code (src/, public/, docs/) and scaffold docs from $DEV_ROOT..."
fi

sync_scaffold_docs

sync_dir "docs"
sync_dir "public"
sync_dir "src"

if [ "$CODE_ONLY" -eq 0 ]; then
    ok "Release root runtime snapshot synced"
else
    ok "Release clone application code synced (root config left untouched)"
fi
