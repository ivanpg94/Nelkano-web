<?php

/** Read-only invariants around an idempotent additive migration, local only. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (!in_array(parse_url(getenv('NELKANO_REPORT_TEST_URL') ?: 'http://127.0.0.1', PHP_URL_HOST), ['localhost', '127.0.0.1'], TRUE)) { throw new RuntimeException('Loopback only.'); }
require_once __DIR__ . '/../nelkano_home.backlog.inc';

use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\node\Entity\Node;

$check = static function ($ok, $label) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  echo "PASS: $label\n";
};
$node = NULL;
$db = \Drupal::database();
try {
  $node = Node::create(['type' => 'nelkano_error_report', 'title' => 'Synthetic taxonomy migration', 'uid' => 1, 'status' => 0]);
  WorkflowStatus::set($node, 'new');
  $node->save();
  $first_revision = $node->getRevisionId();
  $node->setNewRevision(TRUE);
  WorkflowStatus::set($node, 'blocked');
  $node->set('field_report_observations', 'Synthetic blocking reason');
  $node->save();
  $last_revision = $node->getRevisionId();
  foreach (['node__', 'node_revision__'] as $prefix) {
    $db->delete($prefix . WorkflowStatus::FIELD)->condition('entity_id', $node->id())->execute();
  }
  $snapshot = static function () use ($db) {
    $result = [];
    foreach (['node', 'node_field_data', 'node_revision', 'node_field_revision', 'node__field_report_status', 'node_revision__field_report_status'] as $table) {
      $rows = $db->select($table, 't')->fields('t')->execute()->fetchAll(\PDO::FETCH_ASSOC);
      $serialized = array_map('serialize', $rows);
      sort($serialized);
      $result[$table] = hash('sha256', serialize($serialized));
    }
    $result['receipts'] = \Drupal::keyValue('nelkano_report_api_receipts')->getAll();
    return $result;
  };
  $before = $snapshot();
  nelkano_home_ensure_backlog();
  nelkano_home_migrate_report_taxonomy();
  $check($snapshot() === $before, 'migration preserves node data, revision IDs, timestamps, legacy fields and receipts');
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $check(WorkflowStatus::get($storage->loadRevision($first_revision)) === 'new', 'historical revision migrates to its original state');
  $check(WorkflowStatus::get($storage->loadRevision($last_revision)) === 'blocked', 'latest revision migrates to its original state');
  $check(WorkflowStatus::get($storage->load($node->id())) === 'blocked', 'current node uses taxonomy');
  foreach (['node__', 'node_revision__'] as $prefix) {
    $source = $prefix . 'field_report_status';
    $target = $prefix . WorkflowStatus::FIELD;
    $missing = $db->select($source, 's');
    $missing->leftJoin($target, 't', 's.entity_id = t.entity_id AND s.revision_id = t.revision_id AND s.langcode = t.langcode AND s.delta = t.delta AND s.deleted = t.deleted');
    $missing->condition('s.bundle', 'nelkano_error_report')->isNull('t.entity_id');
    $check((int) $missing->countQuery()->execute()->fetchField() === 0, 'all original rows mapped: ' . $prefix);
  }
  $counts = [];
  foreach (['node__', 'node_revision__'] as $prefix) { $counts[$prefix] = $db->select($prefix . WorkflowStatus::FIELD, 't')->countQuery()->execute()->fetchField(); }
  nelkano_home_migrate_report_taxonomy();
  foreach ($counts as $prefix => $count) {
    $check($db->select($prefix . WorkflowStatus::FIELD, 't')->countQuery()->execute()->fetchField() === $count, 'repeat migration creates no duplicate rows: ' . $prefix);
  }
  $check($snapshot() === $before, 'repeat migration leaves reports and receipts intact');
  echo "SUCCESS: taxonomy migration invariants.\n";
}
finally {
  $node?->delete();
}
