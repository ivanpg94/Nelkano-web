#!/usr/bin/env bash
set -euo pipefail

# Deploy an already checked-out release. Never reset Git or change site UUIDs.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="${REPO_DIR:-$(dirname -- "$SCRIPT_DIR")}"
APP_DIR="${APP_DIR:-$REPO_DIR}"

test -f "$REPO_DIR/public_html/modules/custom/nelkano_home/src/Controller/ErrorReportApiController.php"
test -f "$APP_DIR/public_html/sites/default/settings.php"

if [[ "$(cd -- "$REPO_DIR" && pwd)" != "$(cd -- "$APP_DIR" && pwd)" ]]; then
  rsync -a \
    --exclude='/.git/' --exclude='/vendor/' --exclude='/.env' \
    --exclude='/public_html/core/' --exclude='/public_html/modules/contrib/' \
    --exclude='/public_html/themes/contrib/' --exclude='/public_html/libraries/' \
    --exclude='/public_html/sites/*/files/' --exclude='/public_html/sites/*/private/' \
    --exclude='/public_html/sites/*/settings*.php' --exclude='/public_html/sites/*/services*.yml' \
    "$REPO_DIR/" "$APP_DIR/"
fi

cd -- "$APP_DIR"
# Keep scripts enabled: they protect deployed code while Composer removes
# the old nelkano/nelkano_home package on existing installations.
composer install --no-dev --optimize-autoloader --no-interaction
test -f public_html/modules/custom/nelkano_home/src/Controller/ErrorReportApiController.php
./vendor/bin/drush --root=public_html deploy --yes
