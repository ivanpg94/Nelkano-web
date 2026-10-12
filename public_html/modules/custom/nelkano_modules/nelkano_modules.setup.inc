<?php

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\user\Entity\Role;

function nelkano_modules_uuid(string $name): string {
  $sync = \Drupal::service('config.storage.sync')->read($name);
  if (!empty($sync['uuid'])) { return $sync['uuid']; }
  $hex = md5('nelkano-modules:' . $name);
  return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-3' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
}

function nelkano_modules_field(string $bundle, string $name, string $type, string $label, array $settings = [], int $cardinality = 1, array $instance = []): void {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create(['uuid' => nelkano_modules_uuid('field.storage.node.' . $name),
      'entity_type' => 'node', 'field_name' => $name, 'type' => $type,
      'settings' => $settings, 'cardinality' => $cardinality])->save();
  }
  if (!FieldConfig::loadByName('node', $bundle, $name)) {
    FieldConfig::create(['uuid' => nelkano_modules_uuid('field.field.node.' . $bundle . '.' . $name),
      'entity_type' => 'node', 'bundle' => $bundle, 'field_name' => $name,
      'label' => $label, 'settings' => $instance])->save();
  }
}

function nelkano_modules_setup(): void {
  foreach (['nelkano_module' => 'Módulo', 'nelkano_module_release' => 'Versión de módulo'] as $id => $name) {
    if (!NodeType::load($id)) {
      NodeType::create(['uuid' => nelkano_modules_uuid('node.type.' . $id), 'type' => $id,
        'name' => $name, 'new_revision' => TRUE, 'preview_mode' => 0, 'display_submitted' => FALSE])->save();
    }
  }
  nelkano_modules_setup_images();
  nelkano_modules_setup_systems();
  $module = 'nelkano_module';
  nelkano_modules_field($module, 'field_module_description', 'string_long', 'Descripción');
  nelkano_modules_field($module, 'field_module_source', 'string', 'Código fuente', ['max_length' => 2048]);
  nelkano_modules_field($module, 'field_module_id', 'string', 'ID del módulo', ['max_length' => 128]);
  nelkano_modules_field($module, 'field_module_author', 'string', 'Huella de firma', ['max_length' => 64]);
  nelkano_modules_field($module, 'field_module_available', 'boolean', 'Disponible');
  nelkano_modules_field($module, 'field_module_platforms', 'string', 'Plataformas', ['max_length' => 32], 2);
  $release = 'nelkano_module_release';
  nelkano_modules_field($release, 'field_release_module', 'entity_reference', 'Módulo', ['target_type' => 'node'], 1,
    ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => [$module => $module]]]);
  foreach (['url' => [2048, 'Descarga'], 'version' => [32, 'Versión'], 'sdk' => [32, 'SDK'],
    'hash' => [64, 'SHA-256'], 'state' => [16, 'Disponibilidad'], 'error' => [255, 'Última comprobación'],
    'etag' => [256, 'ETag'], 'modified' => [128, 'Last-Modified']] as $key => [$size, $label]) {
    nelkano_modules_field($release, 'field_release_' . $key, 'string', $label, ['max_length' => $size]);
  }
  nelkano_modules_field($release, 'field_release_notes', 'string_long', 'Cambios de la versión');
  nelkano_modules_field($release, 'field_release_platforms', 'string', 'Plataformas', ['max_length' => 32], 2);
  nelkano_modules_field($release, 'field_release_capabilities', 'string', 'Permisos', ['max_length' => 64], 64);
  foreach (['size' => 'Tamaño', 'checked' => 'Comprobado', 'queued' => 'En cola'] as $key => $label) {
    nelkano_modules_field($release, 'field_release_' . $key, 'integer', $label, ['size' => 'big', 'unsigned' => TRUE]);
  }
  // Fields are managed by dedicated forms. Internal verification metadata is hidden.
  foreach ([$module, $release] as $bundle) {
    $form = \Drupal::service('entity_display.repository')->getFormDisplay('node', $bundle);
    $view = \Drupal::service('entity_display.repository')->getViewDisplay('node', $bundle);
    if ($form->isNew()) { $form->set('uuid', nelkano_modules_uuid('core.entity_form_display.node.' . $bundle . '.default')); }
    if ($view->isNew()) { $view->set('uuid', nelkano_modules_uuid('core.entity_view_display.node.' . $bundle . '.default')); }
    if (!$form->getComponent('title')) { $form->setComponent('title', ['type' => 'string_textfield']); }
    $form->save();
    $view->save();
  }
  $role = Role::load('authenticated');
  if ($role && !$role->hasPermission('create nelkano modules')) { $role->grantPermission('create nelkano modules')->save(); }
  foreach (['server' => Server::class, 'index' => Index::class] as $kind => $class) {
    if (!$class::load('nelkano_modules')) {
      $path = __DIR__ . '/config/catalog/search_api.' . $kind . '.nelkano_modules.yml';
      $class::create(\Drupal\Component\Serialization\Yaml::decode(file_get_contents($path)))->save();
    }
  }
  if (\Drupal::moduleHandler()->moduleExists('automated_cron')) {
    $cron = \Drupal::configFactory()->getEditable('automated_cron.settings');
    if ((int) $cron->get('interval') !== 3600) { $cron->set('interval', 3600)->save(); }
  }
}
/** Add optional illustrations without saving or deleting existing content. */
function nelkano_modules_setup_images(): void {
  nelkano_modules_field('nelkano_module', 'field_module_image', 'image', 'Imagen del módulo',
    ['uri_scheme' => 'public', 'target_type' => 'file'], 1,
    ['file_directory' => 'nelkano-modules/images', 'file_extensions' => 'png jpg jpeg webp',
      'max_filesize' => '2 MB', 'alt_field' => TRUE, 'alt_field_required' => FALSE, 'title_field' => FALSE]);
  foreach (['nelkano_module_card' => ['image_scale_and_crop', ['width' => 640, 'height' => 360]],
    'nelkano_module_detail' => ['image_scale', ['width' => 1200, 'height' => NULL, 'upscale' => FALSE]]] as $id => [$effect, $data]) {
    if (!\Drupal\image\Entity\ImageStyle::load($id)) {
      \Drupal\image\Entity\ImageStyle::create(['uuid' => nelkano_modules_uuid('image.style.' . $id),
        'name' => $id, 'label' => $id === 'nelkano_module_card' ? 'Módulos: tarjeta' : 'Módulos: ficha',
        'effects' => [nelkano_modules_uuid('image.effect.' . $id) => [
          'uuid' => nelkano_modules_uuid('image.effect.' . $id), 'id' => $effect, 'weight' => 0, 'data' => $data]]])->save();
    }
  }
}
/** Add a native multi-value field, preserving unspecified existing content. */
function nelkano_modules_setup_systems(): void {
  nelkano_modules_field('nelkano_module', 'field_module_systems', 'list_string', 'Sistemas compatibles',
    ['allowed_values' => \Drupal\nelkano_modules\Service\ModuleSystems::options()], -1);
  $index = Index::load('nelkano_modules');
  if ($index && !$index->getField('systems')) {
    $field = new \Drupal\search_api\Item\Field($index, 'systems');
    $field->setLabel('Sistemas compatibles')->setDatasourceId('entity:node')
      ->setPropertyPath('field_module_systems')->setType('string');
    $index->addField($field)->save();
    $index->reindex();
  }
}
