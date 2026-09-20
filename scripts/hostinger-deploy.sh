#!/usr/bin/env bash
set -euo pipefail

# Compatibility entry point for existing installations. A deploy must never
# reinstall a database, change the site UUID, or replace production settings.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$SCRIPT_DIR/deploy-hostinger.sh" "$@"
