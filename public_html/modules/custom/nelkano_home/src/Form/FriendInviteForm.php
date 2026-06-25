<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class FriendInviteForm extends FormBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('database'));
  }

  public function getFormId(): string {
    return 'nelkano_friend_invite_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $language = $this->requestLanguage();
    $form['#attributes']['class'][] = 'nk-account-form';
    $form['identifier'] = [
      '#type' => 'textfield',
      '#title' => $language === 'en' ? 'Invite by email or username' : 'Invitar por email o usuario',
      '#required' => TRUE,
      '#maxlength' => 120,
      '#attributes' => ['placeholder' => 'amigo@email.com'],
    ];
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
    $target = $this->findUser(trim((string) $form_state->getValue('identifier')));
    if (!$target) {
      $form_state->setErrorByName('identifier', $language === 'en' ? 'No account was found with that email or username.' : 'No encuentro una cuenta con ese email o usuario.');
      return;
    }
    $target_uid = (int) $target->id();
    $current_uid = (int) $this->currentUser()->id();
    if ($target_uid === $current_uid) {
      $form_state->setErrorByName('identifier', $language === 'en' ? 'You cannot send yourself an invitation.' : 'No puedes enviarte una invitacion a ti mismo.');
      return;
    }
    if ($this->relationshipExists($current_uid, $target_uid)) {
      $form_state->setErrorByName('identifier', $language === 'en' ? 'A relationship or invitation already exists with that account.' : 'Ya existe una relacion o invitacion con esa cuenta.');
      return;
    }
    $form_state->set('target_uid', $target_uid);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $language = $this->requestLanguage();
    $this->createInvite((int) $this->currentUser()->id(), (int) $form_state->get('target_uid'));
    $this->messenger()->addStatus($language === 'en' ? 'Invitation sent.' : 'Invitacion enviada.');
    $form_state->setRedirectUrl(Url::fromUserInput($language === 'en' ? '/en/user' : '/user'));
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

  private function findUser(string $identifier): ?User {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $users = $storage->loadByProperties(['mail' => $identifier]) ?: $storage->loadByProperties(['name' => $identifier]);
    $user = $users ? reset($users) : NULL;
    return $user instanceof User ? $user : NULL;
  }

  private function relationshipExists(int $uidA, int $uidB): bool {
    return (bool) $this->database->select('nelkano_friendship', 'f')
      ->fields('f', ['id'])
      ->condition('requester_uid', [$uidA, $uidB], 'IN')
      ->condition('recipient_uid', [$uidA, $uidB], 'IN')
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  private function createInvite(int $requesterUid, int $recipientUid): void {
    $now = \Drupal::time()->getRequestTime();
    $this->database->insert('nelkano_friendship')
      ->fields([
        'requester_uid' => $requesterUid,
        'recipient_uid' => $recipientUid,
        'status' => 'pending',
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
  }

}
