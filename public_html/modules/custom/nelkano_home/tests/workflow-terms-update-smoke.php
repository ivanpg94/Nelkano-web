<?php

/** Local-only regression for update 11023. All fixture writes are rolled back. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_UPDATE_TEST') !== '1') {
  throw new RuntimeException('Run only in local Docker with NELKANO_LOCAL_UPDATE_TEST=1.');
}
require_once __DIR__ . '/../nelkano_home.install';

use Drupal\nelkano_home\Service\ReportWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;

$checks = 0;
$check = static function ($ok, $label) use (&$checks) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++;
  echo "PASS: $label\n";
};
$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$db = \Drupal::database();
$original = $storage->loadByProperties(['vid' => WorkflowStatus::VOCABULARY]);
$original_data = array_map(static fn($term) => $term->toArray(), $original);
$references = static function () use ($db) {
  $data = [];
  foreach (['node__field_workflow_status', 'node_revision__field_workflow_status'] as $table) {
    $rows = array_map('serialize', $db->select($table, 't')->fields('t')->execute()->fetchAll(\PDO::FETCH_ASSOC));
    sort($rows);
    $data[$table] = $rows;
  }
  return $data;
};
$transaction = $db->startTransaction();
try {
  $before_references = $references();
  // Make the canonical codes absent only on this transaction's connection.
  // Other requests keep seeing the committed original terms; nothing is deleted.
  foreach ($original as $term) {
    $db->update('taxonomy_term__field_workflow_code')
      ->fields(['field_workflow_code_value' => 'seed-test-' . $term->get('field_workflow_code')->value])
      ->condition('entity_id', $term->id())->execute();
  }
  $storage->resetCache();
  nelkano_home_update_11023();
  $created = [];
  foreach (ReportWorkflow::STATUSES as $code => $label) {
    $id = WorkflowStatus::termId($code);
    $created[$code] = $id;
    $check(!isset($original[$id]) && $storage->load($id)->label() === $label, 'creates missing term: ' . $code);
  }
  nelkano_home_update_11023();
  foreach ($created as $code => $id) {
    $check(WorkflowStatus::termId($code) === $id, 'rerun preserves term ID: ' . $code);
  }
  $term = $storage->load($created['new']);
  $term->setName('Nuevo personalizado')->save();
  nelkano_home_update_11023();
  $check($storage->load($created['new'])->label() === 'Nuevo personalizado', 'rerun preserves customized labels');

  $db->update('taxonomy_term__field_workflow_code')
    ->fields(['field_workflow_code_value' => 'seed-test-missing'])
    ->condition('entity_id', $created['blocked'])->execute();
  $storage->resetCache();
  nelkano_home_update_11023();
  $check(WorkflowStatus::termId('blocked') !== $created['blocked'], 'repairs one missing term');
  foreach (array_diff_key($created, ['blocked' => TRUE]) as $code => $id) {
    $check(WorkflowStatus::termId($code) === $id, 'repair leaves other IDs intact: ' . $code);
  }
  $check($before_references === $references(), 'node and revision references stay unchanged');
}
finally {
  $transaction->rollBack();
  unset($transaction);
  $storage->resetCache();
}
$after = $storage->loadByProperties(['vid' => WorkflowStatus::VOCABULARY]);
$check(array_map(static fn($term) => $term->toArray(), $after) === $original_data, 'original terms fully restored after test');
echo "SUCCESS: $checks workflow-term update checks.\n";
