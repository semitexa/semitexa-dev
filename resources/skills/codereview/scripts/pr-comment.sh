#!/usr/bin/env bash
# Backwards-compatible alias for bin/pr-reply.sh.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec "$SCRIPT_DIR/pr-reply.sh" "$@"
