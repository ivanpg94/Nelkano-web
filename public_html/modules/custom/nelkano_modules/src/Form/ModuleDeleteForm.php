<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Delete only after a CSRF-protected confirmation of the reviewed revision. */
final class ModuleDeleteForm extends ConfirmFormBase {
  private ?NodeInterface $module = NULL;
  public function getFormId(): string { return 'nelkano_module_delete'; }
  public function getQuestion() { return $this->t('¿Eliminar «@name»?', ['@name' => $this->module?->label() ?? '']); }
  public function getDescription() { return $this->t('Se eliminarán la ficha y todas sus versiones del catálogo. Esta acción no se puede deshacer.'); }
  public function getConfirmText() { return $this->t('Eliminar módulo'); }
  public function getCancelUrl(): Url { return Url::fromRoute('nelkano_modules.detail', ['module' => $this->module?->id()]); }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $module = NULL): array {
    if (!$module || !ModuleRegistry::canEdit($module, $this->currentUser())) { throw new AccessDeniedHttpException(); }
    $this->module = $module;
    $form_state->set('module_id', (int) $module->id());
    $form['module_revision'] = ['#type' => 'hidden', '#default_value' => (string) $module->getRevisionId()];
    $form = parent::buildForm($form, $form_state);
    $form['#attributes']['class'][] = 'nm-editor';
    $form['actions']['submit']['#attributes']['class'][] = 'button--danger';
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $revision = $form_state->getValue('module_revision');
    if (!is_string($revision) || !ctype_digit($revision) || (string) (int) $revision !== $revision || (int) $revision < 1) {
      $form_state->setErrorByName('module_revision', $this->t('Recarga la confirmación antes de eliminar el módulo.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $module = \Drupal::entityTypeManager()->getStorage('node')->load($form_state->get('module_id'));
    if (!$module) { throw new ConflictHttpException('El módulo ya no está disponible.'); }
    try {
      \Drupal::service('nelkano_modules.registry')->deleteModule($module, (int) $form_state->getValue('module_revision'));
      $this->messenger()->addStatus($this->t('El módulo y sus versiones se han eliminado del catálogo.'));
      $form_state->setRedirect('nelkano_modules.mine');
    }
    catch (ConflictHttpException $error) {
      $this->messenger()->addError($error->getMessage());
      $form_state->setRedirect('nelkano_modules.detail', ['module' => $module->id()]);
    }
  }
}
