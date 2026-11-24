<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nelkano_home\Service\CommunityRules;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** In-context acceptance for existing accounts entering the web Community. */
final class CommunityRulesAcceptanceForm extends FormBase {
  public function getFormId(): string {
    return 'nelkano_community_rules_acceptance';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $form['notice'] = ['#plain_text' => $this->t('Para publicar o comentar, lee las normas y acepta la versión vigente.')];
    $form['version'] = ['#type' => 'hidden', '#default_value' => CommunityRules::VERSION];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Aceptar las normas de Comunidad')];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    if (!$account->isAuthenticated() || !$account->hasPermission('use nelkano app features')) {
      throw new AccessDeniedHttpException();
    }
    if (!CommunityRules::accept((int) $account->id(), (string) $form_state->getValue('version'))) {
      $this->messenger()->addError($this->t('No se pudo registrar la aceptación. Revisa las normas e inténtalo de nuevo.'));
    }
    $form_state->setRedirect('nelkano_home.community_posts');
  }
}
