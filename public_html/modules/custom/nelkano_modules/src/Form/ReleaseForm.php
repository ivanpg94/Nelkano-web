<?php

namespace Drupal\nelkano_modules\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\nelkano_modules\Service\ModuleRegistry;

final class ReleaseForm extends FormBase {
  public function getFormId(): string { return 'nelkano_module_release_form'; }
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $module = NULL, ?NodeInterface $release = NULL): array {
    if (!$module || !ModuleRegistry::canEdit($module, $this->currentUser())
      || ($release && ($release->bundle() !== 'nelkano_module_release'
        || (int) $release->get('field_release_module')->target_id !== (int) $module->id()))) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    if (!$form_state->get('initialized')) {
      $form_state->set('initialized', TRUE)->set('module', $module)->set('release', $release)
        ->set('revision', $release ? (int) $release->getRevisionId() : NULL);
    }
    $form['#attributes']['class'][] = 'nm-editor';
    $form['intro'] = ['#markup' => '<h1>' . ($release ? 'Editar versión' : 'Añadir versión') . '</h1><p>El ID, la versión, las plataformas y los permisos se leen del paquete firmado.</p>'];
    $form['url'] = ['#type' => 'url', '#title' => 'Descarga del archivo .nelkmod', '#required' => TRUE, '#maxlength' => 2048,
      '#default_value' => $release?->get('field_release_url')->value ?? '',
      '#description' => $release ? 'Puedes reparar el enlace conservando exactamente el archivo de esta versión.' : 'Usa una versión nueva firmada con la misma clave de autor.'];
    $form['notes'] = ['#type' => 'textarea', '#title' => 'Cambios de esta versión', '#maxlength' => 8000, '#rows' => 5,
      '#default_value' => $release?->get('field_release_notes')->value ?? ''];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => 'Guardar versión'];
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => 'Cancelar',
      '#url' => Url::fromRoute('nelkano_modules.detail', ['module' => $module->id()])];
    return $form;
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $form_state->set('verified', \Drupal::service('nelkano_modules.registry')->inspect($form_state->getValue('url')));
    }
    catch (\InvalidArgumentException | \RuntimeException $e) { $form_state->setErrorByName('url', $e->getMessage()); }
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $module = $form_state->get('module');
      \Drupal::service('nelkano_modules.registry')->saveVersion($module, $form_state->get('release'),
        $form_state->get('revision'), $form_state->get('verified'), $form_state->getValue('notes') ?? '');
      $this->messenger()->addStatus('La versión se ha verificado y guardado.');
      $form_state->setRedirect('nelkano_modules.detail', ['module' => $module->id()]);
    }
    catch (\InvalidArgumentException | \Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
      $this->messenger()->addError($e->getMessage()); $form_state->setRebuild();
    }
  }
}