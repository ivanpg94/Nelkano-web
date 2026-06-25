<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class FriendActionForm extends FormBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('database'));
  }

  public function getFormId(): string {
    return 'nelkano_friend_action_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, int $friendshipId = 0, string $action = 'accept'): array {
    $language = $this->requestLanguage();
    $labels = $language === 'en'
      ? ['accept' => 'Accept', 'reject' => 'Reject', 'cancel' => 'Cancel']
      : ['accept' => 'Aceptar', 'reject' => 'Rechazar', 'cancel' => 'Cancelar'];
    $form['#attributes']['class'][] = 'nk-inline-form';
    $form['friendship_id'] = ['#type' => 'hidden', '#value' => $friendshipId];
    $form['friend_action'] = ['#type' => 'hidden', '#value' => $action];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $labels[$action] ?? 'Actualizar',
      '#attributes' => ['class' => ['nk-small-button']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $id = (int) $form_state->getValue('friendship_id');
    $action = (string) $form_state->getValue('friend_action');
    $row = $this->database->select('nelkano_friendship', 'f')
      ->fields('f')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    $current_uid = (int) $this->currentUser()->id();
    if (!$row) {
      $form_state->setErrorByName('friendship_id', $language === 'en' ? 'Invitation not found.' : 'Invitacion no encontrada.');
      return;
    }
    if (in_array($action, ['accept', 'reject'], TRUE) && (int) $row['recipient_uid'] !== $current_uid) {
      $form_state->setErrorByName('friendship_id', $language === 'en' ? 'You cannot respond to this invitation.' : 'No puedes responder esta invitacion.');
    }
    if ($action === 'cancel' && (int) $row['requester_uid'] !== $current_uid) {
      $form_state->setErrorByName('friendship_id', $language === 'en' ? 'You cannot cancel this invitation.' : 'No puedes cancelar esta invitacion.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $id = (int) $form_state->getValue('friendship_id');
    $action = (string) $form_state->getValue('friend_action');
    if ($action === 'accept') {
      $this->database->update('nelkano_friendship')
        ->fields(['status' => 'accepted', 'updated' => \Drupal::time()->getRequestTime()])
        ->condition('id', $id)
        ->execute();
      $this->messenger()->addStatus($language === 'en' ? 'Invitation accepted.' : 'Invitacion aceptada.');
    }
    else {
      $this->database->delete('nelkano_friendship')
        ->condition('id', $id)
        ->execute();
      $this->messenger()->addStatus($action === 'cancel'
        ? ($language === 'en' ? 'Invitation cancelled.' : 'Invitacion cancelada.')
        : ($language === 'en' ? 'Invitation rejected.' : 'Invitacion rechazada.'));
    }
    $form_state->setRedirectUrl(Url::fromUserInput($language === 'en' ? '/en/user' : '/user'));
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

}
