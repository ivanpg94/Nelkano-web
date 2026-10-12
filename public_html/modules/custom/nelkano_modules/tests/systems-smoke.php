<?php
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_MODULE_CATALOG_TEST') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') { throw new RuntimeException('Explicit local Docker opt-in required.'); }
require_once __DIR__ . '/ModuleFixtures.php';
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\ModuleSystems;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\search_api\Entity\Index;
use Drupal\user\Entity\User;
use NelkanoModuleTests\FakeDownloadClient;
use NelkanoModuleTests\Packages;

$checks = 0;
$check = static function ($ok, $label) use (&$checks) { if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } $checks++; echo 'PASS: ' . $label . PHP_EOL; };
$snapshot = static function () {
  $db = \Drupal::database(); $data = [];
  foreach ($db->schema()->findTables('%') as $table) {
    if (!preg_match('/^(node|users|user__|taxonomy_term|nelkano_)/', $table)) { continue; }
    $rows = [];
    foreach ($db->select($table, 't')->fields('t')->execute()->fetchAll(PDO::FETCH_ASSOC) as $row) { ksort($row); $rows[] = json_encode($row); }
    sort($rows); $data[$table] = hash('sha256', implode("\n", $rows));
  }
  return $data;
};
$before = $snapshot();
\Drupal::moduleHandler()->loadInclude('nelkano_home', 'install');
for ($repeat = 0; $repeat < 2; $repeat++) {
  $sandbox = [];
  for ($step = 0; $step < 100; $step++) {
    nelkano_home_update_11038($sandbox);
    if (($sandbox['#finished'] ?? 1) == 1) { break; }
  }
  $check(($sandbox['#finished'] ?? 1) == 1, 'update completes and can be repeated');
}
$after = $snapshot();
$check(array_intersect_key($after, $before) === $before, 'every pre-existing content table unchanged by update');
$storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', 'field_module_systems');
$check($storage->getType() === 'list_string' && $storage->getCardinality() === -1, 'native unlimited list field');
$check(Index::load('nelkano_modules')->getField('systems')->getType() === 'string', 'Search API native exact multi-value field');
$check(ModuleSystems::validate(['nds', 'gb', 'gb']) === ['gb', 'nds'], 'deduplicates and orders valid systems');
foreach ([['invalid'], 'gba', [3], ['value' => 'gba']] as $invalid) {
  try { ModuleSystems::validate($invalid); throw new RuntimeException('Invalid system accepted'); }
  catch (InvalidArgumentException) { $check(TRUE, 'invalid field input rejected'); }
}
$prefix = 'systemtest' . bin2hex(random_bytes(4)); $modules = []; $owner = NULL; $switched = FALSE;
$switcher = \Drupal::service('account_switcher');
$fake = new FakeDownloadClient(); $registry = new ModuleRegistry($fake, new PackageVerifier());
\Drupal::getContainer()->set('nelkano_modules.registry', $registry);
$key = sodium_crypto_sign_keypair();
$http = \Drupal::httpClient();
$call = static function (string $path, array $query = []) use ($http) {
  $r = $http->get('http://127.0.0.1' . $path, ['query' => $query, 'http_errors' => FALSE]);
  return [(int) $r->getStatusCode(), (string) $r->getBody()];
};
try {
  $owner = User::create(['name' => $prefix, 'status' => 1]); $owner->save();
  $switcher->switchTo($owner); $switched = TRUE;
  foreach ([['gb', 'gba'], ['nds'], []] as $i => $systems) {
    $id = 'example.' . $prefix . '.m' . $i; $url = 'https://github.com/example/' . $prefix . '/v' . $i . '/module.nelkmod';
    $fake->responses[$url] = ['status' => 200, 'body' => Packages::make($id, '1.0.0', $key, ['android'])];
    $module = $registry->saveModule(NULL, NULL, ['title' => $prefix . ' ' . $i, 'description' => 'Fixture systems', 'source' => '', 'published' => TRUE, 'systems' => $systems], $registry->inspect($url));
    $modules[] = $module;
    $check(array_column($module->get('field_module_systems')->getValue(), 'value') === $systems, 'multiple systems persisted including empty');
  }
  $index = Index::load('nelkano_modules'); $index->indexItems();
  foreach (['gb' => 1, 'gba' => 1, 'nds' => 1, 'snes' => 0] as $system => $expected) {
    $result = $index->query()->keys($prefix)->setFulltextFields(['title'])->addCondition('systems', $system)->execute();
    $check((int) $result->getResultCount() === $expected, 'native Search API matches ' . $system);
    [$status, $body] = $call('/api/nelkano/v1/modules', ['q' => $prefix, 'system' => $system, 'platform' => 'android']);
    $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    $check($status === 200 && count($data['items']) === $expected, 'public API filters ' . $system . ' with platform/text');
    if ($expected) { $check(in_array($system, $data['items'][0]['systems'], TRUE), 'API projects selected system'); }
  }
  [$status, $body] = $call('/modulos', ['q' => $prefix, 'sistema' => 'gba', 'plataforma' => 'android', '_wrapper_format' => 'drupal_ajax']);
  $commands = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
  $insert = array_values(array_filter($commands, static fn($c) => ($c['command'] ?? '') === 'insert'))[0] ?? [];
  $check($status === 200 && ($insert['selector'] ?? '') === '#nm-results' && substr_count($insert['data'] ?? '', '<article class="nm-card">') === 1, 'web AJAX combines text, system and platform');
  [$status] = $call('/modulos', ['sistema' => 'invalid']); $check($status === 400, 'unknown web filter rejected');
  foreach ([['system' => 'invalid'], ['system' => ['gba']]] as $query) {
    [$status] = $call('/api/nelkano/v1/modules', $query); $check($status === 400, 'invalid API filter rejected');
  }
  [$status, $body] = $call('/api/nelkano/v1/modules', ['q' => $prefix]);
  $check($status === 200 && count(json_decode($body, TRUE)['items']) === 3, 'unspecified systems still visible without system filter');
  $old = $modules[0];
  $modules[0] = $registry->saveModule($old, (int) $old->getRevisionId(), ['title' => $old->label(), 'description' => 'Fixture systems edited', 'source' => '', 'published' => TRUE]);
  $check(array_column($modules[0]->get('field_module_systems')->getValue(), 'value') === ['gb', 'gba'], 'older callers preserve systems when omitted');
  $old = $modules[0];
  $modules[0] = $registry->saveModule($old, (int) $old->getRevisionId(), ['title' => $old->label(), 'description' => 'Fixture systems edited', 'source' => '', 'published' => TRUE, 'systems' => []]);
  $check($modules[0]->get('field_module_systems')->isEmpty(), 'author can clear selection');
  echo 'READY: ' . $checks . ' systems checks.' . PHP_EOL;
}
finally {
  if ($switched) { $switcher->switchBack(); }
  foreach ($modules as $module) { $module->delete(); }
  if ($owner) { $owner->delete(); }
}
