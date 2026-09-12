<?php

/** Local-only regression. DB writes roll back; only unique fixture folders are removed. */
if (PHP_SAPI !== 'cli' || !class_exists('\\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_REPORT_ACTIONS_TEST') !== '1') { throw new RuntimeException('Local Docker only.'); }

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\nelkano_home\Controller\ErrorReportAdminController;
use Drupal\nelkano_home\Controller\ErrorReportApiController;
use Drupal\nelkano_home\Form\ErrorReportBulkForm;
use Drupal\nelkano_home\Service\ReportApiCredentials;
use Drupal\nelkano_home\Service\ReportDeletion;
use Drupal\nelkano_home\Service\ReportWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$checks = 0;
$check = static function ($ok, $label) use (&$checks) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  echo "PASS: $label\n"; $checks++;
};
$db = \Drupal::database();
$storage = \Drupal::entityTypeManager()->getStorage('node');
$original_ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'nelkano_error_report')->sort('nid')->execute();
$tx = $db->startTransaction();
$switcher = \Drupal::service('account_switcher');
$admin = User::load(1);
$switcher->switchTo($admin);
$directories = [];
$fs = \Drupal::service('file_system');
try {
  $suffix = bin2hex(random_bytes(6));
  $make_node = static function ($status = 'resolved') use ($suffix) {
    $node = Node::create(['type' => 'nelkano_error_report', 'title' => 'Actions ' . $suffix,
      'uid' => 1, 'status' => 0, WorkflowStatus::FIELD => WorkflowStatus::administrativeTermId($status)]);
    $node->save(); return $node;
  };
  $make_directory = static function () use (&$directories, $fs) {
    $dir = 'private://nelkano-error-reports/' . \Drupal::service('uuid')->generate();
    $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
    $directories[] = $dir;
    file_put_contents($dir . '/state.bin', 'synthetic state');
    file_put_contents($dir . '/screenshot.png', 'synthetic screenshot');
    file_put_contents($dir . '/orphan.log', 'synthetic extra file');
    return $dir;
  };
  $term = Term::create(['vid' => WorkflowStatus::VOCABULARY, 'name' => 'Estado ' . $suffix]);
  $term->save();
  $custom = 'term:' . $term->id();
  $check(isset(WorkflowStatus::administrativeOptions()[$custom]), 'ordinary taxonomy term appears in administrative selector');
  $check(!isset(WorkflowStatus::options()[$custom]), 'API options keep original five states');
  $node = $make_node();
  $id = (int) $node->id();
  \Drupal::service('nelkano_home.report_bulk_updater')->update([$id], $custom, NULL, $admin);
  $storage->resetCache([$id]); $node = $storage->load($id);
  $check((int) $node->get(WorkflowStatus::FIELD)->target_id === (int) $term->id(), 'bulk update actually assigns custom term');
  $check($node->get('field_report_status')->isEmpty(), 'custom state is not mapped to an invented API code');
  $page = (new ErrorReportAdminController())->view($id);
  $html = (string) \Drupal::service('renderer')->renderRoot($page);
  $check(str_contains($html, 'Estado ' . $suffix), 'custom state renders in report detail');

  $token = ReportApiCredentials::issue('actions-' . $suffix, 1);
  $request = Request::create('/api/nelkano/v1/error-reports');
  $request->headers->set('Authorization', 'Bearer ' . $token);
  $response = (new ErrorReportApiController())->detail($request, $id);
  $check($response->getStatusCode() === 404, 'API ignores report in a state outside its original workflow without a server error');
  \Drupal::service('nelkano_home.report_bulk_updater')->update([$id], 'resolved', NULL, $admin);
  $storage->resetCache([$id]); $node = $storage->load($id);

  $view = Views::getView('nelkano_error_reports');
  $view->setDisplay('block_1');
  $view->display_handler->overrideOption('filters', ['nid' => [
    'id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid', 'plugin_id' => 'numeric',
    'operator' => '=', 'value' => ['value' => $id], 'group' => 1]]);
  $view->execute();
  $form_object = ErrorReportBulkForm::create(\Drupal::getContainer());
  $form = $form_object->buildForm([], new FormState(), $view);
  $check((string) $form['bulk']['#title'] === 'Acciones' && !isset($form['bulk']['help']), 'compact Actions block removes explanatory text');
  $check(isset($form['bulk']['delete']) && isset($form['bulk']['target_status']['#options'][$custom]), 'delete-selection button and new state present');
  $state = (new FormState())->setValues(['reports' => [0 => $id], 'target_status' => '']);
  $trigger = $form['bulk']['delete'];
  $state->setTriggeringElement($trigger);
  $form_object->validateForm($form, $state);
  $check(!$state->hasAnyErrors() && $state->get('delete_reports') === [$id], 'delete selection does not require choosing a target state');
  $tampered = (new FormState())->setValues(['reports' => [0 => $id + 1]]);
  $tampered->setTriggeringElement($trigger);
  $form_object->validateForm($form, $tampered);
  $check($tampered->hasAnyErrors(), 'forged selection is rejected');
  $tampered->clearErrors();
  $page = (new ErrorReportAdminController())->listing();
  $html = (string) \Drupal::service('renderer')->renderRoot($page);
  $check(!str_contains($html, 'Exportar CSV') && str_contains($html, 'Eliminar terminado') && str_contains($html, 'Eliminar descartado'), 'header replaces export with both cleanup actions');
  $check(str_contains($html, 'name="form_token"'), 'native forms carry CSRF protection');

  $first_dir = $make_directory();
  $node->set('field_report_state_uri', $first_dir . '/state.bin');
  $node->set('field_report_screenshot_uri', $first_dir . '/screenshot.png');
  $node->setNewRevision(TRUE); $node->save();
  $second_dir = $make_directory();
  $node->set('field_report_state_uri', $second_dir . '/state.bin');
  $node->set('field_report_screenshot_uri', $second_dir . '/screenshot.png');
  $node->setNewRevision(TRUE); $node->save();
  $uuid = $node->uuid();
  \Drupal::keyValue('nelkano_report_api_receipts')->set('actions:' . $uuid, ['test' => TRUE]);
  try { ReportDeletion::deleteOne($id, new AnonymousUserSession()); $check(FALSE, 'permission enforced'); }
  catch (AccessDeniedHttpException) { $check(TRUE, 'unauthorized deletion denied'); }
  $check(is_file($second_dir . '/state.bin') && $storage->load($id), 'denied deletion preserves node and files');
  $check(!ReportDeletion::deleteOne($id, $admin, 'rejected'), 'status cleanup rechecks current state');
  $check(ReportDeletion::deleteOne($id, $admin, 'resolved'), 'finished report deleted');
  $check(!$storage->load($id) && !is_dir($first_dir) && !is_dir($second_dir), 'current and historical attachment folders and extra files removed from disk');
  $check(!$db->select('node_revision', 'r')->condition('nid', $id)->countQuery()->execute()->fetchField(), 'all node revisions deleted');
  $check(\Drupal::keyValue('nelkano_report_api_receipts')->get('actions:' . $uuid) === NULL, 'API receipt metadata deleted');
  $check(!ReportDeletion::deleteOne($id, $admin), 'repeat deletion is harmless');

  $unsafe = $make_node();
  $unsafe->set('field_report_state_uri', 'private://outside-report-storage.txt')->save();
  try { ReportDeletion::deleteOne((int) $unsafe->id(), $admin); $check(FALSE, 'unsafe URI rejected'); }
  catch (RuntimeException) { $check(TRUE, 'unsafe attachment path prevents deletion'); }
  $check($storage->load($unsafe->id()) !== NULL, 'unsafe-path report preserved');

  $shared_dir = $make_directory();
  $a = $make_node(); $a->set('field_report_state_uri', $shared_dir . '/state.bin')->save();
  $b = $make_node(); $b->set('field_report_screenshot_uri', $shared_dir . '/screenshot.png')->save();
  try { ReportDeletion::deleteOne((int) $a->id(), $admin); $check(FALSE, 'shared folder rejected'); }
  catch (RuntimeException) { $check(TRUE, 'shared attachment directory is protected'); }
  $check(is_file($shared_dir . '/state.bin') && $storage->load($a->id()) && $storage->load($b->id()), 'shared nodes and files preserved');

  $rejected = $make_node('rejected');
  $missing = 'private://nelkano-error-reports/' . \Drupal::service('uuid')->generate() . '/missing.bin';
  $rejected->set('field_report_state_uri', $missing)->save();
  $check(in_array((int) $rejected->id(), ReportDeletion::idsForStatus('rejected'), TRUE), 'discarded cleanup selects original rejected term');
  $check(ReportDeletion::deleteOne((int) $rejected->id(), $admin, 'rejected'), 'already missing attachment does not prevent node deletion');

  $many = [];
  for ($i = 0; $i < 51; $i++) { $many[] = (int) $make_node()->id(); }
  $matches = ReportDeletion::idsForStatus('resolved');
  $check(!array_diff($many, $matches), 'state cleanup includes all reports beyond the first 50');
  // Execute only the known fixture IDs, never the real reports in the query.
  $context = ['results' => []];
  ReportDeletion::process($many, 'resolved', $context);
  $check(($context['results']['deleted'] ?? 0) === 51, 'batch worker deletes every fixture across pages');
  foreach ($original_ids as $original_id) {
    $check($storage->load($original_id) !== NULL, 'pre-existing report untouched: ' . $original_id);
  }
}
finally {
  $tx->rollBack(); unset($tx);
  $storage->resetCache();
  \Drupal::entityTypeManager()->getStorage('taxonomy_term')->resetCache();
  foreach ($directories as $dir) {
    if (is_dir($dir)) { $fs->deleteRecursive($dir); }
  }
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['taxonomy_term_list', 'node_list']);
  \Drupal::messenger()->deleteAll();
  $switcher->switchBack();
}
$check($original_ids === $storage->getQuery()->accessCheck(FALSE)->condition('type', 'nelkano_error_report')->sort('nid')->execute(), 'original report set restored after test');
echo "SUCCESS: $checks report action checks.\n";
