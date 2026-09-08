<?php

/** Local Drupal integration checks. Never updates pre-existing reports. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) {
  http_response_code(404);
  exit;
}

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\nelkano_home\Form\ErrorReportBulkForm;
use Drupal\nelkano_home\Service\ReportBulkUpdater;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// Require an explicit local origin; never use this as a production health check.
$host = parse_url(\Drupal::request()->getSchemeAndHttpHost(), PHP_URL_HOST);
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], TRUE)) {
  throw new RuntimeException('Run this test only on local Drupal with --uri=http://localhost.');
}
$nodes = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException('FAIL: ' . $label);
  }
  $checks++;
  echo 'PASS: ' . $label . "\n";
};
$storage = \Drupal::entityTypeManager()->getStorage('node');
$switcher = \Drupal::service('account_switcher');
$admin = User::load(1);
$updater = \Drupal::service('nelkano_home.report_bulk_updater');
$switched = FALSE;
try {
  for ($i = 0; $i < 3; $i++) {
    $node = Node::create([
      'type' => 'nelkano_error_report', 'title' => 'Bulk smoke ' . bin2hex(random_bytes(8)),
      'uid' => 1, 'status' => 0, 'field_report_status' => 'in_progress',
      'field_report_system' => 'TEST', 'field_report_observations' => 'Nota anterior',
    ]);
    $node->save();
    $nodes[] = $node;
  }
  $ids = [(int) $nodes[0]->id(), (int) $nodes[1]->id()];
  $read = static function (int $id) use ($storage) {
    $storage->resetCache([$id]);
    return $storage->load($id);
  };
  try {
    $updater->update($ids, 'resolved', NULL, new AnonymousUserSession());
    throw new RuntimeException('Anonymous operation accepted');
  }
  catch (AccessDeniedHttpException $e) {
    $check(TRUE, 'bulk writes require permissions');
  }
  foreach ([[], [$ids[0], $ids[0]], range(1, ReportBulkUpdater::MAX_REPORTS + 1)] as $invalid_ids) {
    try {
      $updater->update($invalid_ids, 'resolved', NULL, $admin);
      throw new RuntimeException('Invalid selection accepted');
    }
    catch (InvalidArgumentException $e) {
      $check(TRUE, 'empty, duplicate or oversized selection denied');
    }
  }
  try {
    $updater->update($ids, 'blocked', ' ', $admin);
    throw new RuntimeException('Empty blocking reason accepted');
  }
  catch (InvalidArgumentException $e) {
    $check(TRUE, 'blocked requires a reason');
  }
  $check($read($ids[0])->get('field_report_status')->value === 'in_progress', 'invalid operations leave reports unchanged');
  $check($updater->update($ids, 'resolved', NULL, $admin) === 2, 'both selected reports updated');
  foreach ($ids as $id) {
    $node = $read($id);
    $check($node->get('field_report_status')->value === 'resolved', 'selected report has target state');
    $check($node->get('field_report_observations')->value === 'Nota anterior', 'other transitions preserve observations');
    $check((int) $node->getRevisionUserId() === 1, 'revision identifies administrator');
  }
  $check($read((int) $nodes[2]->id())->get('field_report_status')->value === 'in_progress', 'unselected report unchanged');
  $revision = $read($ids[0])->getRevisionId();
  $check($updater->update($ids, 'resolved', NULL, $admin) === 0 && $read($ids[0])->getRevisionId() === $revision, 'repeated operation does not create revisions');
  $reason = "No reproducido.\nFalta el dispositivo original.";
  $check($updater->update($ids, 'blocked', $reason, $admin) === 2, 'both reports blocked');
  foreach ($ids as $id) {
    $node = $read($id);
    $check($node->get('field_report_status')->value === 'blocked' && $node->get('field_report_observations')->value === $reason, 'common reason persisted with state');
  }
  $deleted_id = (int) $nodes[2]->id();
  $nodes[2]->delete();
  unset($nodes[2]);
  try {
    $updater->update([$ids[0], $deleted_id], 'rejected', NULL, $admin);
    throw new LogicException('Deleted report accepted');
  }
  catch (RuntimeException $e) {
    $check($read($ids[0])->get('field_report_status')->value === 'blocked', 'missing report prevents partial update');
  }
  $switcher->switchTo($admin);
  $switched = TRUE;
  $form_object = ErrorReportBulkForm::create(\Drupal::getContainer());
  $form = $form_object->buildForm([], new FormState());
  $check($form['table_wrapper']['reports']['#type'] === 'tableselect', 'table has multi-selection controls');
  $check(isset($form['bulk']['target_status']['#options']['blocked']), 'state selector includes blocked');
  $check(array_search('table_wrapper', array_keys($form), TRUE) < array_search('bulk', array_keys($form), TRUE), 'bulk controls are below table');
  $state = new FormState();
  $state->setValues(['reports' => [$deleted_id => $deleted_id], 'target_status' => 'resolved']);
  $form_object->validateForm($form, $state);
  $check($state->hasAnyErrors(), 'forged or no-longer-visible selection rejected');
  $build = \Drupal::formBuilder()->getForm(ErrorReportBulkForm::class);
  $html = (string) \Drupal::service('renderer')->renderRoot($build);
  $check(str_contains($html, 'name="form_token"'), 'authenticated form renders CSRF token');
  $check(str_contains($html, 'name="target_status"') && str_contains($html, 'Aplicar a los seleccionados'), 'state selector and submit render');
  echo "SUCCESS: {$checks} bulk workflow checks.\n";
}
finally {
  if ($switched) {
    $switcher->switchBack();
  }
  foreach ($nodes as $node) {
    $fresh = $storage->load($node->id());
    if ($fresh) {
      $fresh->delete();
    }
  }
  echo "Synthetic reports cleaned up.\n";
}
