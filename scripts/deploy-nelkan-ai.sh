#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="${REPO_DIR:-$(dirname -- "$SCRIPT_DIR")}"
cd -- "$REPO_DIR"
php -d display_errors=stderr "$SCRIPT_DIR/check-deploy-worktree.php" "$REPO_DIR"
test -f public_html/sites/default/settings.php
test -f public_html/modules/custom/nelkano_ai/nelkano_ai.info.yml
test -f public_html/modules/custom/nelkano_ai/nelkano_ai.install
for source in public_html/modules/custom/nelkano_ai/src/Controller/AiController.php public_html/modules/custom/nelkano_ai/src/Service/AiQueue.php public_html/modules/custom/nelkano_ai/src/Service/AiAccess.php; do php -l "$source"; done
./vendor/bin/drush --root=public_html pm:enable nelkano_ai --yes
./vendor/bin/drush --root=public_html cache:rebuild
./vendor/bin/drush --root=public_html php:eval 'if (!\Drupal::database()->schema()->tableExists("nelkano_ai_job")) { throw new \RuntimeException("AI table missing"); } \Drupal::service("router.route_provider")->getRouteByName("nelkano_ai.status"); echo "Nelkan IA module, schema and route ready.\n";'
php -d display_errors=stderr "$SCRIPT_DIR/check-deploy-worktree.php" "$REPO_DIR"
echo 'Nelkan IA API deployed. Release records, installers and unrelated configuration were not imported.'
