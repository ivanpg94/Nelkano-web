<?php

/** Local regression: ordinary term entry leaves the existing API terms intact. */
if (PHP_SAPI !== 'cli' || !class_exists('\\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_WORKFLOW_TEST') !== '1') {
  throw new RuntimeException('Run only on local Docker with NELKANO_LOCAL_WORKFLOW_TEST=1.');
}

use Drupal\Core\Form\FormState;
use Drupal\nelkano_home\Service\ReportWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++;
  echo "PASS: $label\n";
};
$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$before = array_map(static fn($term) => $term->toArray(), $storage->loadByProperties(['vid' => WorkflowStatus::VOCABULARY]));
$original_ids = array_map([WorkflowStatus::class, 'termId'], array_keys(ReportWorkflow::STATUSES));
$options = WorkflowStatus::options();
$transaction = \Drupal::database()->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
try {
  $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('taxonomy_term', WorkflowStatus::VOCABULARY);
  $check(!isset($definitions['field_workflow_api_status']), 'additional API-equivalence field removed');
  $check(!$definitions['field_workflow_code']->isRequired(), 'internal original identifier is optional for ordinary terms');
  $term = Term::create(['vid' => WorkflowStatus::VOCABULARY]);
  $build = \Drupal::service('entity.form_builder')->getForm($term);
  $html = (string) \Drupal::service('renderer')->renderRoot($build);
  $check(!str_contains($html, 'name="field_workflow_code') && !str_contains($html, 'name="field_workflow_api_status'), 'neither technical field appears in the native add form');
  $object = \Drupal::entityTypeManager()->getFormObject('taxonomy_term', 'default')->setEntity($term);
  // Even a term with the same visible name must not replace an API identifier.
  $state = (new FormState())->setValues([
    'name' => [['value' => 'Nuevo']], 'status' => 1, 'weight' => 0,
    'parent' => [0], 'op' => (string) t('Save'),
  ])->disableRedirect();
  \Drupal::formBuilder()->submitForm($object, $state);
  $term = $object->getEntity();
  $check(!$state->hasAnyErrors() && !$term->isNew(), 'native form creates term with name only');
  $check($term->get('field_workflow_code')->isEmpty(), 'new term receives no API code');
  $check(array_map([WorkflowStatus::class, 'termId'], array_keys(ReportWorkflow::STATUSES)) === $original_ids, 'all five original API IDs unchanged');
  $check(WorkflowStatus::options() === $options, 'additional term is excluded from original API workflow options');
  $term->setName('Término adicional renombrado')->save();
  $check($storage->load($term->id())->label() === 'Término adicional renombrado', 'ordinary term can be renamed');
  $check($term->access('delete', User::load(1)), 'ordinary term can be deleted in the interface');
  $id = $term->id();
  $term->delete();
  $check($storage->load($id) === NULL, 'ordinary term can be deleted without affecting original terms');
  foreach ($original_ids as $id) {
    $check(!$storage->load($id)->access('delete', User::load(1)), 'original API term remains protected: ' . $id);
  }
  $original = clone $storage->load($original_ids[0]);
  $original->set('field_workflow_code', NULL);
  try {
    nelkano_home_taxonomy_term_presave($original);
    $check(FALSE, 'original code protected');
  }
  catch (InvalidArgumentException) {
    $check(TRUE, 'original API identifier cannot be cleared');
  }
}
finally {
  $transaction->rollBack();
  unset($transaction);
  $storage->resetCache();
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['taxonomy_term_list']);
  \Drupal::messenger()->deleteAll();
  $switcher->switchBack();
}
$after = array_map(static fn($term) => $term->toArray(), $storage->loadByProperties(['vid' => WorkflowStatus::VOCABULARY]));
$check($before === $after, 'all original terms preserved and test data rolled back');
echo "SUCCESS: $checks ordinary taxonomy checks.\n";
