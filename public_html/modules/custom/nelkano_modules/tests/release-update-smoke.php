<?php

/** Opt-in local Drush QA: synthetic signed releases, no remote downloads/accounts. */
if (PHP_SAPI !== 'cli' || !class_exists('\\Drupal')
  || getenv('NELKANO_LOCAL_MODULE_UPDATE_TEST') !== '1'
  || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal'
  || \Drupal::database()->getConnectionOptions()['host'] !== 'database') {
  throw new RuntimeException('Explicit local Docker opt-in required.');
}
require_once __DIR__ . '/ModuleFixtures.php';

use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\nelkano_modules\Service\ModuleApiCatalog;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use NelkanoModuleTests\FakeDownloadClient;
use NelkanoModuleTests\Packages;

$checks = 0; $created = []; $files = []; $switched = FALSE;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++; echo "PASS: $label\n";
};
$reject = static function (callable $operation, string $label) use ($check): void {
  try { $operation(); }
  catch (InvalidArgumentException) { $check(TRUE, $label); return; }
  throw new RuntimeException('FAIL: accepted ' . $label);
};
// Exact preexisting content/identity state; generated cache/index rows are excluded.
$snapshot = static function (): array {
  $db = \Drupal::database(); $result = [];
  foreach ($db->schema()->findTables('%') as $table) {
    if (!preg_match('/^(node($|_)|users($|_)|user__|user_role($|_)|file_managed$|file_usage$)/', $table)) { continue; }
    $rows = [];
    foreach ($db->select($table, 't')->fields('t')->execute()->fetchAll(PDO::FETCH_ASSOC) as $row) {
      ksort($row); $rows[] = json_encode($row);
    }
    sort($rows); $result[$table] = hash('sha256', implode("\n", $rows));
  }
  ksort($result); return $result;
};
$before = $snapshot();
$originalRegistry = \Drupal::service('nelkano_modules.registry');
$fake = new FakeDownloadClient(); $registry = new ModuleRegistry($fake, new PackageVerifier());
\Drupal::getContainer()->set('nelkano_modules.registry', $registry);
$catalog = new ModuleApiCatalog($registry);
$account = User::load(1);
if (!$account || !$account->hasPermission('administer nelkano modules')) { throw new RuntimeException('Existing local administrator required.'); }
$prefix = 'updateqa' . bin2hex(random_bytes(5)); $key = sodium_crypto_sign_keypair();
$package = static function (string $id, string $version, string $signer, array $platforms, string $sdk, bool $newPermission = FALSE): string {
  $contents = Packages::files($id, $version, $signer, $platforms, $sdk);
  if ($newPermission) {
    $manifest = json_decode($contents['manifest.json'], TRUE, 512, JSON_THROW_ON_ERROR);
    $manifest['capabilities'][] = 'storage.private.cas';
    $contents['manifest.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    unset($contents['signature.json']); $inventory = [];
    foreach ($contents as $path => $data) { $inventory[] = ['path' => $path, 'size' => strlen($data), 'sha256' => hash('sha256', $data)]; }
    $public = hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($signer);
    $contents['signature.json'] = json_encode(['algorithm' => 'Ed25519', 'publicKey' => base64_encode($public), 'files' => $inventory,
      'signature' => base64_encode(sodium_crypto_sign_detached(json_encode($inventory, JSON_UNESCAPED_SLASHES), sodium_crypto_sign_secretkey($signer)))], JSON_UNESCAPED_SLASHES);
  }
  return Packages::zip($contents);
};
$switcher = \Drupal::service('account_switcher');
try {
  $switcher->switchTo($account); $switched = TRUE;
  foreach (['public', 'tester'] as $access) {
    $id = 'qa.' . $prefix . '.' . $access; $counter = 0;
    $inspect = static function (string $bytes, ?\Drupal\node\NodeInterface $module = NULL, ?\Drupal\node\NodeInterface $release = NULL) use ($access, $prefix, $registry, $fake, &$files, &$counter): array {
      $counter++;
      if ($access === 'public') {
        $url = 'https://github.com/example/' . $prefix . '/releases/download/qa/' . $counter . '.nelkmod';
        $fake->responses[$url] = ['status' => 200, 'body' => $bytes];
        return $registry->inspect($url);
      }
      $file = \Drupal::service('file.repository')->writeData($bytes, 'private://nelkano-modules/packages/' . $prefix . '-' . $counter . '.nelkmod', FileExists::Error);
      $file->setTemporary(); $file->save(); $files[] = (int) $file->id();
      return $registry->inspectUpload((int) $file->id(), $module, $release);
    };
    $oldBytes = $package($id, '0.1.0', $key, ['android', 'jvm-desktop'], '0.1.0');
    $oldVerified = $inspect($oldBytes);
    $module = $registry->saveModule(NULL, NULL, ['title' => 'Synthetic module update QA', 'description' => 'Local synthetic update fixture.',
      'source' => '', 'published' => TRUE, 'access' => $access, 'langcode' => 'es'], $oldVerified);
    $created[] = (int) $module->id(); $old = $registry->releases($module)[0];
    $oldFields = [$old->id(), $old->get('field_release_hash')->value, $old->get('field_release_version')->value, $old->getOwnerId()];
    $newBytes = $package($id, '0.1.1', $key, ['android'], '0.2.0', TRUE);
    $new = $registry->saveVersion($module, NULL, NULL, $inspect($newBytes, $module), 'Nuevo permiso de almacenamiento y corrección sintética.');
    $check(count($registry->releases($module)) === 2, "$access: new release added without replacing old");
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$old->id()]); $old = Node::load($old->id());
    $check($oldFields === [$old->id(), $old->get('field_release_hash')->value, $old->get('field_release_version')->value, $old->getOwnerId()]
      && $old->get('field_release_hash')->value === hash('sha256', $oldBytes), "$access: old ID/version/owner/hash preserved");
    $query = ['platform' => 'android', 'sdks' => ['0.1.0', '0.2.0']];
    $latest = $catalog->summary($module, $query)['latest_release'];
    $check($latest['version'] === '0.1.1' && in_array('storage.private.cas', $latest['capabilities'], TRUE), "$access: latest compatible exposes new permission");
    $query['platform'] = 'jvm-desktop';
    $check($catalog->summary($module, $query)['latest_release']['version'] === '0.1.0', "$access: desktop retains compatible older version");
    $query = ['platform' => 'android', 'sdks' => ['0.1.0']];
    $check($catalog->summary($module, $query)['latest_release']['version'] === '0.1.0', "$access: exact SDK uses older compatible release");
    $full = $catalog->version($module, '0.1.1', ['platform' => 'android', 'sdks' => ['0.2.0']]);
    $check($full['notes'] === 'Nuevo permiso de almacenamiento y corrección sintética.' && $full['sha256'] === hash('sha256', $newBytes)
      && $full['download_auth'] === ($access === 'tester' ? 'session' : 'none'), "$access: changelog/hash/download authorization retained");
    $changed = $package($id, '0.1.1', $key, ['android'], '0.2.0');
    $reject(fn() => $registry->saveVersion($module, NULL, NULL, $inspect($changed, $module), ''), "$access: same version different bytes rejected");
    $reject(fn() => $registry->saveVersion($module, $new, (int) $new->getRevisionId(), $inspect($changed, $module, $new), ''), "$access: repair cannot replace signed bytes");
    $foreign = $package($id, '0.1.2', sodium_crypto_sign_keypair(), ['android'], '0.2.0');
    $reject(fn() => $registry->saveVersion($module, NULL, NULL, $inspect($foreign, $module), ''), "$access: foreign signing author rejected");
    $check(count($registry->releases($module)) === 2, "$access: rejected releases leave history unchanged");
  }
}
finally {
  foreach ($created as $nid) { \Drupal::entityTypeManager()->getStorage('node')->resetCache([$nid]); Node::load($nid)?->delete(); }
  foreach ($files as $fid) { File::load($fid)?->delete(); }
  if ($switched) { $switcher->switchBack(); }
  \Drupal::getContainer()->set('nelkano_modules.registry', $originalRegistry);
  $check($before === $snapshot(), 'cleanup preserves every preexisting content/account/file table');
}
echo "SUCCESS: $checks local module update checks; synthetic nodes/files removed.\n";