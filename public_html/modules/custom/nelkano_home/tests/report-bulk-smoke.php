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
use Drupal\nelkano_home\Controller\ErrorReportAdminController;
use Drupal\nelkano_home\Service\ReportBulkUpdater;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\views\Views;
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
      'uid' => 1, 'status' => 0, 'field_workflow_status' => ['target_id' => \Drupal\nelkano_home\Service\WorkflowStatus::termId('in_progress')],
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
  $check(\Drupal\nelkano_home\Service\WorkflowStatus::get($read($ids[0])) === 'in_progress', 'invalid operations leave reports unchanged');
  $check($updater->update($ids, 'resolved', NULL, $admin) === 2, 'both selected reports updated');
  foreach ($ids as $id) {
    $node = $read($id);
    $check(\Drupal\nelkano_home\Service\WorkflowStatus::get($node) === 'resolved', 'selected report has target state');
    $check($node->get('field_report_observations')->value === 'Nota anterior', 'other transitions preserve observations');
    $check((int) $node->getRevisionUserId() === 1, 'revision identifies administrator');
  }
  $check(\Drupal\nelkano_home\Service\WorkflowStatus::get($read((int) $nodes[2]->id())) === 'in_progress', 'unselected report unchanged');
  $revision = $read($ids[0])->getRevisionId();
  $check($updater->update($ids, 'resolved', NULL, $admin) === 0 && $read($ids[0])->getRevisionId() === $revision, 'repeated operation does not create revisions');
  $reason = "No reproducido.\nFalta el dispositivo original.";
  $check($updater->update($ids, 'blocked', $reason, $admin) === 2, 'both reports blocked');
  foreach ($ids as $id) {
    $node = $read($id);
    $check(\Drupal\nelkano_home\Service\WorkflowStatus::get($node) === 'blocked' && $node->get('field_report_observations')->value === $reason, 'common reason persisted with state');
  }
  $deleted_id = (int) $nodes[2]->id();
  $nodes[2]->delete();
  unset($nodes[2]);
  try {
    $updater->update([$ids[0], $deleted_id], 'rejected', NULL, $admin);
    throw new LogicException('Deleted report accepted');
  }
  catch (RuntimeException $e) {
    $check(\Drupal\nelkano_home\Service\WorkflowStatus::get($read($ids[0])) === 'blocked', 'missing report prevents partial update');
  }
  $switcher->switchTo($admin);
  $switched = TRUE;
  $make_view = static function () use ($ids) {
    $view = Views::getView('nelkano_error_reports');
    $view->setDisplay('block_1');
    // Change only the in-memory executable: no administrator config is saved.
    $fields = $view->display_handler->getOption('fields');
    $view->display_handler->setOption('defaults', ['fields' => FALSE, 'filters' => FALSE, 'style' => FALSE]);
    $fields['title']['label'] = 'Título cambiado desde Views';
    $view->display_handler->setOption('fields', [
      'nelkano_report_bulk' => $fields['nelkano_report_bulk'],
      'title' => $fields['title'], 'nid' => $fields['nid'],
    ]);
    $view->display_handler->setOption('filters', [
      'nid' => ['id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid',
        'plugin_id' => 'numeric', 'operator' => 'in', 'value' => $ids],
      'title' => ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title',
        'plugin_id' => 'string', 'operator' => 'contains', 'value' => '',
        'exposed' => TRUE, 'expose' => ['identifier' => 'report_title', 'label' => 'Buscar título']],
    ]);
    $view->display_handler->setOption('style', ['type' => 'table', 'options' => [
      'columns' => ['nelkano_report_bulk' => 'nelkano_report_bulk', 'title' => 'title', 'nid' => 'nid'],
    ]]);
    return $view;
  };
  $view = $make_view();
  $view->execute();
  $form_object = ErrorReportBulkForm::create(\Drupal::getContainer());
  $form = $form_object->buildForm([], new FormState(), $view);
  $check(isset($form['reports'][0]) && !isset($form['table_wrapper']), 'selection uses native Views rows, not a replacement table');
  $check(isset($form['bulk']['target_status']['#options']['blocked']), 'state selector includes blocked');
  $check($form['bulk']['#weight'] > 50, 'bulk controls are below native Views output');
  $state = new FormState();
  $state->setValues(['reports' => [$deleted_id => $deleted_id], 'target_status' => 'resolved']);
  $form_object->validateForm($form, $state);
  $check($state->hasAnyErrors(), 'forged or no-longer-visible selection rejected');
  $state->clearErrors();
  $view = $make_view();
  $build = $view->render();
  $html = (string) \Drupal::service('renderer')->renderRoot($build);
  $check(str_contains($html, 'name="form_token"'), 'authenticated form renders CSRF token');
  $check(str_contains($html, 'name="target_status"') && str_contains($html, 'Aplicar a los seleccionados'), 'state selector and submit render');
  $check(str_contains($html, 'Título cambiado desde Views'), 'Views field label customization renders');
  $check(!str_contains($html, 'views-field-field-report-game'), 'removed Views column is absent');
  $check(strpos($html, 'views-field-title') < strpos($html, 'views-field-nid'), 'Views column order is respected');
  $check(str_contains($html, 'name="reports[0]"'), 'Views substitutes native checkbox placeholders');
  $check(!str_contains($html, '<!--form-item-reports--'), 'no unsubstituted checkbox placeholders');
  $check(str_contains($html, 'name="report_title"'), 'Views exposed filter renders');
  $check(strpos($html, 'views-field-title') < strpos($html, 'Cambiar estado de los seleccionados'), 'rendered bulk actions follow Views results');
  $view = $make_view();
  $view->setItemsPerPage(1);
  $view->execute();
  $check(count($view->result) === 1, 'Views pager bounds the selectable result page');
  $form = $form_object->buildForm([], new FormState(), $view);
  $check(count(array_filter(array_keys($form['reports']), 'is_int')) === 1, 'only current page gets checkboxes');
  $visible_id = $form['reports'][0]['#return_value'];
  $other_id = $visible_id === $ids[0] ? $ids[1] : $ids[0];
  $state = new FormState();
  $state->setValues(['reports' => [0 => $visible_id], 'target_status' => 'resolved']);
  $state->setUserInput(['reports' => [0 => (string) $other_id]]);
  $form_object->validateForm($form, $state);
  $check($state->hasAnyErrors(), 'tampered raw ID rejected even after checkbox value substitution');
  $state->clearErrors();
  $state = new FormState();
  $state->setValues(['reports' => [0 => $visible_id], 'target_status' => 'rejected']);
  $state->setUserInput(['reports' => [0 => (string) $visible_id]]);
  $view->field['nelkano_report_bulk']->viewsFormValidate($form, $state);
  $check(!$state->hasAnyErrors(), 'native Views field validates legitimate selection');
  $view->field['nelkano_report_bulk']->viewsFormSubmit($form, $state);
  $check(\Drupal\nelkano_home\Service\WorkflowStatus::get($read($visible_id)) === 'rejected', 'native Views submit updates selected synthetic report');
  $check(\Drupal\nelkano_home\Service\WorkflowStatus::get($read($other_id)) === 'blocked', 'native Views submit leaves off-page report unchanged');
  $view = $make_view();
  $view->setExposedInput(['report_title' => 'nonexistent-' . bin2hex(random_bytes(8))]);
  $view->execute();
  $check(count($view->result) === 0, 'exposed filter controls the real query');
  $form = $form_object->buildForm([], new FormState(), $view);
  $check(!$form['bulk']['#access'], 'empty results hide bulk controls');
  $check(!$view->field['nelkano_report_bulk']->access(new AnonymousUserSession()), 'selection field denies users without bulk permissions');
  $page = (new ErrorReportAdminController())->listing();
  $page_html = (string) \Drupal::service('renderer')->renderRoot($page);
  $check(str_contains($page_html, 'view-id-nelkano_error_reports'), 'admin controller embeds the native View');
  $check(str_contains($page_html, 'nk-admin-panel'), 'admin controller preserves Nelkano styling wrapper');
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
