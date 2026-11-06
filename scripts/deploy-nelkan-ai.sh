#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="${REPO_DIR:-$(dirname -- "$SCRIPT_DIR")}"
cd -- "$REPO_DIR"
php -d display_errors=stderr "$SCRIPT_DIR/check-deploy-worktree.php" "$REPO_DIR"
test -f public_html/sites/default/settings.php
test -f public_html/modules/custom/nelkano_ai/nelkano_ai.info.yml
test -f public_html/modules/custom/nelkano_ai/nelkano_ai.install
for source in public_html/modules/custom/nelkano_ai/src/Controller/AiController.php public_html/modules/custom/nelkano_ai/src/Service/AiQueue.php public_html/modules/custom/nelkano_ai/src/Service/AiAccess.php public_html/modules/custom/nelkano_ai/src/Service/AiActions.php; do php -l "$source"; done
./vendor/bin/drush --root=public_html pm:enable nelkano_ai --yes
# Upgrade only this module; never run unrelated pending application/configuration updates.
./vendor/bin/drush --root=public_html php:eval '\Drupal::moduleHandler()->loadInclude("nelkano_ai", "install"); nelkano_ai_update_10001(); $versions=\Drupal::keyValue("system.schema"); $versions->set("nelkano_ai", max(10001,(int)$versions->get("nelkano_ai"))); echo "Nelkan IA actions schema ready.\n";'

./vendor/bin/drush --root=public_html cache:rebuild
./vendor/bin/drush --root=public_html php:eval 'if (!\Drupal::database()->schema()->tableExists("nelkano_ai_job")) { throw new \RuntimeException("AI table missing"); } \Drupal::service("router.route_provider")->getRouteByName("nelkano_ai.status"); \Drupal::service("router.route_provider")->getRouteByName("nelkano_ai.tool_results"); if (!\Drupal::database()->schema()->fieldExists("nelkano_ai_job","actions_data")) { throw new \RuntimeException("AI actions column missing"); } echo "Nelkan IA module, schema and routes ready.\n";'
php -d display_errors=stderr "$SCRIPT_DIR/check-deploy-worktree.php" "$REPO_DIR"
echo 'Nelkan IA API deployed. Release records, installers and unrelated configuration were not imported.'
