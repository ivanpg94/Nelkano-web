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
          'version' => ['title' => 'Public version'],
          'apk_file' => [
            'title' => 'APK',
            'type' => 'managed_file',
            'upload_location' => 'public://nelkano-releases',
            'upload_validators' => ['FileExtension' => ['extensions' => 'apk']],
            'description' => 'Sube el APK publico de esta version.',
          ],
          'filename' => ['title' => 'APK filename fallback'],
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
            ? $this->normalizeConfigRowsValue($value, $fields[$key])
            : $value;
        }
      }
      $config->set($langcode, $language_values);
    }

    $config->save();
    parent::submitForm($form, $form_state);
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
        'description' => 'Gestiona la beta publica, el APK, requisitos y changelog.',
        'public_path' => '/versiones',
      ],
      'security' => [
        'title' => 'Seguridad y privacidad',
        'description' => 'Gestiona la informacion sobre datos, cuenta, permisos y Google Drive.',
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
