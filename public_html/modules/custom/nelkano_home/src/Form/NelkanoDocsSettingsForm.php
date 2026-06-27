<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class NelkanoDocsSettingsForm extends ConfigFormBase {

  use AdminFormUiTrait;

  private const LANGUAGES = [
    'es' => 'Espanol',
    'en' => 'English',
  ];

  private const FIELDS = [
    'Privacy cookies' => [
      'privacy_cookies_seo_title' => ['type' => 'textfield', 'title' => 'Titulo SEO'],
      'privacy_cookies_seo_description' => ['type' => 'textarea', 'title' => 'Descripcion SEO'],
      'privacy_cookies_eyebrow' => ['type' => 'textfield', 'title' => 'Etiqueta'],
      'privacy_cookies_title' => ['type' => 'textfield', 'title' => 'Titulo visible'],
      'privacy_cookies_intro' => ['type' => 'textarea', 'title' => 'Introduccion'],
      'privacy_cookies_sections' => [
        'type' => 'textarea',
        'title' => 'Contenido estructurado',
        'description' => 'Una linea por seccion: Titulo|Parrafo 1||Parrafo 2',
      ],
    ],
    'Legal notice' => [
      'legal_notice_seo_title' => ['type' => 'textfield', 'title' => 'Titulo SEO'],
      'legal_notice_seo_description' => ['type' => 'textarea', 'title' => 'Descripcion SEO'],
      'legal_notice_eyebrow' => ['type' => 'textfield', 'title' => 'Etiqueta'],
      'legal_notice_title' => ['type' => 'textfield', 'title' => 'Titulo visible'],
      'legal_notice_intro' => ['type' => 'textarea', 'title' => 'Introduccion'],
      'legal_notice_sections' => [
        'type' => 'textarea',
        'title' => 'Contenido estructurado',
        'description' => 'Una linea por seccion: Titulo|Parrafo 1||Parrafo 2',
      ],
    ],
    'Releases' => [
      'releases_seo_title' => ['type' => 'textfield', 'title' => 'SEO title'],
      'releases_seo_description' => ['type' => 'textarea', 'title' => 'SEO description'],
      'releases_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'releases_title' => ['type' => 'textfield', 'title' => 'Title'],
      'releases_intro' => ['type' => 'textarea', 'title' => 'Intro'],
      'releases_version' => ['type' => 'textfield', 'title' => 'Public version'],
      'releases_filename' => ['type' => 'textfield', 'title' => 'APK filename'],
      'releases_date' => ['type' => 'textfield', 'title' => 'Publication date'],
      'releases_requirements' => ['type' => 'textarea', 'title' => 'Requirements'],
      'releases_changes' => [
        'type' => 'textarea',
        'title' => 'Changelog items',
        'description' => 'One item per line.',
      ],
      'releases_notice' => ['type' => 'textarea', 'title' => 'Download notice'],
    ],
    'Compatibility' => [
      'compatibility_seo_title' => ['type' => 'textfield', 'title' => 'SEO title'],
      'compatibility_seo_description' => ['type' => 'textarea', 'title' => 'SEO description'],
      'compatibility_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'compatibility_title' => ['type' => 'textfield', 'title' => 'Title'],
      'compatibility_intro' => ['type' => 'textarea', 'title' => 'Intro'],
      'compatibility_items' => [
        'type' => 'textarea',
        'title' => 'Compatibility rows',
        'description' => 'One per line: System|Status|Works|Limitations|Last review',
      ],
    ],
    'Security' => [
      'security_seo_title' => ['type' => 'textfield', 'title' => 'SEO title'],
      'security_seo_description' => ['type' => 'textarea', 'title' => 'SEO description'],
      'security_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'security_title' => ['type' => 'textfield', 'title' => 'Title'],
      'security_intro' => ['type' => 'textarea', 'title' => 'Intro'],
      'security_sections' => [
        'type' => 'textarea',
        'title' => 'Security sections',
        'description' => 'One per line: Title|Description',
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
          '#open' => TRUE,
        ];

        foreach ($fields as $key => $definition) {
          $form[$langcode][$section_key][$key] = [
            '#type' => $definition['type'],
            '#title' => $this->adminLabel($definition['title'], $active_language),
            '#default_value' => $config->get("$langcode.$key") ?? '',
            '#description' => $this->adminDescription($definition['description'] ?? NULL, $active_language),
          ];
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
        if ($section_filter !== NULL && $section_key !== $section_filter) {
          continue;
        }
        foreach (array_keys($fields) as $key) {
          $language_values[$key] = $form_state->getValue([$langcode, $section_key, $key]);
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
