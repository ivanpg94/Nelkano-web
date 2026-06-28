<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class HomeSettingsForm extends ConfigFormBase {

  use AdminFormUiTrait;
  use ConfigRowsFormTrait;

  private const LANGUAGES = [
    'es' => 'Espanol',
    'en' => 'English',
  ];

  private const FIELDS = [
    'Hero' => [
      'hero_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'seo_title' => ['type' => 'textfield', 'title' => 'SEO title'],
      'seo_description' => ['type' => 'textarea', 'title' => 'SEO description'],
      'seo_keywords' => ['type' => 'textfield', 'title' => 'SEO keywords'],
      'hero_badge' => ['type' => 'textfield', 'title' => 'Badge'],
      'hero_title' => ['type' => 'textfield', 'title' => 'Title'],
      'hero_description' => ['type' => 'textarea', 'title' => 'Description'],
      'primary_cta' => ['type' => 'textfield', 'title' => 'Primary CTA label'],
      'primary_url' => ['type' => 'textfield', 'title' => 'Primary CTA URL'],
      'secondary_cta' => ['type' => 'textfield', 'title' => 'Secondary CTA label'],
      'secondary_url' => ['type' => 'textfield', 'title' => 'Secondary CTA URL'],
    ],
    'Downloads' => [
      'downloads_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'downloads_title' => ['type' => 'textfield', 'title' => 'Section title'],
      'android_title' => ['type' => 'textfield', 'title' => 'Android title'],
      'android_description' => ['type' => 'textarea', 'title' => 'Android description'],
    ],
    'About' => [
      'about_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'about_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'about_title' => ['type' => 'textfield', 'title' => 'Title'],
      'about_description' => ['type' => 'textarea', 'title' => 'Description'],
    ],
    'Status' => [
      'status_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'status_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'status_title' => ['type' => 'textfield', 'title' => 'Title'],
      'status_description' => ['type' => 'textarea', 'title' => 'Description'],
      'status_items' => [
        'type' => 'config_rows',
        'title' => 'Status cards',
        'description' => 'Add one row per system.',
        'columns' => [
          'system' => ['title' => 'System'],
          'status' => ['title' => 'Status'],
          'description' => ['title' => 'Description', 'type' => 'textarea'],
        ],
      ],
    ],
    'Platforms' => [
      'platforms_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'platforms_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'platforms_title' => ['type' => 'textfield', 'title' => 'Title'],
      'platforms_description' => ['type' => 'textarea', 'title' => 'Description'],
      'platform_items' => [
        'type' => 'config_rows',
        'title' => 'Platform cards',
        'description' => 'Add one row per card.',
        'columns' => [
          'title' => ['title' => 'Title'],
          'description' => ['title' => 'Description', 'type' => 'textarea'],
        ],
      ],
    ],
    'Differentiators' => [
      'differentiators_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'differentiators_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'differentiators_title' => ['type' => 'textfield', 'title' => 'Title'],
      'differentiators_description' => ['type' => 'textarea', 'title' => 'Description'],
      'differentiator_items' => [
        'type' => 'config_rows',
        'title' => 'Differentiator cards',
        'description' => 'Add one row per card.',
        'columns' => [
          'title' => ['title' => 'Title'],
          'description' => ['title' => 'Description', 'type' => 'textarea'],
        ],
      ],
    ],
    'Vision' => [
      'vision_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'vision_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'vision_title' => ['type' => 'textfield', 'title' => 'Title'],
      'vision_description' => ['type' => 'textarea', 'title' => 'Description'],
      'vision_items' => [
        'type' => 'config_rows',
        'title' => 'Vision bullets',
        'description' => 'Add one row per bullet.',
        'columns' => [
          'text' => ['title' => 'Text', 'type' => 'textarea'],
        ],
        'legacy_keys' => ['text'],
      ],
    ],
    'FAQ' => [
      'faq_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'faq_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'faq_title' => ['type' => 'textfield', 'title' => 'Title'],
      'faq_items' => [
        'type' => 'config_rows',
        'title' => 'FAQ items',
        'description' => 'Add one row per question.',
        'columns' => [
          'question' => ['title' => 'Question'],
          'answer' => ['title' => 'Answer', 'type' => 'textarea'],
        ],
      ],
    ],
    'Trust' => [
      'trust_enabled' => ['type' => 'checkbox', 'title' => 'Show section', 'default' => TRUE],
      'trust_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'trust_title' => ['type' => 'textfield', 'title' => 'Title'],
      'trust_description' => ['type' => 'textarea', 'title' => 'Description'],
      'trust_items' => [
        'type' => 'config_rows',
        'title' => 'Trust links',
        'description' => 'Add one row per link.',
        'columns' => [
          'title' => ['title' => 'Title'],
          'description' => ['title' => 'Description', 'type' => 'textarea'],
          'url' => ['title' => 'URL'],
        ],
      ],
    ],
    'Footer' => [
      'footer_primary' => ['type' => 'textarea', 'title' => 'Primary text'],
      'footer_secondary' => ['type' => 'textarea', 'title' => 'Secondary text'],
    ],
  ];

  public function getFormId(): string {
    return 'nelkano_home_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['nelkano_home.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('nelkano_home.settings');
    $active_language = $this->activeAdminLanguage();
    $this->applyNelkanoAdminChrome(
      $form,
      'home',
      'Home',
      'Gestiona el contenido principal, descargas y secciones publicas de Nelkano.',
      '/',
      $active_language,
    );

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
        $form[$langcode][$section_key] = [
          '#type' => 'details',
          '#title' => $this->adminLabel($section_label, $active_language),
          '#open' => FALSE,
          '#attributes' => ['class' => ['nk-admin-section']],
        ];

        foreach ($fields as $key => $definition) {
          if ($definition['type'] === 'config_rows') {
            $form[$langcode][$section_key][$key] = $this->buildConfigRowsElement(
              $config->get("$langcode.$key") ?? '',
              $definition,
              [$langcode, $section_key, $key],
              $active_language,
              $form_state,
            );
            $form[$langcode][$section_key][$key]['#title'] = $this->adminLabel($definition['title'], $active_language);
          }
          else {
            $default_value = $config->get("$langcode.$key");
            if ($default_value === NULL && array_key_exists('default', $definition)) {
              $default_value = $definition['default'];
            }
            $form[$langcode][$section_key][$key] = [
              '#type' => $definition['type'],
              '#title' => $this->adminLabel($definition['title'], $active_language),
              '#default_value' => $default_value ?? '',
              '#description' => $this->adminDescription($definition['description'] ?? NULL, $active_language),
            ];
            if (str_ends_with($key, '_enabled')) {
              $form[$langcode][$section_key][$key]['#wrapper_attributes']['class'][] = 'nk-section-toggle';
            }
          }
        }
      }
    }

    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $active_language === 'es' ? 'Guardar cambios' : 'Save changes';
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('nelkano_home.settings');
    $active_language = (string) ($form_state->getValue('active_language') ?? 'es');

    foreach (array_keys(self::LANGUAGES) as $langcode) {
      if ($langcode !== $active_language) {
        continue;
      }
      $language_values = $config->get($langcode) ?? [];
      foreach (self::FIELDS as $section_label => $fields) {
        $section_key = strtolower(str_replace(' ', '_', $section_label));
        foreach (array_keys($fields) as $key) {
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

}
