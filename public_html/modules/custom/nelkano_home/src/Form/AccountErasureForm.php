<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nelkano_home\Service\AccountErasure;
use Drupal\nelkano_home\Service\AppLoginGuard;
use Drupal\user\Entity\User;

/** Uses Drupal's session-bound Form API CSRF token and fresh password check. */
final class AccountErasureForm extends FormBase {
  public function getFormId(): string { return 'nelkano_account_erasure_form'; }
  private function english(): bool { return str_starts_with(\Drupal::request()->getPathInfo(), '/en/'); }
  public function buildForm(array $form, FormStateInterface $state, string $language = 'es'): array {
    $en = $this->english(); $account = $this->currentUser()->isAuthenticated() ? User::load((int) $this->currentUser()->id()) : NULL;
    $form['#attributes']['class'][] = 'nk-auth-form';
    $form['#token'] = $this->getFormId();
    $form['#cache']['max-age'] = 0;
    if ($account && !AccountErasure::selfServiceAllowed($account)) {
      $form['notice'] = ['#markup' => '<p>' . ($en ? 'Administrative accounts need a manual review. Contact contacto@nelkano.com.' : 'Las cuentas administradoras requieren revisión manual. Contacta con contacto@nelkano.com.') . '</p>'];
      return $form;
    }
    $form['explanation'] = ['#markup' => '<p>' . ($en ? 'This permanently removes your account, profile, friends, synced activity, sessions, error reports and their attachments from the active Nelkano server. Your local games and saves, exported copies and your separate Google Drive files stay on their devices/services. Backup copies expire according to their retention cycle; suppression requests must be reapplied after a restoration.' : 'Se eliminarán tu cuenta, perfil, amistades, actividad sincronizada, sesiones, reportes y sus adjuntos del servidor activo de Nelkano. Tus juegos y partidas locales, las copias exportadas y los archivos de tu Google Drive independiente se conservan en sus dispositivos o servicios. Las copias de seguridad caducan según su ciclo y las supresiones deben volver a aplicarse después de una restauración.') . '</p>'];
    if (!$account) { $form['email'] = ['#type' => 'email', '#title' => $en ? 'Account email' : 'Correo de la cuenta', '#required' => TRUE, '#attributes' => ['autocomplete' => 'username']]; }
    $form['password'] = ['#type' => 'password', '#title' => $en ? 'Current password' : 'Contraseña actual', '#required' => TRUE, '#attributes' => ['autocomplete' => 'current-password']];
    $form['confirmation'] = ['#type' => 'checkbox', '#title' => $en ? 'I understand and want to permanently delete my account and its server data.' : 'Entiendo y quiero eliminar definitivamente mi cuenta y sus datos del servidor.', '#required' => TRUE];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $en ? 'Delete my account' : 'Eliminar mi cuenta', '#attributes' => ['class' => ['nk-auth-submit']]];
    return $form;
  }
  private function authenticate(FormStateInterface $state): ?\Drupal\user\UserInterface {
    $current = $this->currentUser()->isAuthenticated() ? User::load((int) $this->currentUser()->id()) : NULL;
    $login = $current ? $current->getAccountName() : trim((string) $state->getValue('email'));
    $auth = AppLoginGuard::authenticate(\Drupal::request(), $login, (string) $state->getValue('password'));
    $account = $auth['account'] ?? NULL;
    return $auth['status'] === AppLoginGuard::OK && $account && (!$current || (int) $account->id() === (int) $current->id()) ? $account : NULL;
  }
  public function validateForm(array &$form, FormStateInterface $state): void {
    if (!$state->getValue('confirmation')) {
      $state->setErrorByName('confirmation', $this->english() ? 'Confirm account deletion.' : 'Confirma la eliminación de la cuenta.');
    }
    $account = $this->authenticate($state);
    if (!$account) { $state->setErrorByName('password', $this->english() ? 'Incorrect account/password or too many attempts.' : 'Cuenta o contraseña incorrecta, o demasiados intentos.'); return; }
    if (!AccountErasure::selfServiceAllowed($account)) { $state->setErrorByName('password', $this->english() ? 'Administrative accounts require manual review: contacto@nelkano.com.' : 'Las cuentas administradoras requieren revisión manual: contacto@nelkano.com.'); }
  }
  public function submitForm(array &$form, FormStateInterface $state): void {
    $account = $this->authenticate($state);
    if (!$account || !$state->getValue('confirmation')) { return; }
    $was_current = (int) $this->currentUser()->id() === (int) $account->id();
    try { AccountErasure::erase($account); }
    catch (\Throwable $error) { $this->messenger()->addError($this->english() ? 'Deletion could not finish. Contact contacto@nelkano.com.' : 'No se pudo completar el borrado. Contacta con contacto@nelkano.com.'); return; }
    if ($was_current) { user_logout(); }
    $this->messenger()->addStatus($this->english() ? 'Your account and active server data have been deleted.' : 'Tu cuenta y los datos del servidor activo se han eliminado.');
    $state->setRedirect('<front>');
  }
}
