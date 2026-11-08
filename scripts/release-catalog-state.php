<?php
/** Keep administrator-uploaded release records across a source/config deployment. */
function nelkanoPreserveManualReleases(string $action): void {
  if (!in_array($action, ['capture','restore'], true)) throw new RuntimeException('Expected capture or restore.');
  $key = 'nelkano_home.manual_release_deployment';
  $state = \Drupal::state(); $factory = \Drupal::configFactory();
  $uuid = \Drupal::config('system.site')->get('uuid');
  $pending = $state->get($key);
  if ($pending !== null && ($pending['site_uuid'] ?? null) !== $uuid) throw new RuntimeException('Release snapshot belongs to another site.');
  if ($action === 'capture') {
    if ($pending !== null) { echo "Preserving the pending release catalogue snapshot.\n"; return; }
    $docs = $factory->get('nelkano_home.docs'); $languages = [];
    foreach (['es','en'] as $language) $languages[$language] = $docs->get($language . '.releases_items');
    $state->set($key, ['site_uuid'=>$uuid,'languages'=>$languages,'updates'=>$factory->get('nelkano_home.app_updates')->getRawData()]);
    echo "Administrator release catalogue preserved.\n"; return;
  }
  if ($pending === null) { echo "No pending release catalogue to restore.\n"; return; }
  $transaction = \Drupal::database()->startTransaction();
  try {
    $docs = $factory->getEditable('nelkano_home.docs');
    foreach ($pending['languages'] as $language=>$rows) {
      if ($rows === null) $docs->clear($language . '.releases_items');
      else $docs->set($language . '.releases_items', $rows);
    }
    $docs->save();
    $factory->getEditable('nelkano_home.app_updates')->setData($pending['updates'])->save();
    $state->delete($key);
  } catch (Throwable $error) { $transaction->rollBack(); throw $error; }
  echo "Administrator release catalogue restored; uploaded files were not changed.\n";
}
