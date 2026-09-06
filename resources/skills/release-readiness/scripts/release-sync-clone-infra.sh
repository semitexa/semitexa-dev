#!/usr/bin/env bash
#
# Bring the release clone's infrastructure files back in line with the scaffold
# we are about to ship.
#
# The release flow syncs CODE — packages every run, src/ since the sync-release-code
# stage — but never infra, so the clone's Dockerfile and compose files stayed
# frozen at whatever the clone was created with. MEASURED 2026-09-06 against a
# clone that had drifted for months: no node (RenderParityTest silently SKIPPED,
# so the 2026-09-02 preflight reported green on parity it never checked), no pcov,
# no imagemagick webp/jpeg/heic delegates while we were shipping a WebP image
# pipeline, no 512M worker memory_limit, and a compose file still describing
# RabbitMQ two transports ago.
#
# That is the release verifying itself inside an environment older than the one
# it ships. This stage closes the gap: the clone is a disposable approximation of
# a fresh install, so drift here is never wanted, and a changed file simply gets
# copied. The next stage runs `bin/semitexa install`, which rebuilds the image
# when the Dockerfile moved.
#
# .env and .env.default are NOT touched: the clone's own environment carries its
# isolated *.rls.semitexa.test domains and its port, and overwriting those would
# hand the shared local router the dev project's hostnames.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

SCAFFOLD_DIR="${SEMITEXA_SCAFFOLD_DIR:-$DEV_ROOT/packages/semitexa-installer/scaffold}"

[ -d "$SCAFFOLD_DIR" ] || fail "Scaffold directory not found: $SCAFFOLD_DIR"

# Everything the clone runs on. Overlays are included because bin/semitexa picks
# them up by filename — a missing docker-compose.nats.yml means the clone quietly
# runs without the transport the release ships.
INFRA_FILES=(
    Dockerfile
    docker-compose.yml
    docker-compose.test.yml
    docker-compose.mysql.yml
    docker-compose.redis.yml
    docker-compose.nats.yml
    docker-compose.ollama.yml
)

changed=()

for file in "${INFRA_FILES[@]}"; do
    source_file="$SCAFFOLD_DIR/$file"
    target_file="$RELEASE_ROOT/$file"

    if [ ! -f "$source_file" ]; then
        # Not every overlay exists in every scaffold generation; a file we do not
        # ship is not drift.
        continue
    fi

    if [ -f "$target_file" ] && cmp -s "$source_file" "$target_file"; then
        continue
    fi

    cp "$source_file" "$target_file"
    changed+=("$file")
done

# The scaffold's compose set carries a `setup` service: a FIRST-RUN bootstrap for
# a consumer install, which fetches semitexa/ultimate and copies it into the bind
# mount. The release clone is not that. It is a workspace whose vendor/semitexa/*
# entries are SYMLINKS into packages/, and the bootstrap dies on them —
# "cp: target '/var/www/html/./vendor/semitexa/core' is not a directory" — taking
# every service that depends on it down with it, phpunit included. MEASURED here
# the first time this stage synced compose in.
#
# The marker is the mechanism the bootstrap itself offers, and writing it states
# something true: this clone IS bootstrapped, just by the path-repo route rather
# than by composer create-project.
ensure_bootstrap_marker() {
    local marker="$RELEASE_ROOT/var/install/bootstrap-complete.json"

    [ -f "$marker" ] && return 0

    # Only for a clone that really is installed. On anything else the bootstrap
    # SHOULD run, and claiming otherwise would leave it half-built.
    if [ ! -f "$RELEASE_ROOT/composer.json" ] || [ ! -f "$RELEASE_ROOT/vendor/autoload.php" ]; then
        return 0
    fi

    mkdir -p "$RELEASE_ROOT/var/install"
    printf '{\n  "status": "ok",\n  "installed_package": "semitexa/ultimate",\n  "installed_at": "%s",\n  "bootstrap_version": 1,\n  "note": "release clone: installed from path repositories, not by the first-run bootstrap"\n}\n' \
        "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" > "$marker"

    ok "Marked the release clone as bootstrapped so the scaffold's first-run setup stays out of its path repositories"
}

ensure_bootstrap_marker

# The bootstrap does not merely fail on a path-repo clone — it damages it. When
# it ran here it reached `composer install --no-dev` before dying, which
# regenerated the clone's autoloader WITHOUT dev dependencies. The next test run
# then died on "Class PHPUnit\TextUI\Application not found" with every source
# file present and only the classmap wrong. So the marker is written first,
# before this stage runs any compose command that could start `setup`, and the
# check below states plainly when a clone still needs its dev install back.
# A root-run container inside the clone leaves root-owned files behind, and the
# next composer run — which is not root — then dies on "Failed to open stream:
# Permission denied" halfway through vendor/. Same class of problem the release
# scripts already normalise for var/log, and worth doing before anything else
# touches vendor.
normalize_clone_ownership() {
    local uid gid
    uid="$(id -u)"
    gid="$(id -g)"

    find "$RELEASE_ROOT/vendor" "$RELEASE_ROOT/var" -maxdepth 2 ! -user "$uid" -print -quit 2>/dev/null | grep -q . || return 0

    warn "Files inside the release clone are owned by another user; normalizing so composer and the test runner can write"
    docker run --rm -v "$RELEASE_ROOT:/clone" alpine \
        sh -c "chown -R ${uid}:${gid} /clone/vendor /clone/var" >/dev/null 2>&1 \
        || warn "Could not normalize ownership inside the release clone; a later stage may fail on permissions"
}

normalize_clone_ownership

if [ -f "$RELEASE_ROOT/vendor/composer/autoload_classmap.php" ] \
    && ! grep -q 'PHPUnit' "$RELEASE_ROOT/vendor/composer/autoload_classmap.php"
then
    # Every source file is present and only the map is wrong, so the failure
    # arrives as "Class PHPUnit\TextUI\Application not found" with nothing
    # pointing at the autoloader. Say it here instead.
    fail "The release clone's autoloader carries no dev dependencies, so its test stages cannot run. Restore them with: (cd $RELEASE_ROOT && ./bin/semitexa install)"
fi

if [ ${#changed[@]} -eq 0 ]; then
    ok "Release clone infrastructure already matches the scaffold"
    exit 0
fi

warn "Release clone infrastructure had drifted from the scaffold; refreshed:"
for file in "${changed[@]}"; do
    printf '          - %s\n' "$file"
done

# A changed Dockerfile has to become a changed IMAGE here, not later.
#
# `bin/semitexa install` and `docker compose run` both reuse an existing image
# rather than rebuilding it, so leaving the rebuild to a later stage means the
# clone keeps running the environment we just replaced on disk — which reads as
# a successful sync and changes nothing. MEASURED: after the first sync the file
# had node in it and the container still answered "sh: node: not found".
case " ${changed[*]} " in
    *" Dockerfile "*)
        info "Dockerfile changed — rebuilding the clone image now (slow: Swoole is compiled from source)"
        # EVERY service built from this Dockerfile, not just `app`. Compose gives
        # each one its own image — semitexarls-app, semitexarls-phpunit,
        # semitexarls-scheduler — so `build app` leaves the test runner on the
        # old environment, which is precisely the one that matters here.
        # MEASURED: after `build app` succeeded, the phpunit service still
        # answered "sh: node: not found".
        (
            cd "$RELEASE_ROOT"
            build_args=()
            for overlay in \
                docker-compose.yml \
                docker-compose.mysql.yml \
                docker-compose.redis.yml \
                docker-compose.nats.yml \
                docker-compose.ollama.yml \
                docker-compose.test.yml
            do
                [ -f "$RELEASE_ROOT/$overlay" ] && build_args+=(-f "$overlay")
            done

            # BuildKit resolves through the shared local router's DNS and dies
            # there; the classic builder does not. See release-buildkit-dns.
            DOCKER_BUILDKIT="${DOCKER_BUILDKIT:-0}" docker compose "${build_args[@]}" build
        ) || fail "Rebuilding the release clone images failed; its checks would otherwise run on the environment this stage just replaced"
        ok "Release clone image rebuilt from the refreshed Dockerfile"
        ;;
esac

ok "Release clone infrastructure is in step with what this release ships"
