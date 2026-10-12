<?php
/** Verify a config deployment rehearsal on a clone of the local editorial DB. */
use Drupal\Core\Database\Database;
$db = \Drupal::database();
if (!str_starts_with($db->getConnectionOptions()['database'], 'nelkano_book_qa_release_')) {
  throw new RuntimeException('Release QA clone required.');
}
$options = $db->getConnectionOptions(); $options['database'] = 'nelkano_drupal';
Database::addConnectionInfo('book_editorial_comparison', 'default', $options);
$original = Database::getConnection('default', 'book_editorial_comparison');
$tables = ['node', 'node_field_data', 'node_revision', 'node_field_revision', 'book', 'path_alias', 'path_alias_revision', 'users_field_data'];
foreach ($db->schema()->findTables('node__%') as $table) { $tables[] = $table; }
foreach ($db->schema()->findTables('node_revision__%') as $table) { $tables[] = $table; }
foreach ($db->schema()->findTables('nelkano_%') as $table) { $tables[] = $table; }
$fingerprint = function ($connection, $table): string {
  $rows = $connection->select($table, 't')->fields('t')->execute()->fetchAll();
  $rows = array_map(fn($row) => json_encode($row), $rows); sort($rows);
  return hash('sha256', serialize($rows));
};
foreach (array_unique($tables) as $table) {
  if ($fingerprint($db, $table) !== $fingerprint($original, $table)) { throw new RuntimeException('Content changed during config import: ' . $table); }
}
$transfer = \Drupal::service('book_import_export.transfer');
$source = $transfer->read(dirname(DRUPAL_ROOT) . '/content/books/nelkano-sdk.yml');
$before = $transfer->export($source['book']);
$transfer->import($source, TRUE); $transfer->import($source);
if ($transfer->export($source['book']) != $before) { throw new RuntimeException('Repeated import changed book.'); }
foreach (array_unique($tables) as $table) {
  if ($fingerprint($db, $table) !== $fingerprint($original, $table)) { throw new RuntimeException('Content changed during repeated import: ' . $table); }
}
echo count(array_unique($tables)) . " content/account tables preserved across full configuration deployment and repeated book import.\n";
