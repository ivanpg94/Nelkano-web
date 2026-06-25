<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class NelkanoContactForm extends FormBase {

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
    return 'nelkano_contact_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $language = 'es'): array {
    $form['#attributes']['class'][] = 'nk-auth-form';
    $form['#action'] = $language === 'en' ? '/en/contact' : Url::fromRoute('nelkano_home.contact')->toString();

    $form['grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['nk-auth-grid']],
    ];
    $form['grid']['name'] = [
      '#type' => 'textfield',
      '#title' => $language === 'en' ? 'Name' : 'Nombre',
      '#required' => TRUE,
      '#maxlength' => 80,
      '#attributes' => ['placeholder' => $language === 'en' ? 'Your name' : 'Tu nombre'],
    ];
    $form['grid']['mail'] = [
      '#type' => 'email',
      '#title' => 'Email',
      '#required' => TRUE,
      '#attributes' => ['placeholder' => 'tu@email.com'],
    ];
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $language === 'en' ? 'Subject' : 'Asunto',
      '#required' => TRUE,
      '#maxlength' => 120,
      '#attributes' => ['placeholder' => $language === 'en' ? 'Question, issue or suggestion' : 'Consulta, error o propuesta'],
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $language === 'en' ? 'Message' : 'Mensaje',
      '#required' => TRUE,
      '#rows' => 7,
      '#attributes' => [
        'placeholder' => $language === 'en'
          ? 'Tell me what you need. For technical issues, include platform, version and steps to reproduce it.'
          : 'Cuentame que necesitas. Si es un problema tecnico, incluye plataforma, version y pasos para reproducirlo.',
      ],
    ];
    $form['privacy'] = [
      '#type' => 'checkbox',
      '#title' => $language === 'en'
        ? 'I accept that Nelkano uses this data to reply to my message.'
        : 'Acepto que Nelkano use estos datos para responder mi mensaje.',
      '#description' => $language === 'en'
        ? '<a href="/en/privacy-cookies" target="_blank" rel="noopener">Privacy</a>'
        : '<a href="/privacidad-cookies" target="_blank" rel="noopener">Ver privacidad</a>',
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $language === 'en' ? 'Send message' : 'Enviar mensaje',
      '#attributes' => ['class' => ['nk-auth-submit']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    if (strlen(trim((string) $form_state->getValue('message'))) < 3) {
      $form_state->setErrorByName('message', $language === 'en' ? 'Write at least 3 characters.' : 'Escribe al menos 3 caracteres.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $params = [
      'name' => trim((string) $form_state->getValue('name')),
      'mail' => trim((string) $form_state->getValue('mail')),
      'subject' => trim((string) $form_state->getValue('subject')),
      'message' => trim((string) $form_state->getValue('message')),
    ];
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('nelkano_home', 'contact_message', 'contacto@nelkano.com', $langcode, $params, $params['mail'], TRUE);
    if (!empty($result['result'])) {
      $this->messenger()->addStatus($this->isLocalHost()
        ? ($language === 'en' ? 'Message sent to local Mailpit. Check http://localhost:8025.' : 'Mensaje enviado a Mailpit local. Revisalo en http://localhost:8025.')
        : ($language === 'en' ? 'Message sent. Thanks for helping improve Nelkano.' : 'Mensaje enviado. Gracias por ayudar a mejorar Nelkano.'));
    }
    else {
      $this->messenger()->addError($language === 'en'
        ? 'The message could not be sent. Write directly to contacto@nelkano.com.'
        : 'No se pudo enviar el mensaje. Escribenos directamente a contacto@nelkano.com.');
    }
    $form_state->setRedirectUrl(Url::fromUserInput($language === 'en' ? '/en/contact' : '/contacto'));
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

  private function isLocalHost(): bool {
    $host = strtolower(\Drupal::request()->getHttpHost());
    return str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
  }

}
