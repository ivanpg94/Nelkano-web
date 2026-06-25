#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

require_env() {
  local name="$1"
  if [[ -z "${!name:-}" ]]; then
    echo "Missing required environment variable: $name" >&2
    exit 1
  fi
}

require_env DRUPAL_DB_URL
require_env DRUPAL_ADMIN_USER
require_env DRUPAL_ADMIN_PASS

composer install --no-dev --optimize-autoloader

mkdir -p public_html/sites/default/files
chmod 755 public_html/sites/default
chmod 755 public_html/sites/default/files

if [[ ! -f public_html/sites/default/settings.php ]]; then
  cp public_html/sites/default/default.settings.php public_html/sites/default/settings.php
  chmod 644 public_html/sites/default/settings.php
fi

if ! grep -q "Nelkano production settings" public_html/sites/default/settings.php; then
  cat >> public_html/sites/default/settings.php <<'PHP'

// Nelkano production settings.
$settings['trusted_host_patterns'] = [
  '^nelkano\.com$',
  '^www\.nelkano\.com$',
];
$settings['file_chmod_directory'] = 0755;
$settings['file_chmod_file'] = 0644;
PHP
fi

if ! grep -q "Nelkano versioned config sync" public_html/sites/default/settings.php; then
  cat >> public_html/sites/default/settings.php <<'PHP'

// Nelkano versioned config sync.
$settings['config_sync_directory'] = dirname(__DIR__, 3) . '/config/sync';
PHP
fi

if ! vendor/bin/drush --root=public_html status --field=bootstrap 2>/dev/null | grep -q "Successful"; then
  vendor/bin/drush --root=public_html site:install standard \
    --db-url="$DRUPAL_DB_URL" \
    --site-name="Nelkano Emulator" \
    --account-name="$DRUPAL_ADMIN_USER" \
    --account-pass="$DRUPAL_ADMIN_PASS" \
    --yes
fi

vendor/bin/drush --root=public_html pm:enable \
  nelkano_home \
  token \
  metatag \
  metatag_open_graph \
  metatag_twitter_cards \
  simple_sitemap \
  pathauto \
  redirect \
  google_tag \
  --yes

vendor/bin/drush --root=public_html config:set system.site name "Nelkano Emulator" --yes
vendor/bin/drush --root=public_html config:set system.site slogan "Emulador multisistema para Android y Windows" --yes
vendor/bin/drush --root=public_html config:set system.site page.front "/home" --yes
vendor/bin/drush --root=public_html config:set system.performance css.preprocess 1 --yes
vendor/bin/drush --root=public_html config:set system.performance js.preprocess 1 --yes
vendor/bin/drush --root=public_html config:set system.performance cache.page.max_age 3600 --yes

if [[ -f config/sync/system.site.yml ]]; then
  SYNC_UUID="$(grep '^uuid:' config/sync/system.site.yml | awk '{print $2}')"
  if [[ -n "$SYNC_UUID" ]]; then
    vendor/bin/drush --root=public_html config:set system.site uuid "$SYNC_UUID" --yes
  fi
fi

vendor/bin/drush --root=public_html deploy --yes
