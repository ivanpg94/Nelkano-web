<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthController extends ControllerBase {

  use NelkanoPageContextTrait;

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('extension.list.module'));
  }

  public function login(): Response {
    $language = $this->requestLanguage();
    if ($this->currentUser()->isAuthenticated()) {
      if (in_array('nelkano_editor', $this->currentUser()->getRoles(), TRUE)) {
        return new RedirectResponse('/admin/nelkano');
      }
      return new RedirectResponse($language === 'en' ? '/en/user/stream' : '/user/stream');
    }

    return $this->authPage(
      $this->formBuilder()->getForm('Drupal\nelkano_home\Form\NelkanoLoginForm', $language),
      'login',
      $language === 'en' ? 'Log in to continue' : 'Inicia sesion para continuar',
      $language,
    );
  }

  public function register(): Response {
    $language = $this->requestLanguage();
    if ($this->currentUser()->isAuthenticated()) {
      if (in_array('nelkano_editor', $this->currentUser()->getRoles(), TRUE)) {
        return new RedirectResponse('/admin/nelkano');
      }
      return new RedirectResponse($language === 'en' ? '/en/user/stream' : '/user/stream');
    }

    return $this->authPage(
      $this->formBuilder()->getForm('Drupal\nelkano_home\Form\NelkanoRegisterForm', $language),
      'register',
      $language === 'en' ? 'Join the Nelkano community' : 'Unete a nuestra comunidad',
      $language,
    );
  }

  public function password(): Response {
    $language = $this->requestLanguage();
    if ($this->currentUser()->isAuthenticated()) {
      if (in_array('nelkano_editor', $this->currentUser()->getRoles(), TRUE)) {
        return new RedirectResponse('/admin/nelkano');
      }
      return new RedirectResponse($language === 'en' ? '/en/user' : '/user');
    }

    $form = $this->formBuilder()->getForm('Drupal\user\Form\UserPasswordForm');
    $form['#attributes']['class'][] = 'nk-auth-form';
    $form['#action'] = $language === 'en' ? '/en/user/password' : \Drupal\Core\Url::fromRoute('user.pass')->toString();
    $form['name']['#title'] = $language === 'en' ? 'Email or username' : 'Correo electronico o usuario';
    $form['name']['#attributes']['placeholder'] = 'tu@email.com';
    $form['actions']['submit']['#value'] = $language === 'en' ? 'Send link' : 'Enviar enlace';
    if (isset($form['mail'])) {
      $form['mail']['#markup'] = $language === 'en' ? 'We will send instructions to recover access to your account.' : 'Te enviaremos instrucciones para recuperar el acceso a tu cuenta.';
    }

    return $this->authPage(
      $form,
      'password',
      $language === 'en' ? 'Recover access to your account' : 'Recupera el acceso a tu cuenta',
      $language,
    );
  }

  public function verify(int $uid, string $token): RedirectResponse {
    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface) {
      $this->messenger()->addError('El enlace de verificacion no es valido.');
      return new RedirectResponse('/user/login');
    }

    $user_data = \Drupal::service('user.data');
    $stored_hash = $user_data->get('nelkano_home', $uid, 'verification_hash');
    $expires = (int) ($user_data->get('nelkano_home', $uid, 'verification_expires') ?? 0);
    $valid = is_string($stored_hash)
      && hash_equals($stored_hash, hash('sha256', $token))
      && $expires >= \Drupal::time()->getRequestTime();

    if (!$valid) {
      $this->messenger()->addError('El enlace de verificacion ha caducado o no es valido.');
      return new RedirectResponse('/user/login');
    }

    if (!$account->isActive()) {
      $account->activate();
      $account->save();
    }
    $user_data->delete('nelkano_home', $uid, 'verification_hash');
    $user_data->delete('nelkano_home', $uid, 'verification_expires');

    user_login_finalize($account);
    $this->messenger()->addStatus('Cuenta verificada. Bienvenido a Nelkano.');
    return new RedirectResponse('/user/stream');
  }

  private function authPage(array $form, string $mode, string $subtitle, string $language): Response {
    $module_path = $this->moduleExtensionList->getPath('nelkano_home');
    $template = file_get_contents(DRUPAL_ROOT . '/' . $module_path . '/templates/nelkano-auth-standalone.html.twig');
    $renderer = \Drupal::service('renderer');
    $messages = ['#type' => 'status_messages'];
    $rendered_messages = $renderer->renderRoot($messages);
    $rendered_form = $renderer->renderRoot($form);
    $html = \Drupal::service('twig')->createTemplate($template)->render([
      'mode' => $mode,
      'page_title' => match ($mode) {
        'register' => $language === 'en' ? 'Create account' : 'Crear cuenta',
        'password' => $language === 'en' ? 'Reset password' : 'Recuperar contrasena',
        default => $language === 'en' ? 'Log in' : 'Iniciar sesion',
      },
      'auth_title' => match ($mode) {
        'register' => $language === 'en' ? 'Create your Nelkano account' : 'Crea tu cuenta Nelkano',
        'password' => $language === 'en' ? 'Recover your account' : 'Recupera tu cuenta',
        default => $language === 'en' ? 'Welcome back' : 'Bienvenido de nuevo',
      },
      'eyebrow' => match ($mode) {
        'register' => $language === 'en' ? 'New account' : 'Nueva cuenta',
        'password' => $language === 'en' ? 'Account access' : 'Acceso a cuenta',
        default => $language === 'en' ? 'Private area' : 'Area privada',
      },
      'form_kicker' => match ($mode) {
        'register' => $language === 'en' ? 'Start in one minute' : 'Empieza en un minuto',
        'password' => $language === 'en' ? 'Recovery email' : 'Correo de recuperacion',
        default => $language === 'en' ? 'Access form' : 'Formulario de acceso',
      },
      'auth_points' => $this->authPoints($mode, $language),
      'subtitle' => $subtitle,
      'messages' => $rendered_messages,
      'form' => $rendered_form,
      'base_css_url' => '/' . $module_path . '/css/base.css',
      'auth_css_url' => '/' . $module_path . '/css/auth.css',
    ] + $this->chromeContext(
      $module_path,
      $language,
      $this->alternateAuthUrl($mode, $language),
      $language === 'en' ? 'Espanol' : 'English',
    ));

    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
  }

  private function alternateAuthUrl(string $mode, string $language): string {
    $paths = [
      'login' => ['/user/login', '/en/user/login'],
      'register' => ['/user/register', '/en/user/register'],
      'password' => ['/user/password', '/en/user/password'],
    ];
    $pair = $paths[$mode] ?? $paths['login'];
    return $language === 'en' ? $pair[0] : $pair[1];
  }

  private function authPoints(string $mode, string $language): array {
    if ($language === 'en') {
      return match ($mode) {
        'register' => ['Save your profile', 'Prepare web streaming', 'Keep your account ready for future sync'],
        'password' => ['Enter your email', 'Open the recovery link', 'Choose a new password'],
        default => ['Access your profile', 'Open streaming', 'Manage your Nelkano account'],
      };
    }

    return match ($mode) {
      'register' => ['Guarda tu perfil', 'Prepara el streaming web', 'Deja tu cuenta lista para futuras sincronizaciones'],
      'password' => ['Introduce tu correo', 'Abre el enlace de recuperacion', 'Elige una nueva contrasena'],
      default => ['Accede a tu perfil', 'Abre el streaming', 'Gestiona tu cuenta Nelkano'],
    };
  }

}
