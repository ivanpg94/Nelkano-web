param(
  [string]$SiteName = "Nelkano",
  [string]$AdminUser = "admin",
  [string]$AdminPassword = "admin12345"
)

$ErrorActionPreference = "Stop"

docker compose up -d

Write-Host "Waiting for Drupal container..."
for ($i = 0; $i -lt 60; $i++) {
  $status = docker inspect -f "{{.State.Running}}" nelkano-drupal 2>$null
  if ($status -eq "true") {
    break
  }
  Start-Sleep -Seconds 2
}

docker compose exec -T drupal sh -lc "composer require drush/drush:^13 drupal/metatag drupal/simple_sitemap drupal/pathauto drupal/redirect drupal/google_tag --no-interaction"

$installed = docker compose exec -T drupal sh -lc "test -f web/sites/default/settings.php && grep -q 'database' web/sites/default/settings.php && echo yes || echo no"

if ($installed.Trim() -ne "yes") {
  docker compose exec -T drupal sh -lc "mkdir -p web/sites/default/files && chown -R www-data:www-data web/sites/default/files"
  docker compose exec -T drupal sh -lc "vendor/bin/drush site:install standard --db-url=mysql://nelkano:nelkano@database/nelkano_drupal --site-name='$SiteName' --account-name='$AdminUser' --account-pass='$AdminPassword' -y"
}

docker compose exec -T drupal sh -lc "grep -q 'Nelkano versioned config sync' web/sites/default/settings.php || cat >> web/sites/default/settings.php <<'PHP'

// Nelkano versioned config sync.
\$settings['config_sync_directory'] = dirname(__DIR__, 3) . '/config/sync';
PHP"

docker compose exec -T drupal sh -lc "vendor/bin/drush en nelkano_home -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush en token metatag metatag_open_graph metatag_twitter_cards pathauto redirect simple_sitemap google_tag -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set system.site page.front /home -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set block.block.olivero_page_title status 0 -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set system.site name 'Nelkano Emulator' -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set system.site slogan 'Emulador gratis para Android y Windows' -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set system.performance css.preprocess 1 -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set system.performance js.preprocess 1 -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set system.performance cache.page.max_age 3600 -y"
docker compose exec -T drupal sh -lc "test -f config/sync/system.site.yml && vendor/bin/drush config:set system.site uuid \$(grep '^uuid:' config/sync/system.site.yml | awk '{print \$2}') -y || true"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set metatag.metatag_defaults.global tags.description 'Nelkano Emulator es un emulador gratis para Android y Windows con cores para CHIP-8, Game Boy, Game Boy Color, Game Boy Advance, NES y Nintendo DS.' -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set metatag.metatag_defaults.global tags.robots 'index, follow, max-image-preview:large' -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set metatag.metatag_defaults.front tags.title 'Nelkano Emulator - Emulador gratis para Android y Windows' -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush config:set metatag.metatag_defaults.front tags.description 'Descarga Nelkano Emulator para Android y Windows. Cores disponibles para CHIP-8, Game Boy, Game Boy Color, Game Boy Advance, NES y Nintendo DS.' -y"
docker compose exec -T drupal sh -lc "vendor/bin/drush simple-sitemap:generate -y || true"
docker compose exec -T drupal sh -lc "vendor/bin/drush cr"

Write-Host ""
Write-Host "Drupal is ready:"
Write-Host "  Site:  http://localhost:8088/"
Write-Host "  EN:    http://localhost:8088/en"
Write-Host "  Admin: http://localhost:8088/user/login"
Write-Host "  Home editor: http://localhost:8088/admin/config/nelkano/home"
Write-Host "  User:  $AdminUser"
Write-Host "  Pass:  $AdminPassword"
