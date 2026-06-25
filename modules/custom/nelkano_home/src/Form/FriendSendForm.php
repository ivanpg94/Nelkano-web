<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class FriendSendForm extends FormBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('database'));
  }

  public function getFormId(): string {
    return 'nelkano_friend_send_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $target = NULL): array {
    $language = $this->requestLanguage();
    $form['#attributes']['class'][] = 'nk-inline-form';
    $form['target_uid'] = ['#type' => 'hidden', '#value' => (int) $target?->id()];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $language === 'en' ? 'Send invitation' : 'Enviar invitacion',
      '#attributes' => ['class' => ['nk-account-submit']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $target_uid = (int) $form_state->getValue('target_uid');
    $current_uid = (int) $this->currentUser()->id();
    if ($target_uid <= 0 || $target_uid === $current_uid) {
      $form_state->setErrorByName('target_uid', $language === 'en' ? 'Invalid invitation.' : 'Invitacion no valida.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $now = \Drupal::time()->getRequestTime();
    $this->database->insert('nelkano_friendship')
      ->fields([
        'requester_uid' => (int) $this->currentUser()->id(),
        'recipient_uid' => (int) $form_state->getValue('target_uid'),
        'status' => 'pending',
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
    $this->messenger()->addStatus($language === 'en' ? 'Invitation sent.' : 'Invitacion enviada.');
    $form_state->setRedirectUrl(Url::fromUserInput($language === 'en' ? '/en/user' : '/user'));
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

}
