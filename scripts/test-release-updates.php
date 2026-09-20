<?php
/** Destructive rehearsal fixtures are restricted to the dedicated cloned DB. */
use Drupal\Component\Serialization\Yaml;
use Drupal\nelkano_home\Service\SystemPages;

$dbName = \Drupal::database()->getConnectionOptions()['database'];
if (getenv('DRUPAL_DB_HOST') !== 'database' || !str_starts_with($dbName, 'nelkano_release_qa_')) {
  throw new RuntimeException('Only run on an isolated nelkano_release_qa_* database.');
}
$phase = getenv('NELKANO_RELEASE_TEST_PHASE');
$assert = static function ($value, string $message): void {
  if (!$value) { throw new RuntimeException($message); }
};
$digest = static function (): array {
  $result = [];
  foreach (\Drupal::database()->schema()->findTables('%') as $table) {
    if (!preg_match('/^(users|user__|user_role|node|taxonomy|file_|nelkano_|path_alias)/', $table)) { continue; }
    $rows = \Drupal::database()->query('SELECT * FROM {' . $table . '}')->fetchAll(\PDO::FETCH_ASSOC);
    $serialized = array_map('serialize', $rows);
    sort($serialized, SORT_STRING);
    $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', serialize($serialized))];
  }
  ksort($result);
  return $result;
};
$file = '/tmp/nelkano-release-protected.json';
if ($phase === 'prepare') {
  file_put_contents($file, json_encode(['tables' => $digest(), 'uuid' => \Drupal::config('system.site')->get('uuid')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
  foreach (['settings', 'docs'] as $name) {
    $source = '/tmp/nelkano-release-baseline/nelkano_home.' . $name . '.yml';
    $assert(is_file($source), 'Missing baseline ' . $source);
    \Drupal::configFactory()->getEditable('nelkano_home.' . $name)->setData(Yaml::decode(file_get_contents($source)))->save();
  }
  foreach (['nelkano_home.systems', 'nelkano_home.guide', 'nelkano_home.seo', 'image.style.nelkano_header_avatar'] as $name) {
    \Drupal::configFactory()->getEditable($name)->delete();
  }
  \Drupal::state()->delete('nelkano_home.figma_redesign_v1');
  \Drupal::keyValue('system.schema')->set('nelkano_home', 11025);
  echo "Prepared the cloned pre-redesign configuration at schema 11025.\n";
}
elseif (in_array($phase, ['verify', 'verify-sync'], TRUE)) {
  $before = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
  $after = $digest();
  $assert($before['tables'] === $after, 'Persistent user/report/backlog/content tables changed');
  $assert($before['uuid'] === \Drupal::config('system.site')->get('uuid'), 'Site UUID changed');
  $assert(\Drupal::keyValue('system.schema')->get('nelkano_home') === 11033, 'Update sequence incomplete');
  foreach (['nelkano_home.settings', 'nelkano_home.docs', 'nelkano_home.systems', 'nelkano_home.guide', 'nelkano_home.seo', 'image.style.nelkano_header_avatar'] as $name) {
    $data = \Drupal::config($name)->getRawData();
    $assert((bool) $data, 'Missing configuration ' . $name);
    $schema = \Drupal::service('config.typed')->createFromNameAndData($name, $data);
    $errors = [];
    foreach ($schema->validate() as $error) { $errors[] = $error->getPropertyPath() . ': ' . $error->getMessage(); }
    $assert(!$errors, 'Invalid configuration ' . $name . ': ' . implode('; ', $errors));
  }
  foreach (['es', 'en'] as $language) {
    $assert(count(SystemPages::content($language)['pages']) >= 16, 'Missing system pages');
    $assert((bool) \Drupal::config('nelkano_home.guide')->get($language), 'Guide missing language ' . $language);
  }
  if ($phase === 'verify-sync') {
    $source = new \Drupal\Core\Config\FileStorage('/opt/drupal/config/sync');
    foreach ($source->listAll() as $name) {
      $assert(\Drupal::service('config.storage')->read($name) == $source->read($name), 'Sync mismatch: ' . $name);
    }
  }
  echo 'PASS: migrations, configuration schemas, bilingual pages, site UUID and ' . count($after) . " persistent tables unchanged.\n";
}
else { throw new RuntimeException('Set NELKANO_RELEASE_TEST_PHASE=prepare, verify or verify-sync.'); }
