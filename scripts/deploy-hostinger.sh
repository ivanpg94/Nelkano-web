#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd -- "$ROOT"
composer install --no-dev --optimize-autoloader --no-interaction
./vendor/bin/drush --root=public_html deploy --yes
