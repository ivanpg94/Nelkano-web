<?php

/**
 * Rehearse update 11042 on a disposable clone, never the working/production DB.
 *
 * NELKANO_NAVIGATION_TEST_PHASE=prepare drush --uri=http://navigation-qa.localhost php:script scripts/test-navigation-update.php
 * drush --uri=http://navigation-qa.localhost updatedb --yes
 * NELKANO_NAVIGATION_TEST_PHASE=verify drush --uri=http://navigation-qa.localhost php:script scripts/test-navigation-update.php
 * NELKANO_NAVIGATION_TEST_PHASE=idem drush --uri=http://navigation-qa.localhost php:script scripts/test-navigation-update.php
 * NELKANO_NAVIGATION_TEST_PHASE=export drush --uri=http://navigation-qa.localhost php:script scripts/test-navigation-update.php
 *
 * prepare intentionally seeds unpublished/editorial fixtures in the clone.
 * export writes only under /tmp; never export QA sentinels into config/sync.
 */

use Drupal\Core\Config\FileStorage;
use Drupal\nelkano_home\Service\HomeExtensions;
use Drupal\node\Entity\Node;

if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) {
  throw new RuntimeException('Run through Drush on an isolated navigation QA clone.');
}
$db = \Drupal::database();
$options = $db->getConnectionOptions();
$dbName = $options['database'];
if (($options['host'] ?? '') !== 'database'
  || !preg_match('/^(nelkano_navigation_qa_|nelkano_release_qa_navigation_)[a-zA-Z0-9_]+$/D', $dbName)) {
  throw new RuntimeException('Only run on a disposable nelkano_navigation_qa_* Docker database.');
}
$phase = getenv('NELKANO_NAVIGATION_TEST_PHASE');
if (!in_array($phase, ['prepare', 'verify', 'idem', 'idempotence', 'export'], TRUE)) {
  throw new RuntimeException('Set NELKANO_NAVIGATION_TEST_PHASE=prepare, verify, idem or export.');
}
$directory = sys_get_temp_dir() . '/nelkano-navigation-qa-' . $dbName;
if (!is_dir($directory) && !mkdir($directory, 0700, TRUE)) {
  throw new RuntimeException('Cannot create isolated QA evidence directory.');
}
$manifest = $directory . '/before.json';
$rootUuid = 'de520020-2026-4000-8000-000000000001';
$storage = \Drupal::entityTypeManager()->getStorage('node');
$transfer = \Drupal::service('book_import_export.transfer');
$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
  echo 'PASS ' . ++$count . ': ' . $message . PHP_EOL;
};
$rootNode = static function () use ($storage, $rootUuid) {
  $storage->resetCache();
  $nodes = $storage->loadByProperties(['uuid' => $rootUuid, 'type' => 'sdk_page']);
  $root = reset($nodes);
  if (!$root || !$root instanceof \Drupal\book\BookInterface
    || (int) ($root->getBook()['bid'] ?? 0) !== (int) $root->id()) {
    throw new RuntimeException('The cloned canonical SDK Book root must exist.');
  }
  return $root;
};
$tableHashes = static function (?int $excludedRoot = NULL) use ($db): array {
  $tables = [];
  foreach ($db->schema()->findTables('%') as $table) {
    if (!preg_match('/^(users($|_)|user__|user_role($|_)|node($|_)|taxonomy($|_)|file_|nelkano_|path_alias($|_)|book$)/', $table)) {
      continue;
    }
    $query = $db->select($table, 't')->fields('t');
    if ($excludedRoot !== NULL && preg_match('/^node($|_)/', $table)) {
      if ($db->schema()->fieldExists($table, 'nid')) {
        $query->condition('nid', $excludedRoot, '<>');
      }
      elseif ($db->schema()->fieldExists($table, 'entity_id')) {
        $query->condition('entity_id', $excludedRoot, '<>');
      }
    }
    $rows = [];
    foreach ($query->execute()->fetchAll(PDO::FETCH_ASSOC) as $row) {
      ksort($row);
      $rows[] = json_encode($row, JSON_THROW_ON_ERROR);
    }
    sort($rows, SORT_STRING);
    $tables[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
  }
  ksort($tables);
  return $tables;
};
$configSnapshot = static function (bool $includeHome = TRUE): array {
  $configStorage = \Drupal::service('config.storage');
  $names = $configStorage->listAll();
  if (!$includeHome) { $names = array_values(array_diff($names, ['nelkano_home.settings'])); }
  $data = $configStorage->readMultiple($names);
  ksort($data);
  return $data;
};
$revisionCount = static fn(int $nid): int => (int) $db->select('node_revision', 'r')
  ->condition('nid', $nid)->countQuery()->execute()->fetchField();
$schemaReport = static function (): array {
  $data = \Drupal::config('nelkano_home.settings')->getRawData();
  $typed = \Drupal::service('config.typed')->createFromNameAndData('nelkano_home.settings', $data);
  $errors = [];
  foreach ($typed->validate() as $error) {
    $errors[] = $error->getPropertyPath() . ': ' . $error->getMessage();
  }
  return ['name' => 'nelkano_home.settings', 'errors' => $errors,
    'extensions_keys' => array_map('array_keys', HomeExtensions::defaults())];
};

if ($phase === 'prepare') {
  $check(!is_file($manifest), 'Fresh QA manifest; prepare cannot silently replace existing evidence');
  $check((int) \Drupal::keyValue('system.schema')->get('nelkano_home') === 11041, 'Clone starts at schema 11041');
  $defaults = HomeExtensions::defaults();
  $home = \Drupal::configFactory()->getEditable('nelkano_home.settings');
  foreach (['es', 'en'] as $language) {
    $check(isset($defaults[$language]) && count($defaults[$language]) === 10, 'Ten versioned extension fields for ' . $language);
    foreach (array_keys($defaults[$language]) as $key) { $home->clear($language . '.' . $key); }
    $check(isset($defaults[$language]['extensions_title']), 'Editable extension title exists for ' . $language);
    $home->set($language . '.extensions_title', 'QA custom extension title ' . $language);
    $blankKey = NULL;
    foreach ($defaults[$language] as $key => $value) {
      if ($key !== 'extensions_title' && is_string($value)) { $blankKey = $key; break; }
    }
    $check($blankKey !== NULL, 'An intentionally blank editorial extension field can be preserved');
    $home->set($language . '.' . $blankKey, '');
    $home->set($language . '.hero_title', 'QA preserved hero ' . $language);
    $rows = $home->get($language . '.feature_items') ?? [];
    foreach (['Módulos y SDK', 'Modules and SDK'] as $title) {
      $rows[] = ['title' => $title, 'description' => 'QA duplicate', 'visible' => '1',
        'icon' => 'IcoPencilTouch', 'image_uri' => ''];
    }
    $rows[] = ['title' => 'Módulos y SDK personalizados ' . $language,
      'description' => 'QA keep this editorial row and image', 'visible' => '1',
      'icon' => 'IcoCollections', 'image_uri' => 'public://qa-navigation-preserved-' . $language . '.webp'];
    $home->set($language . '.feature_items', $rows);
  }
  $home->save();
  $expectedHome = $home->getRawData();
  foreach (['es', 'en'] as $language) {
    foreach ($defaults[$language] as $key => $value) {
      if (!array_key_exists($key, $expectedHome[$language]) || $expectedHome[$language][$key] === NULL) {
        $expectedHome[$language][$key] = $value;
      }
    }
    $expectedHome[$language]['feature_items'] = array_values(array_filter(
      $expectedHome[$language]['feature_items'],
      static fn(array $row): bool => !in_array($row['title'] ?? '', ['Módulos y SDK', 'Modules and SDK'], TRUE)
    ));
  }
  $root = $rootNode();
  $root->getTranslation('es')->setUnpublished();
  if (!$root->hasTranslation('en')) {
    $root->addTranslation('en', ['title' => 'QA private English SDK draft', 'status' => 0,
      'body' => ['value' => '<p>QA private translation remains unpublished.</p>', 'format' => 'basic_html']]);
  }
  $root->getTranslation('en')->setUnpublished();
  $root->setNewRevision(TRUE);
  $root->setRevisionLogMessage('Isolated navigation migration QA fixture');
  $root->save();
  $root = $rootNode();
  $privateChapter = Node::create(['type' => 'sdk_page', 'title' => 'QA private navigation chapter',
    'langcode' => 'es', 'status' => 0, 'uid' => 0,
    'body' => ['value' => '<p>QA private chapter must never be published.</p>', 'format' => 'basic_html']]);
  $privateChapter->save();
  $privateChapter->setBook(['nid' => (int) $privateChapter->id(), 'bid' => (int) $root->id(),
    'pid' => (int) $root->id(), 'weight' => 999]);
  $check((bool) \Drupal::service('book.manager')->updateOutline($privateChapter), 'Private SDK chapter fixture has a valid outline');
  $beforeBook = $transfer->export($rootUuid);
  $expectedBook = $beforeBook;
  foreach ($expectedBook['pages'] as &$page) {
    if ($page['uuid'] === $rootUuid) { $page['translations']['es']['status'] = TRUE; }
  }
  unset($page);
  $data = ['database' => $dbName, 'site_uuid' => \Drupal::config('system.site')->get('uuid'),
    'root_nid' => (int) $root->id(), 'root_revision' => (int) $root->getRevisionId(),
    'root_revision_count' => $revisionCount((int) $root->id()),
    'protected_tables' => $tableHashes((int) $root->id()), 'other_config' => $configSnapshot(FALSE),
    'expected_home' => $expectedHome, 'expected_book' => $expectedBook, 'private_chapter_uuid' => $privateChapter->uuid()];
  file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
  echo 'Prepared isolated navigation migration fixtures and preservation hashes. Evidence: ' . $directory . PHP_EOL;
  return;
}

$check(is_file($manifest), 'Preparation manifest exists for this exact QA database');
$before = json_decode(file_get_contents($manifest), TRUE, 512, JSON_THROW_ON_ERROR);
$check($before['database'] === $dbName, 'Evidence belongs to the current QA clone');
$check(\Drupal::config('system.site')->get('uuid') === $before['site_uuid'], 'Site UUID preserved');
$check((int) \Drupal::keyValue('system.schema')->get('nelkano_home') === 11042, 'Real updatedb recorded hook 11042');
$check($tableHashes($before['root_nid']) === $before['protected_tables'],
  'Account, report, backlog, child-node, file, alias and Book tables preserved');
$check($configSnapshot(FALSE) == $before['other_config'], 'All configuration outside Home preserved');
$check(\Drupal::config('nelkano_home.settings')->getRawData() == $before['expected_home'],
  'Missing defaults seeded; custom/blank fields, heroes, images and unrelated feature rows preserved');
$check($transfer->export($rootUuid) == $before['expected_book'],
  'Only Spanish SDK root publication changed; prose, hierarchy, English draft and private chapters preserved');
$root = $rootNode();
$check((int) $root->getRevisionId() !== $before['root_revision']
  && $revisionCount((int) $root->id()) === $before['root_revision_count'] + 1,
  'Publishing the Spanish root creates exactly one revision');
$report = $schemaReport();
file_put_contents($directory . '/schema.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$check(!$report['errors'], 'Home configuration matches the typed schema: ' . implode('; ', $report['errors']));

if (in_array($phase, ['idem', 'idempotence'], TRUE)) {
  $fullTables = $tableHashes();
  $fullConfig = $configSnapshot();
  \Drupal::moduleHandler()->loadInclude('nelkano_home', 'install');
  if (!function_exists('nelkano_home_update_11042')) { throw new RuntimeException('Update hook 11042 is missing.'); }
  nelkano_home_update_11042();
  nelkano_home_update_11042();
  $check($tableHashes() === $fullTables, 'Two repeated hooks create no nodes, revisions, publications or persistent row changes');
  $check($configSnapshot() == $fullConfig, 'Two repeated hooks preserve customized configuration exactly');
}
if ($phase === 'export') {
  $export = new FileStorage($directory . '/config');
  $check($export->write('nelkano_home.settings', \Drupal::config('nelkano_home.settings')->getRawData()), 'QA Home configuration exported outside the repository');
  $transfer->write($rootUuid, $directory . '/nelkano-sdk.yml');
  $check($transfer->read($directory . '/nelkano-sdk.yml') == $before['expected_book'], 'QA Book export preserves the expected portable content');
  echo 'QA-only configuration, Book and schema evidence: ' . $directory . PHP_EOL;
}
echo 'Navigation migration checks complete: ' . $count . '; phase=' . $phase . PHP_EOL;
