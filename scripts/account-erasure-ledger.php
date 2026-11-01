<?php
/**
 * Administrative backup-restoration helper, executed through Drush.
 *
 * NELKANO_ERASURE_ACTION=export emits a minimal private JSON register.
 * NELKANO_ERASURE_ACTION=reapply plus NELKANO_ERASURE_SOURCE=/secure/current.json
 * previews matching restored accounts. Add -- --apply to php:script to apply.
 * The source must be the CURRENT register saved separately from the old backup.
 */
declare(strict_types=1);
use Drupal\nelkano_home\Service\AccountErasure;
use Drupal\nelkano_home\Service\AccountErasureLedger;

if (PHP_SAPI !== 'cli') { throw new RuntimeException('Command-line use only.'); }
$action = getenv('NELKANO_ERASURE_ACTION') ?: 'export';
if ($action === 'export') {
  echo json_encode(['schemaVersion'=>1,'exportedAt'=>time(),'requests'=>AccountErasureLedger::entries()], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
  return;
}
if ($action !== 'reapply') { throw new InvalidArgumentException('Expected export or reapply.'); }
$path = getenv('NELKANO_ERASURE_SOURCE') ?: '';
// The separately retained live register is always merged, even when an operator
// supplies an older exported file. Restoring the site must not omit newer erasures.
$requests = AccountErasureLedger::entries();
if ($path !== '') {
  if (is_link($path) || !is_file($path) || filesize($path)>16777216) throw new RuntimeException('Provide a private suppression register file.');
  $real=realpath($path);$public=realpath(DRUPAL_ROOT);
  if (!$real || ($public && str_starts_with($real,$public.DIRECTORY_SEPARATOR))) throw new RuntimeException('The register must be outside the public web root.');
  $register=json_decode(file_get_contents($real),TRUE,32,JSON_THROW_ON_ERROR);
  if (($register['schemaVersion']??NULL)!==1 || !is_array($register['requests']??NULL) || !is_int($register['exportedAt']??NULL) || $register['exportedAt']>time()+60) throw new RuntimeException('Invalid suppression register.');
  foreach ($register['requests'] as $row) {
    if (($row['version']??NULL)!==1 || !preg_match('/^[a-f0-9-]{36}$/D',(string)($row['account_uuid']??'')) || !is_int($row['requested_at']??NULL) || $row['requested_at']<1 || $row['requested_at']>$register['exportedAt']) throw new RuntimeException('Invalid suppression request.');
    $requests[]=$row;
  }
}
$storage=\Drupal::entityTypeManager()->getStorage('user'); $accounts=[];
foreach ($requests as $row) {
  if (($row['version']??NULL)!==1 || !preg_match('/^[a-f0-9-]{36}$/D',(string)($row['account_uuid']??'')) || !is_int($row['requested_at']??NULL) || $row['requested_at']<1 || $row['requested_at']>time()+60) { throw new RuntimeException('Invalid suppression request.'); }
  foreach ($storage->loadByProperties(['uuid'=>$row['account_uuid']]) as $account) {
    if (!AccountErasure::selfServiceAllowed($account)) { throw new RuntimeException('A protected administrative account requires manual review; nothing has been erased.'); }
    AccountErasure::preflight($account); $accounts[(int)$account->id()]=$account;
  }
}
$arguments=array_merge(is_array($extra??NULL)?$extra:[],$_SERVER['argv']??[]);
$apply=in_array('--apply',$arguments,TRUE);
if (in_array('--require-clean',$arguments,TRUE) && $accounts) throw new RuntimeException('Restored accounts match suppression requests. Reapply deletions before reopening the site.');
if ($apply) { foreach($accounts as $account) { AccountErasure::erase($account); } }
echo json_encode(['applied'=>$apply,'restoredAccountsMatched'=>count($accounts),'note'=>'Use the latest separately retained register before reopening a restored site. This does not delete or modify provider snapshots.'],JSON_THROW_ON_ERROR)."\n";
