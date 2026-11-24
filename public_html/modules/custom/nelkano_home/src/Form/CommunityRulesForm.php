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
    $paragraphs = array_slice(CommunityRules::copy($english), 1);
    $form['#cache']['max-age'] = 0;
    foreach ($paragraphs as $index => $paragraph) {
      $form['rule_' . $index] = ['#type' => 'container', '#tag' => 'p', 'text' => ['#plain_text' => $paragraph]];
    }
    $form['version_label'] = ['#type' => 'item', '#plain_text' => ($english ? 'Version ' : 'Versión ') . CommunityRules::VERSION];
    $account = $this->currentUser();
    if ($account->isAuthenticated() && $account->hasPermission('use nelkano app features')) {
      if (CommunityRules::accepted((int) $account->id())) {
        $form['status'] = ['#plain_text' => $english ? 'You have accepted this version.' : 'Ya has aceptado esta versión.'];
      }
      else {
        $form['version'] = ['#type' => 'hidden', '#default_value' => CommunityRules::VERSION];
        $form['submit'] = ['#type' => 'submit', '#value' => $english ? 'Accept the rules' : 'Aceptar las normas'];
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
      $form_state->setRedirect('nelkano_home.community_posts');
    }
    else {
      $form_state->setRebuild();
    }
  }
}
