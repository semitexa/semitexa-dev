#!/usr/bin/env bash
# Fail the release when semitexa/installer's scaffold manifest is stale.
#
# Since step 1 of "one source for bin/semitexa" (dec-one-source-for-bin-semitexa,
# 2026-11-19) the installer is a Composer package carrying its own
# resources/scaffold-manifest.json, derived from semitexa/update's. Until step 2
# makes update read from the installer, nothing consumes that manifest, and a
# scaffold change synced with scaffold:sync alone leaves it stale. Tagged stale,
# step 2 would start from wrong hashes (Fable's review of step 1, Minor 1).
#
# --check writes nothing and exits 1 when the derived manifest would differ, 2
# when it cannot read what it derives from. The tool only reads files, so it runs
# on the host like the other structural stages: this stage runs before any
# container is up, and an exec into a stopped app container read as a stale
# manifest (review, dev#283). Only exit 1 is called stale; any other failure is
# reported as the check failing.
# A release whose installer has no derive tool yet (before step 1) passes.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

cd "$DEV_ROOT"

tool="packages/semitexa-installer/tools/derive-manifest.php"
if [ ! -f "$tool" ]; then
    echo "semitexa/installer has no derive tool yet: nothing to check."
    exit 0
fi

status=0
php "$tool" --check || status=$?

case "$status" in
    0)
        exit 0
        ;;
    1)
        cat >&2 <<'MSG'

semitexa/installer's scaffold manifest no longer matches its scaffold or
semitexa/update's history. Derive it again and commit it with the scaffold change:

    bin/semitexa update:scaffold:rebuild
    php packages/semitexa-installer/tools/derive-manifest.php

MSG
        exit 1
        ;;
    *)
        printf '\nCould not check semitexa/installer'"'"'s scaffold manifest: the derive tool exited %s.\nThis is not a stale manifest; its message above says what failed.\n' "$status" >&2
        exit "$status"
        ;;
esac
