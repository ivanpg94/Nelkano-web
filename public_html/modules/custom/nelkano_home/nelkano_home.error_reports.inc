<?php

declare(strict_types=1);

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;

/**
 * Ensures that emulator reports are normal Drupal content and a View.
 */
function nelkano_home_ensure_error_report_content(): void {
  if (!NodeType::load('nelkano_error_report')) {
    NodeType::create([
      'type' => 'nelkano_error_report',
      'name' => 'Reporte de error del emulador',
      'description' => 'Reporte privado enviado desde una sesion autenticada de la aplicacion Nelkano.',
      'new_revision' => FALSE,
      'preview_mode' => 0,
      'display_submitted' => TRUE,
    ])->save();
  }
  $report_type = NodeType::load('nelkano_error_report');
  if (!FieldConfig::loadByName('node', 'nelkano_error_report', 'body')) {
    node_add_body_field($report_type, 'Pasos para reproducir');
  }

  $fields = [
    'field_report_status' => ['list_string', 'Estado', ['allowed_values' => ['new' => 'Nuevo', 'in_progress' => 'En proceso', 'resolved' => 'Resuelto', 'rejected' => 'Descartado']]],
    'field_report_category' => ['list_string', 'Categoria', ['allowed_values' => ['crash' => 'Cierre inesperado', 'freeze' => 'Bloqueo', 'graphics' => 'Graficos', 'audio' => 'Audio', 'controls' => 'Controles', 'performance' => 'Rendimiento', 'save_state' => 'Guardado', 'other' => 'Otro']]],
    'field_report_system' => ['string', 'Sistema', ['max_length' => 32]],
    'field_report_game' => ['string', 'Juego', ['max_length' => 180]],
    'field_report_rom_hash' => ['string', 'Identidad de ROM', ['max_length' => 128]],
    'field_report_slot' => ['integer', 'Slot', []],
    'field_report_expected' => ['string_long', 'Resultado esperado', []],
    'field_report_actual' => ['string_long', 'Resultado actual', []],
    'field_report_app_version' => ['string', 'Version de la aplicacion', ['max_length' => 64]],
    'field_report_app_build' => ['integer', 'Build de la aplicacion', []],
    'field_report_core_version' => ['string', 'Version del core', ['max_length' => 128]],
    'field_report_state_format' => ['string', 'Formato del estado', ['max_length' => 64]],
    'field_report_device_id' => ['string', 'ID del dispositivo', ['max_length' => 128]],
    'field_report_device_model' => ['string', 'Modelo del dispositivo', ['max_length' => 160]],
    'field_report_android' => ['string', 'Version de Android', ['max_length' => 64]],
    'field_report_abis' => ['string', 'ABIs', ['max_length' => 255]],
    'field_report_gpu' => ['string', 'GPU', ['max_length' => 255]],
    'field_report_backend' => ['string', 'Backend', ['max_length' => 128]],
    'field_report_settings' => ['string_long', 'Configuracion del emulador', []],
    'field_report_logs' => ['string_long', 'Registros de diagnostico', []],
    'field_report_state_uri' => ['string', 'URI privada del estado', ['max_length' => 512]],
    'field_report_state_name' => ['string', 'Nombre del estado', ['max_length' => 255]],
    'field_report_state_size' => ['integer', 'Tamano del estado', ['size' => 'big']],
    'field_report_state_sha256' => ['string', 'SHA-256 del estado', ['max_length' => 64]],
    'field_report_screenshot_uri' => ['string', 'URI privada de la captura', ['max_length' => 512]],
  ];

  foreach ($fields as $field_name => [$type, $label, $settings]) {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
        'cardinality' => 1,
        'settings' => $settings,
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'nelkano_error_report', $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'nelkano_error_report',
        'label' => $label,
        'required' => in_array($field_name, [
          'field_report_status', 'field_report_category', 'field_report_system',
          'field_report_rom_hash', 'field_report_slot', 'field_report_state_uri',
          'field_report_state_name', 'field_report_state_size', 'field_report_state_sha256',
        ], TRUE),
      ])->save();
    }
  }

  nelkano_home_enable_error_report_displays();

  $view_definition = nelkano_home_error_report_view_definition();
  $view = View::load('nelkano_error_reports');
  if (!$view) {
    View::create($view_definition)->save();
  }
  else {
    foreach (['label', 'description', 'tag', 'base_table', 'base_field', 'display'] as $key) {
      $view->set($key, $view_definition[$key]);
    }
    $view->setStatus(TRUE);
    $view->save();
  }
}

/**
 * Exposes report fields without replacing unrelated display configuration.
 */
function nelkano_home_enable_error_report_displays(): void {
  $repository = \Drupal::service('entity_display.repository');
  $form = $repository->getFormDisplay('node', 'nelkano_error_report');
  $view = $repository->getViewDisplay('node', 'nelkano_error_report');
  $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'nelkano_error_report');
  $weight = 10;
  foreach ($fields as $name => $definition) {
    if (!str_starts_with($name, 'field_report_')) {
      continue;
    }
    [$widget, $formatter] = match ($definition->getType()) {
      'integer' => ['number', 'number_integer'],
      'list_string' => ['options_select', 'list_default'],
      'string_long' => ['string_textarea', 'basic_string'],
      default => ['string_textfield', 'string'],
    };
    if (in_array($name, ['field_report_state_uri', 'field_report_screenshot_uri'], TRUE)) {
      $formatter = 'nelkano_report_attachment';
    }
    $form->setComponent($name, ['type' => $widget, 'weight' => $weight]);
    $view->setComponent($name, ['type' => $formatter, 'label' => 'above', 'weight' => $weight]);
    $weight++;
  }
  $form->save();
  $view->save();
}

/**
 * Renames the legacy reviewing state, including historical field revisions.
 */
function nelkano_home_update_error_report_statuses(): void {
  $database = \Drupal::database();
  foreach (['node__field_report_status', 'node_revision__field_report_status'] as $table) {
    if ($database->schema()->tableExists($table)) {
      $database->update($table)->fields(['field_report_status_value' => 'in_progress'])
        ->condition('bundle', 'nelkano_error_report')
        ->condition('field_report_status_value', 'reviewing')->execute();
    }
  }
  $storage = FieldStorageConfig::loadByName('node', 'field_report_status');
  if ($storage) {
    $storage->setSetting('allowed_values', [
      'new' => 'Nuevo', 'in_progress' => 'En proceso',
      'resolved' => 'Resuelto', 'rejected' => 'Descartado',
    ])->save();
  }
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list']);
}

/**
 * Returns the versioned Views definition used by the admin and CSV export.
 */
function nelkano_home_error_report_view_definition(): array {
  $list_fields = [
    'nid' => nelkano_home_error_report_view_field('node_field_data', 'nid', 'ID', 'node', 'nid', TRUE),
    'field_report_status' => nelkano_home_error_report_view_field('node__field_report_status', 'field_report_status', 'Estado'),
    'field_report_system' => nelkano_home_error_report_view_field('node__field_report_system', 'field_report_system', 'Sistema'),
    'field_report_game' => nelkano_home_error_report_view_field('node__field_report_game', 'field_report_game', 'Juego'),
    'title' => nelkano_home_error_report_view_field('node_field_data', 'title', 'Resumen', 'node', 'title'),
    'uid' => nelkano_home_error_report_view_field('node_field_data', 'uid', 'Usuario', 'node', 'uid'),
    'created' => nelkano_home_error_report_view_field('node_field_data', 'created', 'Fecha', 'node', 'created'),
  ];
  $export_fields = $list_fields;
  foreach ([
    'body' => ['node__body', 'body', 'Pasos para reproducir'],
    'field_report_category' => ['node__field_report_category', 'field_report_category', 'Categoria'],
    'field_report_rom_hash' => ['node__field_report_rom_hash', 'field_report_rom_hash', 'Identidad de ROM'],
    'field_report_slot' => ['node__field_report_slot', 'field_report_slot', 'Slot'],
    'field_report_expected' => ['node__field_report_expected', 'field_report_expected', 'Resultado esperado'],
    'field_report_actual' => ['node__field_report_actual', 'field_report_actual', 'Resultado actual'],
    'field_report_app_version' => ['node__field_report_app_version', 'field_report_app_version', 'Version de app'],
    'field_report_app_build' => ['node__field_report_app_build', 'field_report_app_build', 'Build de app'],
    'field_report_core_version' => ['node__field_report_core_version', 'field_report_core_version', 'Version del core'],
    'field_report_state_format' => ['node__field_report_state_format', 'field_report_state_format', 'Formato de estado'],
    'field_report_device_id' => ['node__field_report_device_id', 'field_report_device_id', 'ID de dispositivo'],
    'field_report_device_model' => ['node__field_report_device_model', 'field_report_device_model', 'Modelo de dispositivo'],
    'field_report_android' => ['node__field_report_android', 'field_report_android', 'Android'],
    'field_report_abis' => ['node__field_report_abis', 'field_report_abis', 'ABIs'],
    'field_report_gpu' => ['node__field_report_gpu', 'field_report_gpu', 'GPU'],
    'field_report_backend' => ['node__field_report_backend', 'field_report_backend', 'Backend'],
    'field_report_settings' => ['node__field_report_settings', 'field_report_settings', 'Configuracion'],
    'field_report_logs' => ['node__field_report_logs', 'field_report_logs', 'Logs'],
    'field_report_state_uri' => ['node__field_report_state_uri', 'field_report_state_uri', 'URI privada del estado'],
    'field_report_state_name' => ['node__field_report_state_name', 'field_report_state_name', 'Archivo de estado'],
    'field_report_state_size' => ['node__field_report_state_size', 'field_report_state_size', 'Bytes del estado'],
    'field_report_state_sha256' => ['node__field_report_state_sha256', 'field_report_state_sha256', 'SHA-256'],
    'field_report_screenshot_uri' => ['node__field_report_screenshot_uri', 'field_report_screenshot_uri', 'Captura privada'],
  ] as $key => [$table, $field, $label]) {
    $export_fields[$key] = nelkano_home_error_report_view_field($table, $field, $label);
  }

  return [
    'id' => 'nelkano_error_reports',
    'label' => 'Reportes de errores de Nelkano',
    'module' => 'views',
    'description' => 'Contenido privado recibido desde el emulador y preparado para exportacion.',
    'tag' => 'Nelkano',
    'base_table' => 'node_field_data',
    'base_field' => 'nid',
    'status' => TRUE,
    'display' => [
      'default' => [
        'id' => 'default',
        'display_title' => 'Principal',
        'display_plugin' => 'default',
        'position' => 0,
        'display_options' => [
          'access' => ['type' => 'perm', 'options' => ['perm' => 'administer nelkano error reports']],
          'cache' => ['type' => 'tag'],
          'query' => ['type' => 'views_query', 'options' => ['disable_sql_rewrite' => TRUE, 'distinct' => FALSE]],
          'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50, 'offset' => 0]],
          'style' => ['type' => 'table', 'options' => ['columns' => array_combine(array_keys($list_fields), array_keys($list_fields)), 'default' => 'created', 'info' => []]],
          'row' => ['type' => 'fields'],
          'fields' => $list_fields,
          'filters' => [
            'type' => [
              'id' => 'type', 'table' => 'node_field_data', 'field' => 'type',
              'entity_type' => 'node', 'entity_field' => 'type', 'plugin_id' => 'bundle',
              'operator' => 'in', 'value' => ['nelkano_error_report' => 'nelkano_error_report'], 'group' => 1,
            ],
          ],
          'sorts' => [
            'nid' => [
              'id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid', 'order' => 'DESC',
              'entity_type' => 'node', 'entity_field' => 'nid', 'plugin_id' => 'standard',
            ],
          ],
        ],
      ],
      'block_1' => [
        'id' => 'block_1', 'display_title' => 'Listado administrativo',
        'display_plugin' => 'block', 'position' => 1,
        'display_options' => ['display_extenders' => []],
      ],
      'data_export_1' => [
        'id' => 'data_export_1', 'display_title' => 'Exportacion CSV',
        'display_plugin' => 'data_export', 'position' => 2,
        'display_options' => [
          'fields' => $export_fields,
          'path' => 'admin/nelkano/error-reports/export.csv',
          'filename' => 'nelkano-error-reports-[date:custom:Y-m-d].csv',
          'automatic_download' => TRUE,
          'style' => ['type' => 'data_export', 'options' => ['formats' => ['csv' => 'csv'], 'csv_settings' => ['delimiter' => ',', 'enclosure' => '"', 'escape_char' => '\\', 'strip_tags' => TRUE, 'trim' => TRUE, 'encoding' => 'utf8']]],
          'export_method' => 'batch', 'export_batch_size' => 100, 'export_limit' => 0,
          'export_filesystem' => 'private', 'redirect_to_display' => 'none',
          'include_query_params' => FALSE, 'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
          'display_extenders' => [],
        ],
      ],
    ],
  ];
}

/**
 * Builds a compact Views field handler definition.
 */
function nelkano_home_error_report_view_field(string $table, string $field, string $label, string $entity_type = 'node', ?string $entity_field = NULL, bool $admin_link = FALSE): array {
  $definition = [
    'id' => $field, 'table' => $table, 'field' => $field,
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'plugin_id' => 'field', 'label' => $label, 'exclude' => FALSE,
    'alter' => ['alter_text' => $admin_link, 'text' => $admin_link ? '<a href="/admin/nelkano/error-reports/{{ nid }}">#{{ nid }}</a>' : '', 'make_link' => FALSE, 'strip_tags' => FALSE, 'trim' => FALSE, 'html' => FALSE],
    'element_default_classes' => TRUE, 'empty' => '', 'hide_empty' => FALSE,
    'empty_zero' => FALSE, 'hide_alter_empty' => TRUE,
    'click_sort_column' => 'value', 'type' => 'string', 'settings' => [],
    'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE,
    'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE,
    'delta_first_last' => FALSE, 'multi_type' => 'separator', 'separator' => ', ',
    'field_api_classes' => FALSE,
  ];
  if ($entity_type !== '') {
    $definition['entity_type'] = $entity_type;
  }
  if ($entity_field !== NULL) {
    $definition['entity_field'] = $entity_field;
  }
  return $definition;
}

/**
 * Migrates rows created by the provisional custom entity implementation.
 */
function nelkano_home_migrate_legacy_error_reports(): void {
  $database = \Drupal::database();
  if (!$database->schema()->tableExists('nelkano_error_report')) {
    return;
  }
  $rows = $database->select('nelkano_error_report', 'r')->fields('r')->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  foreach ($rows as $row) {
    Node::create([
      'type' => 'nelkano_error_report', 'title' => (string) $row['summary'],
      'uid' => (int) $row['uid'], 'status' => 0, 'created' => (int) $row['created'],
      'body' => ['value' => (string) $row['steps'], 'format' => 'plain_text'],
      'field_report_status' => (string) $row['status'],
      'field_report_category' => (string) $row['category'],
      'field_report_system' => (string) $row['system'],
      'field_report_game' => (string) $row['game_label'],
      'field_report_rom_hash' => (string) $row['rom_hash'],
      'field_report_slot' => (int) $row['slot'],
      'field_report_expected' => (string) $row['expected_result'],
      'field_report_actual' => (string) $row['actual_result'],
      'field_report_app_version' => (string) $row['app_version'],
      'field_report_app_build' => (int) $row['app_build'],
      'field_report_core_version' => (string) $row['core_version'],
      'field_report_state_format' => (string) $row['state_format'],
      'field_report_device_id' => (string) $row['device_id'],
      'field_report_device_model' => (string) $row['device_model'],
      'field_report_android' => (string) $row['android_version'],
      'field_report_abis' => (string) $row['abis'],
      'field_report_gpu' => (string) $row['gpu'],
      'field_report_backend' => (string) $row['backend'],
      'field_report_settings' => (string) $row['settings_json'],
      'field_report_logs' => (string) $row['logs'],
      'field_report_state_uri' => (string) $row['state_uri'],
      'field_report_state_name' => (string) $row['state_filename'],
      'field_report_state_size' => (int) $row['state_size'],
      'field_report_state_sha256' => (string) $row['state_sha256'],
      'field_report_screenshot_uri' => (string) $row['screenshot_uri'],
    ])->save();
  }
  $database->schema()->dropTable('nelkano_error_report');
  \Drupal::service('keyvalue')->get('entity.definitions.installed')->delete('nelkano_error_report');
}
