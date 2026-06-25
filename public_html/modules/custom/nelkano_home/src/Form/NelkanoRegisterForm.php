<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class NelkanoRegisterForm extends FormBase {

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
    );
  }

  public function getFormId(): string {
    return 'nelkano_register_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $language = 'es'): array {
    $terms_description = $language === 'en'
      ? '<a href="/en/legal-notice" target="_blank" rel="noopener">Legal notice</a> - <a href="/en/privacy-cookies" target="_blank" rel="noopener">Privacy and cookies</a>'
      : '<a href="/aviso-legal" target="_blank" rel="noopener">Aviso legal</a> - <a href="/privacidad-cookies" target="_blank" rel="noopener">Privacidad y cookies</a>';
    $switch = $language === 'en'
      ? '<div class="nk-auth-switch">Already have an account? <a href="/en/user/login">Log in</a></div>'
      : '<div class="nk-auth-switch">Ya tienes una cuenta? <a href="/user/login">Inicia sesion</a></div>';

    $form['#attributes']['class'][] = 'nk-auth-form';
    $form['#action'] = $language === 'en' ? '/en/user/register' : Url::fromRoute('user.register')->toString();

    $form['grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['nk-auth-grid']],
    ];
    $form['grid']['name'] = [
      '#type' => 'textfield',
      '#title' => $language === 'en' ? 'Name' : 'Nombre',
      '#required' => TRUE,
      '#maxlength' => 60,
      '#attributes' => [
        'autocomplete' => 'name',
        'placeholder' => $language === 'en' ? 'Your name' : 'Tu nombre',
      ],
    ];
    $form['grid']['mail'] = [
      '#type' => 'email',
      '#title' => 'Email',
      '#required' => TRUE,
      '#attributes' => [
        'autocomplete' => 'email',
        'placeholder' => 'tu@email.com',
      ],
    ];
    $form['grid']['pass'] = [
      '#type' => 'password',
      '#title' => $language === 'en' ? 'Password' : 'Contrasena',
      '#required' => TRUE,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'placeholder' => '********',
      ],
    ];
    $form['grid']['pass_confirm'] = [
      '#type' => 'password',
      '#title' => $language === 'en' ? 'Confirm' : 'Confirmar',
      '#required' => TRUE,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'placeholder' => '********',
      ],
    ];

    $form['terms'] = [
      '#type' => 'checkbox',
      '#title' => $language === 'en' ? 'I accept the terms and conditions' : 'Acepto los terminos y condiciones',
      '#description' => $terms_description,
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $language === 'en' ? 'Create account' : 'Crear Cuenta',
      '#attributes' => ['class' => ['nk-auth-submit']],
    ];

    $form['switch'] = [
      '#markup' => $switch,
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $name = trim((string) $form_state->getValue('name'));
    $mail = trim((string) $form_state->getValue('mail'));
    $password = (string) $form_state->getValue('pass');
    $confirm = (string) $form_state->getValue('pass_confirm');

    if (strlen($name) < 2) {
      $form_state->setErrorByName('name', $language === 'en' ? 'Write a recognizable name.' : 'Escribe un nombre reconocible.');
    }
    if (strlen($password) < 8) {
      $form_state->setErrorByName('pass', $language === 'en' ? 'Use at least 8 characters.' : 'Usa al menos 8 caracteres.');
    }
    if ($password !== $confirm) {
      $form_state->setErrorByName('pass_confirm', $language === 'en' ? 'Passwords do not match.' : 'Las contrasenas no coinciden.');
    }

    $storage = \Drupal::entityTypeManager()->getStorage('user');
    if ($storage->loadByProperties(['mail' => $mail])) {
      $form_state->setErrorByName('mail', $language === 'en' ? 'An account already exists with that email.' : 'Ya existe una cuenta con ese correo.');
    }
    if ($storage->loadByProperties(['name' => $mail])) {
      $form_state->setErrorByName('mail', $language === 'en' ? 'That email is already reserved as a username.' : 'Ese correo ya esta reservado como usuario.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $mail = trim((string) $form_state->getValue('mail'));
    $display_name = trim((string) $form_state->getValue('name'));
    $password = (string) $form_state->getValue('pass');

    $account = User::create([
      'name' => $mail,
      'mail' => $mail,
      'pass' => $password,
      'status' => 0,
    ]);
    $account->save();
    $this->createInitialProfile((int) $account->id(), $display_name);

    $token = bin2hex(random_bytes(32));
    $expires = \Drupal::time()->getRequestTime() + 86400;
    $user_data = \Drupal::service('user.data');
    $user_data->set('nelkano_home', (int) $account->id(), 'verification_hash', hash('sha256', $token));
    $user_data->set('nelkano_home', (int) $account->id(), 'verification_expires', $expires);

    $verify_url = Url::fromRoute('nelkano_home.verify', [
      'uid' => $account->id(),
      'token' => $token,
    ], ['absolute' => TRUE])->toString();

    $params = [
      'name' => $display_name,
      'verify_url' => $verify_url,
    ];
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('nelkano_home', 'account_verify', $mail, $langcode, $params, 'contacto@nelkano.com', TRUE);
    if (!empty($result['result'])) {
      $this->messenger()->addStatus($language === 'en' ? 'Account created. We sent you an email to verify it.' : 'Cuenta creada. Te hemos enviado un correo para verificarla.');
    }
    else {
      $this->messenger()->addError($language === 'en' ? 'Account created, but the verification email could not be sent. Contact support.' : 'Cuenta creada, pero no se pudo enviar el correo de verificacion. Contacta con soporte.');
      if ($this->isLocalHost()) {
        $this->messenger()->addWarning(($language === 'en' ? 'Local environment: use this link to verify the account if Mailpit is not running: ' : 'Entorno local: usa este enlace para verificarla si Mailpit no esta levantado: ') . $verify_url);
      }
    }
    $form_state->setRedirectUrl(Url::fromUserInput($language === 'en' ? '/en/user/login' : '/user/login'));
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

  private function createInitialProfile(int $uid, string $displayName): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('nelkano_profile')) {
      return;
    }

    $now = \Drupal::time()->getRequestTime();
    $database->merge('nelkano_profile')
      ->key('uid', $uid)
      ->fields([
        'display_name' => $displayName,
        'status_text' => '',
        'avatar_color' => '#a414ff',
        'avatar_file_uri' => '',
        'updated' => $now,
      ])
      ->insertFields([
        'uid' => $uid,
        'display_name' => $displayName,
        'status_text' => '',
        'avatar_color' => '#a414ff',
        'avatar_file_uri' => '',
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
  }

  private function isLocalHost(): bool {
    $host = strtolower(\Drupal::request()->getHttpHost());
    return str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
  }

}
