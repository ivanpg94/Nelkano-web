<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class NelkanoLoginForm extends FormBase {

  public function __construct(
    private readonly UserAuthInterface $userAuth,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('user.auth'));
  }

  public function getFormId(): string {
    return 'nelkano_login_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $language = 'es'): array {
    $form['#attributes']['class'][] = 'nk-auth-form';
    $form['#action'] = $language === 'en' ? '/en/user/login' : Url::fromRoute('user.login')->toString();

    $form['name'] = [
      '#type' => 'email',
      '#title' => $language === 'en' ? 'Email address' : 'Correo electronico',
      '#required' => TRUE,
      '#attributes' => [
        'autocomplete' => 'email',
        'placeholder' => 'tu@email.com',
      ],
    ];

    $form['pass'] = [
      '#type' => 'password',
      '#title' => $language === 'en' ? 'Password' : 'Contrasena',
      '#required' => TRUE,
      '#attributes' => [
        'autocomplete' => 'current-password',
        'placeholder' => '••••••••',
      ],
    ];

    $form['row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['nk-auth-row']],
    ];
    $form['row']['remember'] = [
      '#type' => 'checkbox',
      '#title' => $language === 'en' ? 'Remember me' : 'Recordarme',
    ];
    $form['row']['forgot'] = [
      '#type' => 'link',
      '#title' => $language === 'en' ? 'Forgot your password?' : '¿Olvidaste tu contrasena?',
      '#url' => Url::fromUri('internal:' . ($language === 'en' ? '/en/user/password' : '/user/password')),
      '#attributes' => ['class' => ['nk-auth-link']],
    ];

    $form['actions'] = ['#type' => 'actions', '#weight' => 20];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $language === 'en' ? 'Log in' : 'Iniciar Sesion',
      '#attributes' => ['class' => ['nk-auth-submit']],
    ];

    $form['switch'] = [
      '#weight' => 30,
      '#markup' => $language === 'en'
        ? '<div class="nk-auth-switch">No account yet? <a href="/en/user/register">Create one</a></div>'
        : '<div class="nk-auth-switch">¿No tienes una cuenta? <a href="/user/register">Registrate</a></div>',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $mail = trim((string) $form_state->getValue('name'));
    $password = (string) $form_state->getValue('pass');
    $accounts = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $mail]);
    $account = $accounts ? reset($accounts) : NULL;

    if (!$account) {
      $form_state->setErrorByName('name', 'No encontramos una cuenta con ese correo.');
      return;
    }

    $uid = $this->userAuth->authenticate($account->getAccountName(), $password);
    if (!$uid) {
      $form_state->setErrorByName('pass', 'La contrasena no es correcta.');
      return;
    }

    if (!$account->isActive()) {
      $form_state->setErrorByName('name', 'Revisa tu correo y verifica la cuenta antes de iniciar sesion.');
      return;
    }

    $form_state->set('uid', $uid);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = User::load((int) $form_state->get('uid'));
    if ($account) {
      user_login_finalize($account);
      $form_state->setRedirect($account->hasRole('nelkano_editor') ? 'nelkano_home.admin' : ($this->isEnglishRequest() ? 'nelkano_home.user_stream_en' : 'nelkano_home.user_stream'));
    }
  }

  private function isEnglishRequest(): bool {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en');
  }

}
