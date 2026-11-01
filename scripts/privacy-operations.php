<?php
/** CLI operational switch and private-register preflight, never a public route. */
declare(strict_types=1);
use Drupal\nelkano_home\Service\AccountErasureLedger;
use Drupal\nelkano_home\Service\ConnectionPrivacyRetention;
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
$action = getenv('NELKANO_PRIVACY_OPS_ACTION') ?: 'status';
if (!in_array($action, ['status','enable','run'], TRUE)) throw new RuntimeException('Expected status, enable or run.');
if ($action === 'enable') {
  if (getenv('NELKANO_CONNECTION_RETENTION_ENABLED') === '0') throw new RuntimeException('Environment explicitly disables privacy maintenance.');
  AccountErasureLedger::seedMirror();
  // Confirm write access with the same OS user that runs Drupal; no account data.
  foreach (AccountErasureLedger::paths() as $path) {
    $probe = dirname($path) . '/.privacy-write-check-' . bin2hex(random_bytes(6));
    $h = fopen($probe, 'x+b');
    if (!$h) throw new RuntimeException('Private register is not writable.');
    chmod($probe, 0600); fclose($h);
    if (!unlink($probe)) throw new RuntimeException('Private register cleanup unavailable.');
  }
  \Drupal::state()->set('nelkano_home.connection_retention_enabled', TRUE);
  \Drupal::state()->set('nelkano_home.connection_retention_next', 0);
}
if ($action === 'run') {
  if (!ConnectionPrivacyRetention::enabled()) throw new RuntimeException('Privacy maintenance is not enabled.');
  \Drupal::state()->set('nelkano_home.connection_retention_next', 0);
  if (!ConnectionPrivacyRetention::runScheduled()) throw new RuntimeException('Privacy maintenance did not finish; inspect the aggregate status before proceeding.');
}
$db = \Drupal::database(); $now = \Drupal::time()->getCurrentTime(); $expired = [];
foreach (['nelkano_stream_session','nelkano_game_session'] as $table) {
  if ($db->schema()->tableExists($table)) {
    $q=$db->select($table,'s');$q->condition($q->orConditionGroup()->condition('expires',$now,'<=')->condition('status','closed'));
    $expired[$table]=(int)$q->countQuery()->execute()->fetchField();
  }
}
echo json_encode(['action'=>$action,'maintenanceEnabled'=>ConnectionPrivacyRetention::enabled(),'privateSuppressionEntries'=>count(AccountErasureLedger::entries()),'independentRegistryOutsideSiteFiles'=>TRUE,'expiredConnectionRows'=>$expired,'lastMaintenance'=>\Drupal::state()->get('nelkano_home.connection_retention_last',NULL),'scope'=>'Own application data only; provider snapshots and mailbox contents are separate.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";