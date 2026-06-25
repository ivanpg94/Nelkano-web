<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class HomeSettingsForm extends ConfigFormBase {

  private const LANGUAGES = [
    'es' => 'Spanish',
    'en' => 'English',
  ];

  private const FIELDS = [
    'Navigation' => [
      'nav_language' => ['type' => 'textfield', 'title' => 'Language switch label'],
    ],
    'Hero' => [
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
      'downloads_title' => ['type' => 'textfield', 'title' => 'Section title'],
      'android_title' => ['type' => 'textfield', 'title' => 'Android title'],
      'android_channel' => ['type' => 'textfield', 'title' => 'Android channel'],
      'android_description' => ['type' => 'textarea', 'title' => 'Android description'],
      'android_url' => [
        'type' => 'textfield',
        'title' => 'Android URL',
        'description' => 'If empty or #, the newest .apk in modules/custom/nelkano_home/emulator is used.',
      ],
      'windows_title' => ['type' => 'textfield', 'title' => 'Windows title'],
      'windows_channel' => ['type' => 'textfield', 'title' => 'Windows channel'],
      'windows_description' => ['type' => 'textarea', 'title' => 'Windows description'],
      'windows_url' => [
        'type' => 'textfield',
        'title' => 'Windows URL',
        'description' => 'If empty or #, the newest .exe in modules/custom/nelkano_home/emulator is used.',
      ],
    ],
    'About' => [
      'about_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'about_title' => ['type' => 'textfield', 'title' => 'Title'],
      'about_description' => ['type' => 'textarea', 'title' => 'Description'],
    ],
    'Status' => [
      'status_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'status_title' => ['type' => 'textfield', 'title' => 'Title'],
      'status_description' => ['type' => 'textarea', 'title' => 'Description'],
      'status_items' => [
        'type' => 'textarea',
        'title' => 'Status cards',
        'description' => 'One per line: System|Status|Description',
      ],
    ],
    'Platforms' => [
      'platforms_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'platforms_title' => ['type' => 'textfield', 'title' => 'Title'],
      'platforms_description' => ['type' => 'textarea', 'title' => 'Description'],
      'platform_items' => [
        'type' => 'textarea',
        'title' => 'Platform cards',
        'description' => 'One per line: Title|Description',
      ],
    ],
    'Differentiators' => [
      'differentiators_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'differentiators_title' => ['type' => 'textfield', 'title' => 'Title'],
      'differentiators_description' => ['type' => 'textarea', 'title' => 'Description'],
      'differentiator_items' => [
        'type' => 'textarea',
        'title' => 'Differentiator cards',
        'description' => 'One per line: Title|Description',
      ],
    ],
    'Vision' => [
      'vision_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'vision_title' => ['type' => 'textfield', 'title' => 'Title'],
      'vision_description' => ['type' => 'textarea', 'title' => 'Description'],
      'vision_items' => [
        'type' => 'textarea',
        'title' => 'Vision bullets',
        'description' => 'One item per line.',
      ],
    ],
    'FAQ' => [
      'faq_eyebrow' => ['type' => 'textfield', 'title' => 'Eyebrow'],
      'faq_title' => ['type' => 'textfield', 'title' => 'Title'],
      'faq_items' => [
        'type' => 'textarea',
        'title' => 'FAQ items',
        'description' => 'One per line: Question|Answer',
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

    $form['intro'] = [
      '#markup' => '<p>Edit the public landing page. Spanish is shown at <code>/</code>; English is shown at <code>/en</code>.</p>',
    ];

    foreach (self::LANGUAGES as $langcode => $language_label) {
      $form[$langcode] = [
        '#type' => 'details',
        '#title' => $language_label,
        '#open' => $langcode === 'es',
        '#tree' => TRUE,
      ];

      foreach (self::FIELDS as $section_label => $fields) {
        $section_key = strtolower(str_replace(' ', '_', $section_label));
        $form[$langcode][$section_key] = [
          '#type' => 'details',
          '#title' => $section_label,
          '#open' => in_array($section_label, ['Hero', 'Downloads'], TRUE),
        ];

        foreach ($fields as $key => $definition) {
          $form[$langcode][$section_key][$key] = [
            '#type' => $definition['type'],
            '#title' => $definition['title'],
            '#default_value' => $config->get("$langcode.$key") ?? '',
            '#description' => $definition['description'] ?? NULL,
          ];
        }
      }
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('nelkano_home.settings');

    foreach (array_keys(self::LANGUAGES) as $langcode) {
      $language_values = [];
      foreach (self::FIELDS as $section_label => $fields) {
        $section_key = strtolower(str_replace(' ', '_', $section_label));
        foreach (array_keys($fields) as $key) {
          $language_values[$key] = $form_state->getValue([$langcode, $section_key, $key]);
        }
      }
      $config->set($langcode, $language_values);
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
