<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class NelkanoDocsSettingsForm extends ConfigFormBase {

  use AdminFormUiTrait;
  use ConfigRowsFormTrait;

  private const LANGUAGES = [
    'es' => 'Espanol',
    'en' => 'English',
  ];

  private const FIELDS = [
    'Privacy cookies' => [
      'privacy_cookies_eyebrow' => ['type' => 'textfield', 'title' => 'Etiqueta'],
      'privacy_cookies_title' => ['type' => 'textfield', 'title' => 'Titulo visible'],
      'privacy_cookies_intro' => ['type' => 'textarea', 'title' => 'Introduccion'],
      'privacy_cookies_sections' => [
        'type' => 'config_rows',
        'layout' => 'cards',
        'title' => 'Contenido estructurado',
        'description' => 'Add one row per section. Put each paragraph on its own line.',
        'columns' => [
          'visible' => ['title' => 'Visible', 'type' => 'checkbox'],
          'title' => ['title' => 'Title'],
          'paragraphs' => ['title' => 'Paragraphs', 'type' => 'textarea'],
        ],
        'legacy_keys' => ['title', 'paragraphs'],
      ],
    ],
    'Legal notice' => [
      'legal_notice_eyebrow' => ['type' => 'textfield', 'title' => 'Etiqueta'],
      'legal_notice_title' => ['type' => 'textfield', 'title' => 'Titulo visible'],
      'legal_notice_intro' => ['type' => 'textarea', 'title' => 'Introduccion'],
      'legal_notice_sections' => [
        'type' => 'config_rows',
        'layout' => 'cards',
        'title' => 'Contenido estructurado',
        'description' => 'Add one row per section. Put each paragraph on its own line.',
        'columns' => [
          'visible' => ['title' => 'Visible', 'type' => 'checkbox'],
          'title' => ['title' => 'Title'],
          'paragraphs' => ['title' => 'Paragraphs', 'type' => 'textarea'],
        ],
        'legacy_keys' => ['title', 'paragraphs'],
      ],
    ],
    'Releases' => [
      'releases_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'releases_title' => ['type' => 'textfield', 'title' => 'Title'],
      'releases_intro' => ['type' => 'textarea', 'title' => 'Intro'],
      'releases_items' => [
        'type' => 'config_rows',
        'layout' => 'cards',
        'title' => 'Release rows',
        'description' => 'Add one row per release. Put each change on its own line.',
        'columns' => [
          'visible' => ['title' => 'Mostrar version', 'type' => 'checkbox'],
          'platform' => ['title' => 'Aplicación', 'type' => 'select', 'options' => ['android' => 'Android', 'windows' => 'Windows', 'steamos' => 'SteamOS', 'sdk' => 'SDK Studio (Windows)']],
          'version_code' => ['title' => 'Código de actualización'],
          'version' => ['title' => 'Public version'],
          'apk_file' => [
            'title' => 'Archivo de descarga (APK, MSI o ZIP)',
            'type' => 'managed_file',
            'upload_location' => 'private://nelkano-releases',
            'upload_validators' => ['FileExtension' => ['extensions' => 'apk msi zip']],
            'description' => 'Sube el archivo de esta aplicación. Mostrar versión controla el enlace y la descarga; desmárcalo para despublicar.',
          ],
          'installer_file' => [
            'title' => 'Instalador de Windows (MSI)',
            'type' => 'managed_file',
            'upload_location' => 'private://nelkano-releases',
            'upload_validators' => ['FileExtension' => ['extensions' => 'msi']],
            'description' => 'Descarga inicial de Studio. El ZIP separado se conserva para las actualizaciones automáticas.',
          ],
          'filename' => ['title' => 'Nombre del archivo'],
          'date' => ['title' => 'Publication date'],
          'changes' => ['title' => 'Changelog items', 'type' => 'textarea'],
        ],
      ],
    ],
    'Compatibility' => [
      'compatibility_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'compatibility_title' => ['type' => 'textfield', 'title' => 'Title'],
      'compatibility_intro' => ['type' => 'textarea', 'title' => 'Intro'],
      'compatibility_items' => [
        'type' => 'config_rows',
        'layout' => 'cards',
        'title' => 'Compatibility rows',
        'description' => 'Add one row per system.',
        'columns' => [
          'system' => ['title' => 'System'],
          'status' => ['title' => 'Status'],
          'works' => ['title' => 'Works', 'type' => 'textarea'],
          'limitations' => ['title' => 'Limitations', 'type' => 'textarea'],
          'last_review' => ['title' => 'Last review'],
        ],
      ],
    ],
    'Security' => [
      'security_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'security_title' => ['type' => 'textfield', 'title' => 'Title'],
      'security_intro' => ['type' => 'textarea', 'title' => 'Intro'],
      'security_sections' => [
        'type' => 'config_rows',
        'layout' => 'cards',
        'title' => 'Security sections',
        'legacy_keys' => ['title', 'description'],
        'description' => 'Add one row per section.',
        'columns' => [
          'visible' => ['title' => 'Visible', 'type' => 'checkbox'],
          'title' => ['title' => 'Title'],
          'description' => ['title' => 'Description', 'type' => 'textarea'],
        ],
      ],
    ],
  ];

  public function getFormId(): string {
    return 'nelkano_docs_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['nelkano_home.docs'];
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $page_key = 'all'): array {
    $page_key = (string) (\Drupal::routeMatch()->getParameter('page_key') ?: $page_key);
    $config = $this->config('nelkano_home.docs');
    $section_filter = $this->sectionFilter($page_key);
    $meta = $this->pageMeta($page_key);
    $active_language = $this->activeAdminLanguage();
    $this->applyNelkanoAdminChrome(
      $form,
      $page_key,
      $meta['title'],
      $meta['description'],
      $meta['public_path'],
      $active_language,
    );

    $form['page_key'] = [
      '#type' => 'value',
      '#value' => $page_key,
    ];

    $form['active_language'] = [
      '#type' => 'value',
      '#value' => $active_language,
    ];

    foreach (self::LANGUAGES as $langcode => $language_label) {
      if ($langcode !== $active_language) {
        continue;
      }
      $form[$langcode] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => ['class' => ['nk-admin-language-fields']],
      ];

      foreach (self::FIELDS as $section_label => $fields) {
        $section_key = strtolower(str_replace(' ', '_', $section_label));
        if ($section_filter !== NULL && $section_key !== $section_filter) {
          continue;
        }
        $form[$langcode][$section_key] = [
          '#type' => 'details',
          '#title' => $this->adminLabel($this->sectionUiTitle($section_label), $active_language),
          '#open' => FALSE,
        ];

        $form[$langcode][$section_key]['save_section'] = ['#type'=>'submit','#value'=>$active_language==='es'?'Guardar sección':'Save section','#name'=>'save_'.$section_key,'#r_section'=>$section_key,'#attributes'=>['class'=>['r-save-section']]];
        foreach ($fields as $key => $definition) {
          if ($definition['type'] === 'config_rows') {
            $stored_value = $config->get("$langcode.$key") ?? '';
            if ($key === 'releases_items' && $stored_value === '') {
              $stored_value = $this->legacyReleaseRows($config->get($langcode) ?? []);
            }
            if ($key === 'releases_items') {
              $form[$langcode][$section_key][$key] = ['#type' => 'container', '#tree' => TRUE];
              foreach (['android' => 'Android', 'windows' => 'Windows', 'steamos' => 'SteamOS', 'sdk' => 'SDK'] as $platform => $label) {
                $platform_definition = $definition;
                $platform_definition['title'] = $active_language === 'es' ? 'Versiones publicadas' : 'Published releases';
                $platform_definition['columns']['platform'] = ['title' => 'Aplicación', 'type' => 'value', 'value' => $platform];
                $platform_definition['columns']['apk_file']['upload_validators']['FileExtension']['extensions'] = \Drupal\nelkano_home\Service\ReleaseArtifact::PLATFORMS[$platform][1];
                if ($platform === 'sdk') {
                  $platform_definition['columns']['apk_file']['title'] = 'Paquete de actualización (ZIP)';
                  $platform_definition['columns']['apk_file']['description'] = 'Contenido completo para el actualizador de Studio. No subas el MSI en este campo.';
                }
                else unset($platform_definition['columns']['installer_file']);
                $stored_rows = array_values(array_filter(is_array($stored_value) ? $stored_value : [], static fn($row) => \Drupal\nelkano_home\Service\ReleaseArtifact::platform($row) === $platform));
                $group = $this->buildConfigRowsElement($stored_rows, $platform_definition, [$langcode, $section_key, $key, $platform], $active_language, $form_state);
                $group['#attributes']['class'][] = 'r-release-platform-group';
                $group['#type'] = 'details'; $group['#title'] = $label; $group['#open'] = FALSE;
                $form[$langcode][$section_key][$key][$platform] = $group;
              }
              continue;
            }
            $form[$langcode][$section_key][$key] = $this->buildConfigRowsElement(
              $stored_value,
              $definition,
              [$langcode, $section_key, $key],
              $active_language,
              $form_state,
            );
            $form[$langcode][$section_key][$key]['#title'] = $this->adminLabel($definition['title'], $active_language);
          }
          else {
            $form[$langcode][$section_key][$key] = [
              '#type' => $definition['type'],
              '#title' => $this->adminLabel($definition['title'], $active_language),
              '#default_value' => $config->get("$langcode.$key") ?? '',
              '#description' => $this->adminDescription($definition['description'] ?? NULL, $active_language),
            ];
          }
        }
        $row_keys=array_keys(array_filter($fields,static fn($d)=>$d['type']==='config_rows'));
        $header_keys=array_values(array_diff(array_keys($fields),$row_keys));
        $form[$langcode][$section_key]['#title']=$active_language==='es'?'Cabecera':'Header';
        $form[$langcode][$section_key]['save_section']['#r_keys']=$header_keys;
        foreach($row_keys as $row_key){
          $card=$section_key.'_content';
          $form[$langcode][$card]=['#type'=>'details','#title'=>$active_language==='es'?'Contenido':'Content','#open'=>FALSE];
          $form[$langcode][$card][$row_key]=$form[$langcode][$section_key][$row_key];
          $form[$langcode][$card][$row_key]['#parents']=[$langcode,$section_key,$row_key];
          unset($form[$langcode][$section_key][$row_key]);
          $form[$langcode][$card]['save_section']=['#type'=>'submit','#value'=>$active_language==='es'?'Guardar sección':'Save section','#name'=>'save_'.$card,'#r_section'=>$section_key,'#r_keys'=>[$row_key],'#attributes'=>['class'=>['r-save-section']]];
        }
      }
    }

    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $active_language === 'es' ? 'Guardar cambios' : 'Save changes';
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $language = (string) $form_state->getValue('active_language', 'es');
    $value = $form_state->getValue([$language, 'releases', 'releases_items']);
    if ($value === NULL) return;
    $rows = $this->normalizeReleaseGroups($value, FALSE);
    $seen = [];
    foreach ($rows as $row) {
      $platform = \Drupal\nelkano_home\Service\ReleaseArtifact::platform($row);
      $format = \Drupal\nelkano_home\Service\ReleaseArtifact::PLATFORMS[$platform][1] ?? '';
      $key = $platform . ':' . $row['version'];
      $code = trim((string) $row['version_code']);
      $error = '';
      if (!$format || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $row['version'])) $error = 'Selecciona una aplicación y una versión válida, por ejemplo 1.0.0-beta.';
      elseif (isset($seen[$key]) || ($code !== '' && isset($seen[$platform . ':build:' . $code]))) $error = 'No repitas la versión ni el código de actualización dentro de una aplicación.';
      elseif ($code !== '' && (!ctype_digit($code) || (int) $code < 1 || strlen($code) > 10 || (int) $code > 2147483647)) $error = 'El código de actualización debe ser un entero positivo.';
      elseif ($row['apk_file'] !== '' && !str_ends_with($row['apk_file'], '.' . $format)) $error = 'El archivo no corresponde a la aplicación: Android APK, Windows MSI, SteamOS y SDK ZIP.';
      if (($row['installer_file'] ?? '') !== '' && ($platform !== 'sdk' || !str_ends_with($row['installer_file'], '.msi'))) $error = 'El instalador adicional solo admite MSI de SDK Studio.';
      if ($error !== '') $form_state->setErrorByName($language . '][releases][releases_items', $this->t($error));
      $seen[$key] = TRUE;
      if ($code !== '') $seen[$platform . ':build:' . $code] = TRUE;
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('nelkano_home.docs');
    $page_key = (string) ($form_state->getValue('page_key') ?? 'all');
    $active_language = (string) ($form_state->getValue('active_language') ?? 'es');
    $section_filter = $this->sectionFilter($page_key);

    foreach (array_keys(self::LANGUAGES) as $langcode) {
      if ($langcode !== $active_language) {
        continue;
      }
      $language_values = $config->get($langcode) ?? [];
      foreach (self::FIELDS as $section_label => $fields) {
        $section_key = strtolower(str_replace(' ', '_', $section_label));
        $only_section=$form_state->getTriggeringElement()['#r_section']??NULL;
        if ($only_section!==NULL && $only_section!==$section_key) {continue;}
        if ($section_filter !== NULL && $section_key !== $section_filter) {
          continue;
        }
        foreach (array_keys($fields) as $key) {
          $only_keys=$form_state->getTriggeringElement()['#r_keys']??NULL;
          if ($only_keys!==NULL && !in_array($key,$only_keys,TRUE)) {continue;}
          $value = $form_state->getValue([$langcode, $section_key, $key]);
          $language_values[$key] = ($fields[$key]['type'] ?? '') === 'config_rows'
            ? ($key === 'releases_items' ? $this->normalizeReleaseGroups($value) : $this->normalizeConfigRowsValue($value, $fields[$key]))
            : $value;
        }
      }
      $config->set($langcode, $language_values);
    }

    if ($form_state->getValue([$active_language, 'releases', 'releases_items']) !== NULL && (($form_state->getTriggeringElement()['#r_section'] ?? 'releases') === 'releases')) {
      $source = $config->get($active_language . '.releases_items') ?? [];
      $other = $active_language === 'es' ? 'en' : 'es';
      $previous = [];
      foreach ($config->get($other . '.releases_items') ?? [] as $row) $previous[\Drupal\nelkano_home\Service\ReleaseArtifact::platform($row) . ':' . $row['version']] = $row;
      $translated = [];
      foreach ($source as $row) {
        $key = \Drupal\nelkano_home\Service\ReleaseArtifact::platform($row) . ':' . $row['version'];
        $copy = $previous[$key] ?? $row;
        foreach (['visible', 'platform', 'version', 'version_code', 'apk_file', 'installer_file', 'filename', 'date'] as $field) $copy[$field] = $row[$field] ?? '';
        $translated[] = $copy;
      }
      $config->set($other . '.releases_items', $translated);
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /** Keep one canonical catalog while presenting separate platform editors. */
  private function normalizeReleaseGroups(mixed $value, bool $persistFiles = TRUE): array {
    if (!is_array($value)) return [];
    $definition = self::FIELDS['Releases']['releases_items'];
    if (isset($value['rows'])) return $this->normalizeConfigRowsValue($value, $definition, $persistFiles);
    $rows = [];
    foreach (array_keys(\Drupal\nelkano_home\Service\ReleaseArtifact::PLATFORMS) as $platform) {
      foreach ($this->normalizeConfigRowsValue($value[$platform] ?? [], $definition, $persistFiles) as $row) { $row['platform'] = $platform; $rows[] = $row; }
    }
    return $rows;
  }

  private function sectionFilter(string $pageKey): ?string {
    return match ($pageKey) {
      'privacy_cookies' => 'privacy_cookies',
      'legal_notice' => 'legal_notice',
      'releases' => 'releases',
      'security' => 'security',
      default => NULL,
    };
  }

  private function pageMeta(string $pageKey): array {
    return match ($pageKey) {
      'privacy_cookies' => [
        'title' => 'Privacidad y cookies',
        'description' => 'Gestiona la politica de privacidad, cookies y tratamiento de datos.',
        'public_path' => '/privacidad-cookies',
      ],
      'legal_notice' => [
        'title' => 'Aviso legal',
        'description' => 'Gestiona el contenido publicado y exportable por configuracion.',
        'public_path' => '/aviso-legal',
      ],
      'releases' => [
        'title' => 'Versiones',
        'description' => 'Gestiona las descargas publicadas, requisitos y notas de cada versión.',
        'public_path' => '/versiones',
      ],
      'security' => [
        'title' => 'Seguridad y privacidad',
        'description' => 'Gestiona la información sobre datos locales, cuenta y permisos de los módulos.',
        'public_path' => '/seguridad-privacidad',
      ],
      default => [
        'title' => 'Documentacion',
        'description' => 'Gestiona las paginas documentales de Nelkano.',
        'public_path' => '/',
      ],
    };
  }

  private function legacyReleaseRows(array $content): array {
    $version = trim((string) ($content['releases_version'] ?? ''));
    $filename = trim((string) ($content['releases_filename'] ?? ''));
    $date = trim((string) ($content['releases_date'] ?? ''));
    $requirements = trim((string) ($content['releases_requirements'] ?? ''));
    $changes = $content['releases_changes'] ?? '';
    $notice = trim((string) ($content['releases_notice'] ?? ''));

    if ($version === '' && $filename === '' && $date === '' && $requirements === '' && $notice === '') {
      return [];
    }

    if (is_array($changes)) {
      $changes = implode("\n", array_map(static fn($item): string => is_array($item) ? (string) ($item['text'] ?? '') : (string) $item, $changes));
    }

    return [[
      'visible' => '1',
      'version' => $version,
      'apk_file' => '',
      'filename' => $filename,
      'date' => $date,
      'requirements' => $requirements,
      'changes' => trim((string) $changes),
      'notice' => $notice,
    ]];
  }

  private function sectionUiTitle(string $sectionLabel): string {
    return match ($sectionLabel) {
      'Privacy cookies' => 'Privacidad y cookies',
      'Legal notice' => 'Aviso legal',
      'Releases' => 'Versiones',
      'Security' => 'Seguridad y privacidad',
      default => $sectionLabel,
    };
  }

}
