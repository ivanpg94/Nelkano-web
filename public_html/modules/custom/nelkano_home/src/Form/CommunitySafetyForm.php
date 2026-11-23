<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\CommunityBlocks;
use Drupal\nelkano_home\Service\CommunityModeration;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CommunitySafetyForm extends FormBase {

  public function getFormId(): string {
    return 'nelkano_community_safety';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $kind = '', string $target = '', string $mode = 'report'): array {
    $entity = CommunityModeration::reportable($this->currentUser(), $kind, $target);
    $target = (string) $entity->id();
    $form['#cache']['max-age'] = 0;
    $form_state->set('safety_target', [$kind, $target, $mode]);
    $label = (string) $entity->label();
    if ($entity instanceof UserInterface && strcasecmp($label, (string) $entity->getEmail()) === 0) {
      $label = (string) $this->t('Usuario @uid', ['@uid' => $target]);
    }
    $form['target'] = ['#plain_text' => $label];
    if ($mode === 'block') {
      $blocked = CommunityBlocks::preferences((int) $this->currentUser()->id(), (int) $target)['blocked'];
      $form_state->set('safety_blocked', !$blocked);
      $form['description'] = ['#plain_text' => (string) ($blocked
        ? $this->t('Podrás volver a interactuar con esta cuenta si también te permite el contacto.')
        : $this->t('La cuenta bloqueada no podrá enviarte mensajes directos ni solicitudes de amistad. Sus publicaciones dejarán de aparecer.'))];
      $label = $blocked ? $this->t('Desbloquear usuario') : $this->t('Bloquear usuario');
    }
    else {
      $form['reason'] = ['#type' => 'radios', '#title' => $this->t('Motivo'), '#options' => CommunityModeration::REASONS, '#required' => TRUE];
      $label = $this->t('Enviar denuncia');
    }
    $form['actions'] = ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => $label]];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$kind, $target, $mode] = $form_state->get('safety_target');
    try {
      if ($mode === 'block') {
        CommunityModeration::reportable($this->currentUser(), 'user', $target);
        $blocked = $form_state->get('safety_blocked');
        CommunityBlocks::change(\Drupal::database(), (int) $this->currentUser()->id(), (int) $target, ['blocked' => $blocked]);
        $this->messenger()->addStatus($blocked ? $this->t('Usuario bloqueado.') : $this->t('Usuario desbloqueado.'));
      }
      else {
        CommunityModeration::submit($this->currentUser(), $kind, $target, (string) $form_state->getValue('reason'));
        $this->messenger()->addStatus($this->t('Denuncia enviada para revisión.'));
      }
    }
    catch (HttpExceptionInterface $error) {
      $this->messenger()->addError($error->getMessage());
      $form_state->setRebuild();
      return;
    }
    $entity = CommunityModeration::target($kind, $target);
    $destination = $entity?->hasLinkTemplate('canonical') ? $entity->toUrl() : Url::fromRoute('<front>');
    $form_state->setRedirectUrl($destination);
  }

}
