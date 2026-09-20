<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nelkano_home\Service\SystemPages;

/** Independent system editor in the editable-pages sidebar. */
final class SystemSettingsForm extends ConfigFormBase {

  use AdminFormUiTrait;
  use SystemPagesFormTrait;
  use ConfigRowsFormTrait;

  public function getFormId(): string {
    return 'nelkano_system_settings';
  }

  protected function getEditableConfigNames(): array {
    return [SystemPages::CONFIG];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $language = $this->activeAdminLanguage();
    $this->applyNelkanoAdminChrome(
      $form,
      'systems',
      $language === 'es' ? 'Sistemas' : 'Systems',
      $language === 'es' ? 'Gestiona las fichas de cada core y sus tarjetas en Estado actual.' : 'Manage each core information page and its current-status card.',
      ($language === 'es' ? '/sistemas' : '/en/systems'),
      $language,
    );
    $form['catalog_actions']=['#type'=>'container','#weight'=>-100,'#attributes'=>['class'=>['r-catalog-actions']]];
    $form['catalog_actions']['add']=['#type'=>'link','#title'=>$language==='es'?'＋ Añadir sistema':'＋ Add system','#url'=>\Drupal\Core\Url::fromRoute('nelkano_home.admin_add_system',[],['query'=>['admin_lang'=>$language]]),'#attributes'=>['class'=>['button']]];
    $form['active_language'] = ['#type' => 'value', '#value' => $language];
    $form[$language] = ['#type' => 'container', '#tree' => TRUE, '#attributes' => ['class' => ['nk-admin-language-fields']]];
    $this->buildSystemPages($form, $language, $form_state);
    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $language === 'es' ? 'Guardar cambios' : 'Save changes';
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveSystemPages($form_state, (string) $form_state->getValue('active_language'));
    parent::submitForm($form, $form_state);
  }
}
