<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;

trait ConfigRowsFormTrait {

  private function buildConfigRowsElement(mixed $storedValue, array $definition, array $parents, string $language, FormStateInterface $form_state): array {
    $columns = $definition['columns'] ?? [];
    $input = $form_state->getUserInput();
    $input_exists = FALSE;
    $input_value = NestedArray::getValue($input, $parents, $input_exists);
    $rows_source = $input_exists ? ($input_value['rows'] ?? $input_value) : $storedValue;
    $rows = $this->storedConfigRows($rows_source, array_keys($columns), $definition);
    $count_key = implode(':', $parents);
    $counts = $this->getFormRowsCounts($form_state);
    $row_count = max((int) ($counts[$count_key] ?? 0), count($rows), 1);
    $rows = array_pad($rows, $row_count, []);
    $wrapper_id = 'nk-config-rows-' . preg_replace('/[^a-z0-9_-]+/i', '-', $count_key);

    $element = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['nk-config-rows']],
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];

    $element['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->adminLabel($definition['title'] ?? '', $language),
      '#attributes' => ['class' => ['nk-config-rows-title']],
    ];

    if (($definition['layout'] ?? '') === 'cards') {
      $element['rows'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['nk-config-rows-cards']],
      ];

      foreach ($rows as $index => $row) {
        $element['rows'][$index] = [
          '#type' => 'details',
          '#title' => $this->configRowCardTitle($row, $index, $language),
          '#open' => FALSE,
          '#attributes' => ['class' => ['nk-config-row-card']],
        ];
        $element['rows'][$index]['top'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['nk-config-row-card-top']],
        ];
        $element['rows'][$index]['top']['_delete'] = [
          '#type' => 'checkbox',
          '#title' => $this->adminLabel('Delete', $language),
          '#wrapper_attributes' => ['class' => ['nk-config-row-field', 'nk-config-row-field-delete']],
        ];
        foreach (['visible', 'filename', 'date'] as $top_key) {
          if (isset($columns[$top_key])) {
            $element['rows'][$index]['top'][$top_key] = $this->buildConfigRowsField($row, $top_key, $columns[$top_key], $language);
          }
        }
        $element['rows'][$index]['fields'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['nk-config-row-card-fields']],
        ];
        foreach ($columns as $key => $column) {
          if (in_array($key, ['visible', 'filename', 'date'], TRUE)) {
            continue;
          }
          $element['rows'][$index]['fields'][$key] = $this->buildConfigRowsField($row, $key, $column, $language);
        }
      }
    }
    else {
    $element['rows'] = [
      '#type' => 'table',
      '#header' => array_merge(
        ['#'],
        array_map(fn(array $column): string => $this->adminLabel($column['title'] ?? '', $language), $columns),
        [$this->adminLabel('Delete', $language)],
      ),
      '#attributes' => ['class' => ['nk-config-rows-table']],
    ];

    foreach ($rows as $index => $row) {
      $element['rows'][$index]['position'] = [
        '#plain_text' => (string) ($index + 1),
      ];
      foreach ($columns as $key => $column) {
        $element['rows'][$index][$key] = [
          '#type' => $column['type'] ?? 'textfield',
          '#title' => $this->adminLabel($column['title'] ?? $key, $language),
          '#title_display' => 'invisible',
          '#default_value' => $row[$key] ?? '',
          '#attributes' => ['placeholder' => $this->adminLabel($column['title'] ?? $key, $language)],
        ];
        if (($column['type'] ?? '') === 'managed_file') {
          $element['rows'][$index][$key]['#default_value'] = $this->configRowsManagedFileDefault($row[$key] ?? '');
          $element['rows'][$index][$key]['#upload_location'] = $column['upload_location'] ?? 'public://nelkano-releases';
          $element['rows'][$index][$key]['#upload_validators'] = $column['upload_validators'] ?? [
            'FileExtension' => ['extensions' => 'apk'],
          ];
          $element['rows'][$index][$key]['#description'] = $this->adminDescription($column['description'] ?? NULL, $language);
        }
      }
      $element['rows'][$index]['_delete'] = [
        '#type' => 'checkbox',
        '#title' => $this->adminLabel('Delete', $language),
        '#title_display' => 'invisible',
      ];
    }
    }

    $element['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['nk-config-rows-actions']],
    ];
    $element['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->adminLabel('Add row', $language),
      '#submit' => ['::addConfigRowSubmit'],
      '#ajax' => [
        'callback' => '::configRowsAjax',
        'wrapper' => $wrapper_id,
      ],
      '#limit_validation_errors' => [],
      '#name' => 'add_' . preg_replace('/[^a-z0-9_]+/i', '_', $count_key),
      '#nk_config_rows_parents' => $parents,
      '#nk_config_rows_count_key' => $count_key,
    ];

    if (!empty($definition['description'])) {
      $element['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->adminDescription($definition['description'], $language),
        '#attributes' => ['class' => ['description']],
      ];
    }

    return $element;
  }

  public function addConfigRowSubmit(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $count_key = (string) ($trigger['#nk_config_rows_count_key'] ?? '');
    if ($count_key === '') {
      return;
    }
    $parents = $trigger['#nk_config_rows_parents'] ?? [];
    $input_exists = FALSE;
    $input_value = NestedArray::getValue($form_state->getUserInput(), $parents, $input_exists);
    $posted_rows = $input_exists && is_array($input_value) ? ($input_value['rows'] ?? $input_value) : [];
    $current_count = is_array($posted_rows) ? count($posted_rows) : 0;
    $counts = $this->getFormRowsCounts($form_state);
    $counts[$count_key] = max($current_count, (int) ($counts[$count_key] ?? 0), 1) + 1;
    $this->setFormRowsCounts($form_state, $counts);
    $form_state->setRebuild(TRUE);
  }

  public function configRowsAjax(array &$form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    $parents = $trigger['#nk_config_rows_parents'] ?? [];
    $element = NestedArray::getValue($form, $parents);
    return is_array($element) ? $element : $form;
  }

  private function normalizeConfigRowsValue(mixed $value, array $definition): array {
    $columns = $definition['columns'] ?? [];
    $keys = array_keys($columns);
    $rows = $value['rows'] ?? $value ?? [];
    $normalized = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
      $row = $this->unpackConfigRowCard($row);
      if (!empty($row['_delete'])) {
        continue;
      }
      $item = [];
      $has_value = FALSE;
      foreach ($keys as $key) {
        $item[$key] = $this->normalizeConfigRowsColumnValue($row[$key] ?? '', $columns[$key] ?? []);
        $has_value = $has_value || $item[$key] !== '';
      }
      if ($has_value) {
        $normalized[] = $item;
      }
    }

    return $normalized;
  }

  private function storedConfigRows(mixed $storedValue, array $keys, array $definition = []): array {
    if (is_array($storedValue)) {
      $rows = [];
      foreach ($storedValue as $row) {
        if (!is_array($row)) {
          continue;
        }
        if (isset($row['fields']) && is_array($row['fields'])) {
          $row = $this->unpackConfigRowCard($row);
        }
        $item = [];
        foreach ($keys as $key) {
          $item[$key] = $this->storedConfigRowsString($row[$key] ?? '', $definition['columns'][$key] ?? []);
        }
        $rows[] = $item;
      }
      return $rows;
    }

    $legacy_keys = $definition['legacy_keys'] ?? $keys;
    $rows = [];
    foreach (preg_split('/\R/', trim((string) $storedValue)) ?: [] as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $parts = array_map('trim', explode('|', $line, count($legacy_keys)));
      $row = [];
      foreach ($legacy_keys as $index => $legacy_key) {
        $value = $parts[$index] ?? '';
        if ($legacy_key === 'paragraphs') {
          $value = implode("\n", array_values(array_filter(array_map('trim', preg_split('/\s*\|\|\s*/', $value) ?: []))));
        }
        $row[$legacy_key] = $value;
      }
      $rows[] = $row;
    }

    return $rows;
  }

  private function buildConfigRowsField(array $row, string $key, array $column, string $language): array {
    $field = [
      '#type' => $column['type'] ?? 'textfield',
      '#title' => $this->adminLabel($column['title'] ?? $key, $language),
      '#default_value' => $row[$key] ?? '',
      '#attributes' => ['placeholder' => $this->adminLabel($column['title'] ?? $key, $language)],
      '#wrapper_attributes' => ['class' => ['nk-config-row-field', 'nk-config-row-field-' . str_replace('_', '-', $key)]],
    ];

    if (($column['type'] ?? '') === 'managed_file') {
      $field['#default_value'] = $this->configRowsManagedFileDefault($row[$key] ?? '');
      $field['#upload_location'] = $column['upload_location'] ?? 'public://nelkano-releases';
      $field['#upload_validators'] = $column['upload_validators'] ?? [
        'FileExtension' => ['extensions' => 'apk'],
      ];
      $field['#description'] = $this->adminDescription($column['description'] ?? NULL, $language);
    }

    return $field;
  }

  private function unpackConfigRowCard(array $row): array {
    if (isset($row['top']) || isset($row['fields'])) {
      $top = is_array($row['top'] ?? NULL) ? $row['top'] : [];
      $fields = is_array($row['fields'] ?? NULL) ? $row['fields'] : [];
      return $top + $fields;
    }

    return $row;
  }

  private function configRowCardTitle(array $row, int $index, string $language): string {
    $version = trim((string) ($row['version'] ?? ''));
    $prefix = $this->adminLabel('Version', $language) . ' ' . ($index + 1);
    return $version !== '' ? $prefix . ' - ' . $version : $prefix;
  }

  private function normalizeConfigRowsColumnValue(mixed $value, array $column): string {
    if (($column['type'] ?? '') === 'managed_file') {
      $fid = is_array($value) ? (int) reset($value) : (int) $value;
      if ($fid <= 0) {
        return '';
      }
      $file = \Drupal\file\Entity\File::load($fid);
      if (!$file) {
        return '';
      }
      $file->setPermanent();
      $file->save();
      return $file->getFileUri();
    }

    if (is_bool($value)) {
      return $value ? '1' : '0';
    }

    return trim((string) $value);
  }

  private function storedConfigRowsString(mixed $value, array $column = []): string {
    if (($column['type'] ?? '') === 'managed_file') {
      if (is_array($value)) {
        $fid = (int) reset($value);
        $file = $fid > 0 ? \Drupal\file\Entity\File::load($fid) : NULL;
        return $file ? $file->getFileUri() : '';
      }
      return trim((string) $value);
    }
    if (is_array($value)) {
      return implode("\n", array_map('trim', array_filter(array_map('strval', $value))));
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    return (string) $value;
  }

  private function configRowsManagedFileDefault(mixed $value): array {
    if (is_array($value)) {
      $fid = (int) reset($value);
      return $fid > 0 ? [$fid] : [];
    }

    $uri = trim((string) $value);
    if ($uri === '') {
      return [];
    }

    $files = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $uri]);
    $file = reset($files);
    return $file ? [(int) $file->id()] : [];
  }

  private function getFormRowsCounts(FormStateInterface $form_state): array {
    return (array) ($form_state->get('nelkano_config_rows_counts') ?? []);
  }

  private function setFormRowsCounts(FormStateInterface $form_state, array $counts): void {
    $form_state->set('nelkano_config_rows_counts', $counts);
  }

}
