<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nelkano_home\Service\CommunityRules;

final class CommunityRulesForm extends FormBase {
  public function getFormId(): string {
    return 'nelkano_community_rules';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $language = 'es'): array {
    $english = $language === 'en';
    $form['#cache']['max-age'] = 0;
    $account = $this->currentUser();
    if ($account->isAuthenticated() && $account->hasPermission('use nelkano app features')) {
      if (CommunityRules::accepted((int) $account->id())) {
        $form['status'] = ['#plain_text' => $english ? 'You have accepted this version.' : 'Ya has aceptado esta versión.'];
      }
      else {
        $form['version'] = ['#type' => 'hidden', '#default_value' => CommunityRules::VERSION];
        $form['submit'] = [
          '#type' => 'submit',
          '#value' => $english ? 'Accept the rules' : 'Aceptar las normas',
          '#attributes' => ['class' => ['r-btn']],
        ];
      }
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    if (!$account->isAuthenticated() || !$account->hasPermission('use nelkano app features')) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    if (CommunityRules::accept((int) $account->id(), (string) $form_state->getValue('version'))) {
      $language = $form_state->getBuildInfo()['args'][0] ?? 'es';
      $form_state->setRedirect($language === 'en' ? 'nelkano_home.community_rules_en' : 'nelkano_home.community_rules_es');
    }
    else {
      $form_state->setRebuild();
    }
  }
}
