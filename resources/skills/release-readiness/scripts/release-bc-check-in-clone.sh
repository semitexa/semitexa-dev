#!/usr/bin/env bash
# roave/backward-compatibility-check for the package in the current directory,
# run inside the release clone's app container: the image carries the bcmath
# and intl it needs (2026-10-30), the host's PHP may not. release-bc-gate.php
# calls it per package, as `cd <package> && release-bc-check-in-clone.sh
# --from=<tag> --to=HEAD --format=json`, and only for a package that had @api
# at its previous tag, so roave is installed (into var/tools/roave of the clone)
# the first time one does.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/common.sh"

case "$PWD/" in
    "$RELEASE_ROOT"/*) ;;
    *) echo "not inside the release clone: $PWD" >&2; exit 2 ;;
esac
inside="/var/www/html/${PWD#"$RELEASE_ROOT"/}"
tool=/var/www/html/var/tools/roave

if ! compose exec -T app test -x "$tool/vendor/bin/roave-backward-compatibility-check"; then
    compose exec -T app composer require --no-interaction --no-progress --working-dir="$tool" \
        'roave/backward-compatibility-check:^8.27' >&2
fi

# Its exit status is the answer: 0 no break, 3 breaks, anything else not checked.
compose exec -T -w "$inside" app php "$tool/vendor/bin/roave-backward-compatibility-check" "$@"
