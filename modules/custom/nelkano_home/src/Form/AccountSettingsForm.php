<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

final class AccountSettingsForm extends FormBase {

  public function getFormId(): string {
    return 'nelkano_account_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $account = NULL, string $language = 'es'): array {
    $uid = (int) ($account?->id() ?? $this->currentUser()->id());
    $form['#attributes']['class'][] = 'nk-account-form';
    $form['uid'] = ['#type' => 'hidden', '#value' => $uid];

    $form['mail'] = [
      '#type' => 'email',
      '#title' => $language === 'en' ? 'Email address' : 'Correo electronico',
      '#required' => TRUE,
      '#maxlength' => 254,
      '#default_value' => $account?->getEmail(),
      '#attributes' => ['placeholder' => 'tu@email.com'],
    ];
    $form['current_password'] = [
      '#type' => 'password',
      '#title' => $language === 'en' ? 'Current password' : 'Contrasena actual',
      '#description' => $language === 'en' ? 'Required if you change email or password.' : 'Necesaria si cambias el correo o la contrasena.',
      '#attributes' => ['autocomplete' => 'current-password'],
    ];
    $form['new_password'] = [
      '#type' => 'password',
      '#title' => $language === 'en' ? 'New password' : 'Nueva contrasena',
      '#attributes' => ['autocomplete' => 'new-password'],
    ];
    $form['new_password_confirm'] = [
      '#type' => 'password',
      '#title' => $language === 'en' ? 'Confirm new password' : 'Confirmar nueva contrasena',
      '#attributes' => ['autocomplete' => 'new-password'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $language === 'en' ? 'Save account' : 'Guardar cuenta',
      '#attributes' => ['class' => ['nk-account-submit']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $uid = (int) $form_state->getValue('uid');
    if ($uid !== (int) $this->currentUser()->id()) {
      $form_state->setErrorByName('mail', $language === 'en' ? 'You can only edit your own account.' : 'Solo puedes editar tu propia cuenta.');
      return;
    }

    $account = User::load($uid);
    if (!$account instanceof UserInterface) {
      $form_state->setErrorByName('mail', $language === 'en' ? 'The account could not be loaded.' : 'No se ha podido cargar la cuenta.');
      return;
    }

    $mail = trim((string) $form_state->getValue('mail'));
    $new_password = (string) $form_state->getValue('new_password');
    $new_password_confirm = (string) $form_state->getValue('new_password_confirm');
    $mail_changed = mb_strtolower($mail) !== mb_strtolower((string) $account->getEmail());

    if ($mail_changed) {
      $storage = $this->entityTypeManager()->getStorage('user');
      $existing_mail = $storage->loadByProperties(['mail' => $mail]);
      $existing_name = $storage->loadByProperties(['name' => $mail]);
      if ($this->hasOtherAccount($existing_mail, $uid) || $this->hasOtherAccount($existing_name, $uid)) {
        $form_state->setErrorByName('mail', $language === 'en' ? 'That email is already in use.' : 'Ese correo ya esta en uso.');
      }
    }

    if ($new_password !== '' && mb_strlen($new_password) < 8) {
      $form_state->setErrorByName('new_password', $language === 'en' ? 'Use at least 8 characters.' : 'Usa al menos 8 caracteres.');
    }
    if ($new_password !== $new_password_confirm) {
      $form_state->setErrorByName('new_password_confirm', $language === 'en' ? 'Passwords do not match.' : 'Las contrasenas no coinciden.');
    }

    if ($mail_changed || $new_password !== '') {
      $current_password = (string) $form_state->getValue('current_password');
      $authenticated_uid = \Drupal::service('user.auth')->authenticate($account->getAccountName(), $current_password);
      if ((int) $authenticated_uid !== $uid) {
        $form_state->setErrorByName('current_password', $language === 'en' ? 'The current password is not correct.' : 'La contrasena actual no es correcta.');
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $uid = (int) $form_state->getValue('uid');
    $account = User::load($uid);
    if (!$account instanceof UserInterface) {
      return;
    }

    $mail = trim((string) $form_state->getValue('mail'));
    $account->setEmail($mail);
    $account->setUsername($mail);
    $new_password = (string) $form_state->getValue('new_password');
    if ($new_password !== '') {
      $account->setPassword($new_password);
    }
    $account->save();

    $this->messenger()->addStatus($language === 'en' ? 'Account updated.' : 'Cuenta actualizada.');
    $form_state->setRedirectUrl(Url::fromUserInput($language === 'en' ? '/en/user' : '/user'));
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

  private function hasOtherAccount(array $accounts, int $uid): bool {
    foreach ($accounts as $account) {
      if ((int) $account->id() !== $uid) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
