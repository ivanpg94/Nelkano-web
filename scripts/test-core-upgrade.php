<?php
/** Local upgrade preservation proof; never writes to Drupal content/configuration. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
$options = \Drupal::database()->getConnectionOptions();
if (getenv('NELKANO_LOCAL_CORE_UPGRADE_TEST') !== '1' || $options['database'] !== 'nelkano_drupal' || $options['host'] !== 'database') {
  throw new RuntimeException('Explicit local Docker upgrade verification required.');
}
$data = ['uuid' => \Drupal::config('system.site')->get('uuid'), 'tables' => []];
foreach (\Drupal::database()->schema()->findTables('%') as $table) {
  if (!preg_match('/^(users|user__|user_role|node|taxonomy|file_|nelkano_|path_alias|book$)/', $table)) { continue; }
  $rows = [];
  foreach (\Drupal::database()->select($table, 't')->fields('t')->execute()->fetchAll(PDO::FETCH_ASSOC) as $row) { ksort($row); $rows[] = json_encode($row, JSON_THROW_ON_ERROR); }
  sort($rows, SORT_STRING);
  $data['tables'][$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
}
ksort($data['tables']);
$file = '/tmp/nelkano-core-upgrade-content.json';
$phase = getenv('NELKANO_CORE_UPGRADE_PHASE');
if ($phase === 'before') {
  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
  echo 'Recorded ' . count($data['tables']) . ' persistent tables and site UUID.' . PHP_EOL;
}
elseif ($phase === 'after') {
  $before = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
  if ($data !== $before) {
    $changed = array_keys(array_filter($data['tables'], static fn($v, $k) => $v !== ($before['tables'][$k] ?? NULL), ARRAY_FILTER_USE_BOTH));
    throw new RuntimeException('Persistent data or site UUID changed: ' . implode(', ', $changed));
  }
  echo 'PASS: all ' . count($data['tables']) . ' persistent tables, row counts, hashes and site UUID preserved.' . PHP_EOL;
}
else { throw new RuntimeException('Set NELKANO_CORE_UPGRADE_PHASE=before or after.'); }
