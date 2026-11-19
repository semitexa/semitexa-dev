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
# --check writes nothing and exits 1 when the derived manifest would differ.
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

if docker compose exec -T app php "$tool" --check; then
    exit 0
fi

cat >&2 <<'MSG'

semitexa/installer's scaffold manifest no longer matches its scaffold or
semitexa/update's history. Derive it again and commit it with the scaffold change:

    bin/semitexa update:scaffold:rebuild
    docker compose exec -T app php packages/semitexa-installer/tools/derive-manifest.php

MSG
exit 1
