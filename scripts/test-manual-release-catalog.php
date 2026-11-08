<?php
/** One transactional local rehearsal: no persistent catalogue or file changes. */
if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only.');
require_once __DIR__ . '/release-catalog-state.php';
$factory=\Drupal::configFactory();$state=\Drupal::state();$key='nelkano_home.manual_release_deployment';
if ($state->get($key)!==null) throw new RuntimeException('A real catalogue preservation is pending.');
$before=$factory->get('nelkano_home.docs')->getRawData();$updates=$factory->get('nelkano_home.app_updates')->getRawData();
$tx=\Drupal::database()->startTransaction();
try {
  nelkanoPreserveManualReleases('capture');
  foreach(['es','en'] as $lang) $factory->getEditable('nelkano_home.docs')->set($lang.'.releases_items',[['version'=>'qa-imported-config']])->save();
  $factory->getEditable('nelkano_home.app_updates')->set('active.android-arm64',999999)->save();
  nelkanoPreserveManualReleases('capture');
  nelkanoPreserveManualReleases('restore');
  $factory->reset();
  if ($factory->get('nelkano_home.docs')->getRawData()!==$before || $factory->get('nelkano_home.app_updates')->getRawData()!==$updates || $state->get($key)!==null) throw new RuntimeException('Release catalogue changed across config import.');
  echo "PASS: manual releases, hidden SDK, fallback channels and retry snapshot preserved.\n";
} finally {
  $tx->rollBack();$factory->reset();$state->resetCache();
}
