<?php

declare(strict_types=1);

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\nelkano_home\Service\ReportWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\views\Entity\View;

/** Use shipped UUIDs when updating another environment before config import. */
function nelkano_home_backlog_uuid(string $name): string {
  $config = \Drupal::service('config.storage.sync')->read($name);
  if (!empty($config['uuid'])) {
    return $config['uuid'];
  }
  $hex = md5('nelkano-backlog:' . $name);
  return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-3' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
}

/** Persistent sequence: deleting a HU never recycles its number. */
function nelkano_home_hu_sequence_schema(): array {
  return [
    'description' => 'Monotonic HU numbers; serial minus one is the visible number.',
    'fields' => ['id' => ['type' => 'serial', 'size' => 'big', 'not null' => TRUE]],
    'primary key' => ['id'],
  ];
}

/** Seed taxonomy content by stable API code, preserving existing IDs and labels. */
function nelkano_home_ensure_workflow_terms(): void {
  if (!Vocabulary::load(WorkflowStatus::VOCABULARY)) {
    Vocabulary::create(['uuid' => nelkano_home_backlog_uuid('taxonomy.vocabulary.' . WorkflowStatus::VOCABULARY), 'vid' => WorkflowStatus::VOCABULARY, 'name' => 'Estados de Nelkano'])->save();
  }
  nelkano_home_backlog_field('taxonomy_term', WorkflowStatus::VOCABULARY, 'field_workflow_code', 'string', 'Código API', ['max_length' => 32]);
  foreach (ReportWorkflow::STATUSES as $code => $label) {
    $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => WorkflowStatus::VOCABULARY, 'field_workflow_code' => $code]);
    if (!$existing) {
      Term::create(['vid' => WorkflowStatus::VOCABULARY, 'name' => $label, 'field_workflow_code' => $code])->save();
    }
    elseif (count($existing) > 1) {
      throw new \RuntimeException('Hay términos duplicados para el código de estado: ' . $code);
    }
  }
}

/** Additive configuration; does not replace customized report Views. */
function nelkano_home_ensure_backlog(): void {
  nelkano_home_ensure_workflow_terms();
  nelkano_home_allow_plain_workflow_terms();
  if (!NodeType::load('hu')) {
    NodeType::create(['uuid' => nelkano_home_backlog_uuid('node.type.hu'), 'type' => 'hu', 'name' => 'HU', 'description' => 'Historias de usuario del backlog de Nelkano.', 'new_revision' => TRUE, 'preview_mode' => 0, 'display_submitted' => FALSE])->save();
  }
  foreach (['hu', 'nelkano_error_report'] as $bundle) {
    nelkano_home_backlog_field('node', $bundle, WorkflowStatus::FIELD, 'entity_reference', 'Estado', ['target_type' => 'taxonomy_term'], [
      'handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => [WorkflowStatus::VOCABULARY => WorkflowStatus::VOCABULARY], 'auto_create' => FALSE],
    ]);
    FieldConfig::loadByName('node', $bundle, WorkflowStatus::FIELD)->setDefaultValueCallback('nelkano_home_default_workflow_status')->save();
  }
  nelkano_home_backlog_field('node', 'hu', 'field_hu_number', 'integer', 'Número', ['size' => 'big', 'unsigned' => TRUE]);
  FieldConfig::loadByName('node', 'hu', 'field_hu_number')->setRequired(FALSE)->save();
  nelkano_home_backlog_field('node', 'hu', 'field_hu_description', 'string_long', 'Descripción');
  $schema = \Drupal::database()->schema();
  if (!$schema->tableExists('nelkano_hu_sequence')) {
    $schema->createTable('nelkano_hu_sequence', nelkano_home_hu_sequence_schema());
  }
  $repo = \Drupal::service('entity_display.repository');
  foreach (['hu', 'nelkano_error_report'] as $bundle) {
    $form = $repo->getFormDisplay('node', $bundle);
    $display = $repo->getViewDisplay('node', $bundle);
    $form->setComponent(WorkflowStatus::FIELD, ['type' => 'options_select', 'weight' => 10]);
    $display->setComponent(WorkflowStatus::FIELD, ['type' => 'entity_reference_label', 'label' => 'above', 'weight' => 10, 'settings' => ['link' => FALSE]]);
    if ($bundle === 'hu') {
      if ($form->isNew()) { $form->set('uuid', nelkano_home_backlog_uuid('core.entity_form_display.node.hu.default')); }
      if ($display->isNew()) { $display->set('uuid', nelkano_home_backlog_uuid('core.entity_view_display.node.hu.default')); }
      $form->setComponent('title', ['type' => 'string_textfield', 'weight' => 0]);
      $form->setComponent('field_hu_description', ['type' => 'string_textarea', 'weight' => 5]);
      $form->removeComponent('field_hu_number');
      $display->setComponent('field_hu_number', ['type' => 'number_integer', 'weight' => 0, 'label' => 'above']);
      $display->setComponent('field_hu_description', ['type' => 'basic_string', 'weight' => 5, 'label' => 'above']);
    }
    else {
      // Retain the legacy field and its historical values for rollback/audit.
      FieldConfig::loadByName('node', 'nelkano_error_report', 'field_report_status')?->setRequired(FALSE)->save();
      $form->removeComponent('field_report_status');
      $display->removeComponent('field_report_status');
    }
    $form->save();
    $display->save();
  }
  nelkano_home_ensure_hu_sprint();
}

/** Add sprint without assigning existing stories or touching report settings. */
function nelkano_home_ensure_hu_sprint(): void {
  nelkano_home_backlog_field('node', 'hu', 'field_hu_sprint', 'integer', 'Sprint');
  FieldConfig::loadByName('node', 'hu', 'field_hu_sprint')->setRequired(FALSE)->save();
  $repository = \Drupal::service('entity_display.repository');
  $form = $repository->getFormDisplay('node', 'hu');
  if ($form->getComponent('field_hu_sprint') === NULL) {
    $form->setComponent('field_hu_sprint', ['type' => 'number', 'weight' => 8])->save();
  }
  $display = $repository->getViewDisplay('node', 'hu');
  if ($display->getComponent('field_hu_sprint') === NULL) {
    $display->setComponent('field_hu_sprint', ['type' => 'number_integer', 'label' => 'above', 'weight' => 8, 'settings' => ['thousand_separator' => '', 'prefix_suffix' => FALSE]])->save();
  }
}

function nelkano_home_backlog_field(string $entity, string $bundle, string $name, string $type, string $label, array $storage_settings = [], array $settings = []): void {
  if (!FieldStorageConfig::loadByName($entity, $name)) {
    FieldStorageConfig::create(['uuid' => nelkano_home_backlog_uuid('field.storage.' . $entity . '.' . $name), 'entity_type' => $entity, 'field_name' => $name, 'type' => $type, 'cardinality' => 1, 'settings' => $storage_settings])->save();
  }
  if (!FieldConfig::loadByName($entity, $bundle, $name)) {
    FieldConfig::create(['uuid' => nelkano_home_backlog_uuid('field.field.' . $entity . '.' . $bundle . '.' . $name), 'entity_type' => $entity, 'bundle' => $bundle, 'field_name' => $name, 'label' => $label, 'required' => TRUE, 'settings' => $settings])->save();
  }
}

/** Copy every translation and revision without saving nodes or changing receipts. */
function nelkano_home_migrate_report_taxonomy(): void {
  $db = \Drupal::database();
  $transaction = $db->startTransaction();
  try {
    foreach (['node__' , 'node_revision__'] as $prefix) {
      $source = $prefix . 'field_report_status';
      $target = $prefix . WorkflowStatus::FIELD;
      if (!$db->schema()->tableExists($source)) {
        continue;
      }
      foreach ($db->select($source, 's')->fields('s')->condition('bundle', 'nelkano_error_report')->execute() as $row) {
        $code = $row->field_report_status_value === 'reviewing' ? 'in_progress' : $row->field_report_status_value;
        $keys = ['entity_id' => $row->entity_id, 'revision_id' => $row->revision_id, 'langcode' => $row->langcode, 'delta' => $row->delta, 'deleted' => $row->deleted];
        $query = $db->select($target, 't');
        foreach ($keys as $key => $value) {
          $query->condition($key, $value);
        }
        if (!$query->countQuery()->execute()->fetchField()) {
          $db->insert($target)->fields($keys + ['bundle' => $row->bundle, WorkflowStatus::FIELD . '_target_id' => WorkflowStatus::termId($code)])->execute();
        }
      }
    }
    unset($transaction);
  }
  catch (\Throwable $e) {
    $transaction->rollBack();
    throw $e;
  }
  $view = View::load('nelkano_error_reports');
  if ($view) {
    $displays = $view->get('display');
    foreach ($displays as &$display) {
      $options = &$display['display_options'];
      foreach (['fields', 'sorts', 'filters'] as $kind) {
        foreach ($options[$kind] ?? [] as $key => $handler) {
          if (($handler['table'] ?? '') !== 'node__field_report_status') {
            continue;
          }
          $handler['table'] = 'node__' . WorkflowStatus::FIELD;
          $handler['field'] = WorkflowStatus::FIELD . ($kind === 'fields' ? '' : '_target_id');
          if ($kind === 'fields') {
            $handler['type'] = 'entity_reference_label';
            $handler['settings'] = ['link' => FALSE];
            $handler['click_sort_column'] = 'target_id';
            $handler['group_column'] = 'target_id';
          }
          elseif ($kind === 'filters') {
            $handler['plugin_id'] = 'taxonomy_index_tid';
            $handler['vid'] = WorkflowStatus::VOCABULARY;
            $handler['value'] = array_map(static fn($code) => WorkflowStatus::termId((string) $code), $handler['value'] ?? []);
          }
          $options[$kind][$key] = $handler;
        }
      }
      unset($options);
    }
    unset($display);
    $view->set('display', $displays)->save();
  }
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list']);
}

/** Keep taxonomy entry ordinary; only seeded terms carry internal API codes. */
function nelkano_home_allow_plain_workflow_terms(): void {
  FieldConfig::loadByName('taxonomy_term', WorkflowStatus::VOCABULARY, 'field_workflow_code')
    ->setLabel('Código API')->setRequired(FALSE)->save();
  // Remove the intermediate equivalence field, without deleting any terms.
  FieldConfig::loadByName('taxonomy_term', WorkflowStatus::VOCABULARY, 'field_workflow_api_status')?->delete();
  FieldStorageConfig::loadByName('taxonomy_term', 'field_workflow_api_status')?->delete();
  $form = \Drupal::service('entity_display.repository')->getFormDisplay('taxonomy_term', WorkflowStatus::VOCABULARY);
  if ($form->isNew()) {
    $form->set('uuid', nelkano_home_backlog_uuid('core.entity_form_display.taxonomy_term.' . WorkflowStatus::VOCABULARY . '.default'));
  }
  $form->removeComponent('field_workflow_code')->removeComponent('field_workflow_api_status')->save();
}
