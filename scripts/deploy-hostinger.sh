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
# Plain Git transports the installers in parts; no Git LFS or extra tools.
php scripts/materialize-release-artifacts.php
# Publish the matching Java/GCC sources without carrying private Nelkano code.
php scripts/materialize-release-artifacts.php public_html/third-party-sources
test -s public_html/third-party-sources/nelkano-runtimes-2026-10-31.zip
php scripts/verify-release-bundle.php
NELKANO_RELEASE_ASSETS_CHECK_ONLY=1 ./vendor/bin/drush --root=public_html php:script scripts/install-release-assets.php
# Keep the site closed on any failed update or restored-account check.
./vendor/bin/drush --root=public_html state:set system.maintenance_mode 1
./vendor/bin/drush --root=public_html cache:rebuild
# Keep scripts enabled: they protect deployed code while Composer removes
# the old nelkano/nelkano_home package on existing installations.
composer install --no-dev --optimize-autoloader --no-interaction
test -f public_html/modules/custom/nelkano_home/src/Controller/ErrorReportApiController.php
# Verify all referenced APKs before importing their release records.
NELKANO_RELEASE_ASSETS_CHECK_ONLY=1 ./vendor/bin/drush --root=public_html php:script scripts/install-release-assets.php
./vendor/bin/drush --root=public_html php:script scripts/install-release-assets.php
./vendor/bin/drush --root=public_html deploy --yes
# Enable bounded privacy maintenance only after deployment/configuration succeeds.
NELKANO_PRIVACY_OPS_ACTION=enable ./vendor/bin/drush --root=public_html php:script scripts/privacy-operations.php
# A restored site must not reopen with accounts present in the current register.
NELKANO_ERASURE_ACTION=reapply ./vendor/bin/drush --root=public_html php:script scripts/account-erasure-ledger.php -- --require-clean
# Configuration export does not contain Book nodes. Import by UUID only after
# the bundle, fields, languages and text formats have been installed.
./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml --dry-run
./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml
./vendor/bin/drush --root=public_html cache:rebuild

# Reopen only after the independent suppression register and content passed.
./vendor/bin/drush --root=public_html state:set system.maintenance_mode 0
./vendor/bin/drush --root=public_html cache:rebuild
