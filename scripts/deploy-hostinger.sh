#!/usr/bin/env bash
set -euo pipefail

BRANCH="${1:-main}"
REPO_DIR="${REPO_DIR:-$HOME/repo/nelkano-emulator-main}"
APP_DIR="${APP_DIR:-$HOME/domains/nelkano.com}"

cd "$REPO_DIR"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git reset --hard "origin/$BRANCH"

rsync -a --delete \
  --exclude='public_html/' \
  --exclude='vendor/' \
  web/ "$APP_DIR/"

cd "$APP_DIR"
composer install --no-dev --optimize-autoloader

if ! grep -q "Nelkano versioned config sync" public_html/sites/default/settings.php; then
  cat >> public_html/sites/default/settings.php <<'PHP'

// Nelkano versioned config sync.
$settings['config_sync_directory'] = dirname(__DIR__, 3) . '/config/sync';
PHP
fi

rsync -a --delete \
  "$REPO_DIR/web/modules/custom/nelkano_home/" \
  "$APP_DIR/public_html/modules/custom/nelkano_home/"

if [[ -f config/sync/system.site.yml ]]; then
  SYNC_UUID="$(grep '^uuid:' config/sync/system.site.yml | awk '{print $2}')"
  if [[ -n "$SYNC_UUID" ]]; then
    vendor/bin/drush --root=public_html config:set system.site uuid "$SYNC_UUID" --yes
  fi
fi

vendor/bin/drush --root=public_html deploy --yes
