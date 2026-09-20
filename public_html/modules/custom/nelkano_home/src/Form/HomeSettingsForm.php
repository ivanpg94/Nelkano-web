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
      'hero_enabled' => ['type'=>'checkbox','title'=>'Show section','default'=>TRUE],
      'hero_badge' => ['type'=>'textfield','title'=>'Badge'],
      'hero_title' => ['type'=>'textfield','title'=>'Title'],
      'hero_description' => ['type'=>'textarea','title'=>'Description'],
      'primary_cta' => ['type'=>'textfield','title'=>'Primary CTA label'],
      'primary_url' => ['type'=>'textfield','title'=>'Primary CTA URL'],
      'secondary_cta' => ['type'=>'textfield','title'=>'Texto de descarga (versión y APK desde Versiones)'],
    ],
    'About' => [
      'about_enabled' => ['type'=>'checkbox','title'=>'Show section','default'=>TRUE],
      'about_eyebrow' => ['type'=>'textfield','title'=>'Eyebrow'],
      'about_title' => ['type'=>'textfield','title'=>'Title'],
      'about_description' => ['type'=>'textarea','title'=>'Description'],
      'feature_items' => ['type'=>'config_rows','layout'=>'cards','title'=>'Funciones destacadas','columns'=>[
        'visible'=>['type'=>'checkbox','title'=>'Visible'],
        'title'=>['title'=>'Title'],
        'description'=>['type'=>'textarea','title'=>'Description'],
        'icon'=>['type'=>'select','title'=>'Icono de Figma','options'=>['IcoCloud'=>'Google Drive','IcoGamepad'=>'Mando','IcoPencilTouch'=>'Editor de controles','IcoCollections'=>'Colecciones','IcoZip'=>'Archivo comprimido']],
        'image_uri'=>['type'=>'managed_file','title'=>'Imagen personalizada','upload_location'=>'public://nelkano-images','upload_validators'=>['FileExtension'=>['extensions'=>'png jpg jpeg webp gif'],'FileIsImage'=>[]]],
      ]],
    ],
    'Status' => [
      'status_enabled'=>['type'=>'checkbox','title'=>'Show section','default'=>TRUE],
      'status_eyebrow'=>['type'=>'textfield','title'=>'Eyebrow'],
      'status_title'=>['type'=>'textfield','title'=>'Title'],
    ],
    'Experiencia' => [
      'differentiators_enabled'=>['type'=>'checkbox','title'=>'Show section','default'=>TRUE],
      'differentiators_eyebrow'=>['type'=>'textfield','title'=>'Eyebrow'],
      'differentiators_title'=>['type'=>'textfield','title'=>'Title'],
      'differentiator_items'=>['type'=>'config_rows','layout'=>'cards','title'=>'Experiencia','legacy_keys'=>['title','description'],'columns'=>[
        'visible'=>['type'=>'checkbox','title'=>'Visible'],
        'title'=>['title'=>'Title'],
        'description'=>['type'=>'textarea','title'=>'Description'],
      ]],
    ],
    'Footer' => [
      'footer_primary'=>['type'=>'textarea','title'=>'Primary text'],
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

        $form[$langcode][$section_key]['save_section'] = ['#type'=>'submit','#value'=>$active_language==='es'?'Guardar sección':'Save section','#name'=>'save_'.$section_key,'#r_section'=>$section_key,'#attributes'=>['class'=>['r-save-section']]];
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
        $only_section=$form_state->getTriggeringElement()['#r_section']??NULL;
        if ($only_section!==NULL && $only_section!==$section_key) {continue;}
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
