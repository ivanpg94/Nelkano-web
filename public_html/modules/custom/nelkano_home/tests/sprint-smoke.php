<?php

/** Local integration. Synthetic HU only; transaction rolls back its content. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }

use Drupal\nelkano_home\Controller\HuAdminController;
use Drupal\nelkano_home\Form\HuForm;
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\Core\Form\FormState;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

$checks = 0;
$check = static function ($ok, $label) use (&$checks) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++;
  echo "PASS: $label\n";
};
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$transaction = \Drupal::database()->startTransaction();
$stack = \Drupal::service('request_stack');
$board = static function (string $sprint) use ($stack) {
  $stack->push(Request::create('/admin/nelkano/backlog', 'GET', ['sprint' => $sprint]));
  try { return (new HuAdminController())->board(); }
  finally { $stack->pop(); }
};
$contains = static function (array $build, $id): bool {
  foreach ($build['board']['#columns'] as $column) {
    foreach ($column['cards'] as $card) { if ((int) $card['id'] === (int) $id) { return TRUE; } }
  }
  return FALSE;
};
try {
  foreach (['', 'none', '0', '2', '-1', '2147483647'] as $value) {
    $check(HuAdminController::sprintFilter(Request::create('/', 'GET', ['sprint' => $value])) === $value, 'valid sprint filter: ' . $value);
  }
  foreach (['1.5', 'abc', ['2'], '2147483648'] as $value) {
    try {
      HuAdminController::sprintFilter(Request::create('/', 'GET', ['sprint' => $value]));
      throw new RuntimeException('Invalid filter accepted');
    }
    catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException) { $check(TRUE, 'invalid filter rejected'); }
  }
  $node = HuWorkflow::save(NULL, NULL, 'new', 'Synthetic sprint verification', 'Temporary test.', ['sprint' => 2]);
  $id = (int) $node->id();
  $check((int) $node->get('field_hu_sprint')->value === 2, 'create stores integer sprint');
  $check($contains($board('2'), $id) && !$contains($board('0'), $id) && !$contains($board('none'), $id), 'filter isolates matching sprint');
  $check($contains($board(''), $id), 'all sprints includes assigned HU');
  $revision = (int) $node->getRevisionId();
  $node = HuWorkflow::save($id, $revision, 'new', NULL, NULL, ['sprint' => 0]);
  $check((int) $node->get('field_hu_sprint')->value === 0 && (int) $node->getRevisionId() !== $revision, 'sprint-only edit creates revision and accepts zero');
  $node = HuWorkflow::save($id, (int) $node->getRevisionId(), 'in_progress');
  $check((int) $node->get('field_hu_sprint')->value === 0, 'card movement preserves sprint');
  $zero = $board('0');
  $check($contains($zero, $id) && !$contains($board('none'), $id), 'zero differs from unassigned');
  $check(str_contains($zero['board']['#create_url'], 'sprint=0'), 'creation link retains sprint');
  $html = (string) \Drupal::service('renderer')->renderInIsolation($zero);
  $check(str_contains($html, 'value="0" selected') && str_contains($html, 'Sprint 0'), 'zero selected in rendered filter and card');
  $node = HuWorkflow::save($id, (int) $node->getRevisionId(), 'in_progress', NULL, NULL, ['sprint' => NULL]);
  $check($node->get('field_hu_sprint')->isEmpty() && $contains($board('none'), $id), 'clearing sprint restores unassigned filter');
  $revision = (int) $node->getRevisionId();
  $same = HuWorkflow::save($id, $revision, 'in_progress', NULL, NULL, ['sprint' => NULL]);
  $check((int) $same->getRevisionId() === $revision, 'unchanged sprint is idempotent');
  try {
    HuWorkflow::save($id, $revision, 'in_progress', NULL, NULL, ['sprint' => 1.5]);
    throw new RuntimeException('Decimal accepted');
  }
  catch (InvalidArgumentException) { $check(TRUE, 'service rejects non-integers'); }
  $stack->push(Request::create('/admin/nelkano/backlog/add', 'GET', ['sprint' => '0']));
  try {
    $form = (new HuForm())->buildForm([], new FormState());
    $check($form['sprint']['#default_value'] === 0 && $form['sprint']['#step'] === 1, 'new HU preselects filtered sprint');
    $check(str_contains($form['actions']['cancel']['#url']->toString(), 'sprint=0'), 'cancel retains filter');
  }
  finally { $stack->pop(); }
  echo "SUCCESS: $checks sprint checks.\n";
}
finally {
  $transaction->rollBack();
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  $switcher->switchBack();
  echo "Synthetic content rolled back. No existing HU modified.\n";
}
