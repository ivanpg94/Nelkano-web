<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\nelkano_home\Service\CommunityRules;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ProfileSettingsForm extends FormBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('database'));
  }

  public function getFormId(): string {
    return 'nelkano_profile_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $account = NULL, string $language = 'es'): array {
    $uid = (int) ($account?->id() ?? $this->currentUser()->id());
    $row = $this->database->select('nelkano_profile', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: [];

    $form['#attributes']['class'][] = 'nk-account-form';
    $form['uid'] = ['#type' => 'hidden', '#value' => $uid];
    $form['display_name'] = [
      '#type' => 'textfield',
      '#title' => $language === 'en' ? 'Visible username' : 'Usuario visible',
      '#required' => TRUE,
      '#maxlength' => 40,
      '#default_value' => $row['display_name'] ?? $account?->getDisplayName(),
      '#attributes' => ['placeholder' => $language === 'en' ? 'Your Nelkano name' : 'Tu nombre en Nelkano'],
    ];
    $form['status_text'] = [
      '#type' => 'textfield',
      '#title' => $language === 'en' ? 'Status' : 'Estado',
      '#maxlength' => 100,
      '#default_value' => $row['status_text'] ?? '',
      '#attributes' => ['placeholder' => $language === 'en' ? 'Playing, testing compatibility, available...' : 'Jugando, probando compatibilidad, disponible...'],
    ];
    $form['avatar_color'] = [
      '#type' => 'select',
      '#title' => $language === 'en' ? 'Avatar color' : 'Color del avatar',
      '#default_value' => $row['avatar_color'] ?? '#a414ff',
      '#options' => [
        '#a414ff' => 'Nelkano violeta',
        '#38bdf8' => 'Azul energia',
        '#22c55e' => 'Verde online',
        '#f59e0b' => 'Ambar',
      ],
    ];
    $form['avatar_upload'] = [
      '#type' => 'file',
      '#title' => $language === 'en' ? 'Profile image' : 'Imagen de perfil',
      '#description' => $language === 'en' ? 'Optional. Use a square PNG, JPG or WebP image.' : 'Opcional. Usa una imagen cuadrada en PNG, JPG o WebP.',
      '#attributes' => [
        'accept' => 'image/png,image/jpeg,image/webp',
        'aria-label' => $language === 'en' ? 'Select profile image' : 'Seleccionar imagen de perfil',
      ],
    ];
    if ($uid === (int) $this->currentUser()->id() && $this->currentUser()->hasPermission('use nelkano app features')) {
      $account_rules = CommunityRules::state($uid);
      $form['community_rules'] = [
        '#type' => 'checkbox',
        '#title' => $language === 'en' ? 'I accept the Community rules' : 'Acepto las normas de Comunidad',
        '#default_value' => $account_rules['accepted'],
        '#description' => $language === 'en'
          ? '<a href="/en/community/rules" target="_blank" rel="noopener">Read the rules</a>. Acceptance enables posts, comments and messages.'
          : '<a href="/comunidad/normas" target="_blank" rel="noopener">Leer las normas</a>. Al aceptarlas puedes publicar, comentar y enviar mensajes.',
      ];
      $form['community_rules_version'] = ['#type' => 'hidden', '#default_value' => $account_rules['version']];
      $form['community_rules_original'] = ['#type' => 'value', '#value' => $account_rules['accepted']];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $language === 'en' ? 'Save profile' : 'Guardar perfil',
      '#attributes' => ['class' => ['nk-account-submit']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $uid = (int) $form_state->getValue('uid');
    if ($uid !== (int) $this->currentUser()->id()) {
      $form_state->setErrorByName('display_name', $language === 'en' ? 'You can only edit your own profile.' : 'Solo puedes editar tu propio perfil.');
    }
    if (mb_strlen(trim((string) $form_state->getValue('display_name'))) < 2) {
      $form_state->setErrorByName('display_name', $language === 'en' ? 'Use at least 2 characters.' : 'Usa al menos 2 caracteres.');
    }
    if (isset($form['community_rules']) && $form_state->getValue('community_rules_version') !== CommunityRules::VERSION) {
      $form_state->setErrorByName('community_rules', $language === 'en'
        ? 'The Community rules changed. Reload your profile to review them.'
        : 'Las normas de Comunidad han cambiado. Recarga el perfil para revisarlas.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $uid = (int) $form_state->getValue('uid');
    $now = \Drupal::time()->getRequestTime();
    $display_name = trim((string) $form_state->getValue('display_name'));
    $status_text = trim((string) $form_state->getValue('status_text'));
    $avatar_color = (string) $form_state->getValue('avatar_color');
    $accept_rules = (bool) $form_state->getValue('community_rules');
    if (isset($form['community_rules']) && $accept_rules !== $form_state->getValue('community_rules_original')
        && !CommunityRules::setPreference($uid, $accept_rules, (string) $form_state->getValue('community_rules_version'))) {
      $this->messenger()->addError($language === 'en' ? 'Could not save your Community preference.' : 'No se pudo guardar tu preferencia de Comunidad.');
      $form_state->setRebuild();
      return;
    }
    $avatar_file_uri = (string) ($this->currentProfileRow($uid)['avatar_file_uri'] ?? '');
    $file = $this->uploadAvatar();
    if ($file instanceof File) {
      $file->setPermanent();
      $file->save();
      $avatar_file_uri = $file->getFileUri();
    }

    $this->database->merge('nelkano_profile')
      ->key('uid', $uid)
      ->fields([
        'display_name' => $display_name,
        'status_text' => $status_text,
        'avatar_color' => $avatar_color,
        'avatar_file_uri' => $avatar_file_uri,
        'updated' => $now,
      ])
      ->insertFields([
        'uid' => $uid,
        'display_name' => $display_name,
        'status_text' => $status_text,
        'avatar_color' => $avatar_color,
        'avatar_file_uri' => $avatar_file_uri,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    $this->messenger()->addStatus($language === 'en' ? 'Profile updated.' : 'Perfil actualizado.');
    $form_state->setRedirectUrl(Url::fromUserInput($language === 'en' ? '/en/user' : '/user'));
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

  private function currentProfileRow(int $uid): array {
    return $this->database->select('nelkano_profile', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: [];
  }

  private function uploadAvatar(): ?File {
    $directory = 'public://nelkano-avatars';
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $validators = [
      'FileExtension' => ['extensions' => 'png jpg jpeg webp'],
      'FileSizeLimit' => ['fileLimit' => 2 * 1024 * 1024],
    ];
    $file = file_save_upload('avatar_upload', $validators, $directory, FileSystemInterface::EXISTS_RENAME);
    return $file instanceof File ? $file : NULL;
  }

}
