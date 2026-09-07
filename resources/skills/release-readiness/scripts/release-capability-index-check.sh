#!/usr/bin/env bash
# Fail the release when the shipped capability index is stale.
#
# The index is generated in the monorepo, where every package is on disk, and
# ships inside semitexa/dev so a project can see what the framework offers
# beyond its own composer.json. A package that gained a capability since the
# last build is simply absent from it — and nothing says so, because the index
# answers confidently with a shrinking subset. Same shape as the gates above:
# the summary line looks identical whether the index is current or a month
# behind.
#
# --check writes nothing; it regenerates in memory and compares the content
# hash, which covers the capabilities only (generated_at changes every run, so
# hashing the whole file would fail every time and the gate would be switched
# off within a week).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

cd "$DEV_ROOT"

if bin/semitexa dev:capability-index:build --check; then
    exit 0
fi

cat >&2 <<'MSG'

The shipped capability index no longer matches the packages on disk.
Regenerate it in the monorepo and commit the result with the release:

    bin/semitexa dev:capability-index:build

MSG
exit 1
