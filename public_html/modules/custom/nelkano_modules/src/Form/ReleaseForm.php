<?php

namespace Drupal\nelkano_modules\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\ModuleAudience;

final class ReleaseForm extends FormBase {
  public function getFormId(): string { return 'nelkano_module_release_form'; }
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $module = NULL, ?NodeInterface $release = NULL): array {
    $canManageTester = ModuleAudience::canManageTester($this->currentUser());
    $private = $module && ModuleAudience::access($module) === 'tester';
    if (!$module || !ModuleRegistry::canEdit($module, $this->currentUser())
      || ($private && !$canManageTester)
      || ($release && ($release->bundle() !== 'nelkano_module_release'
        || (int) $release->get('field_release_module')->target_id !== (int) $module->id()))) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    if (!$form_state->get('initialized')) {
      $form_state->set('initialized', TRUE)->set('module', $module)->set('release', $release)
        ->set('revision', $release ? (int) $release->getRevisionId() : NULL);
    }
    $form['#attributes']['class'][] = 'nm-editor';
    $intro = $private
      ? 'Administra el paquete privado reservado a las cuentas Tester. El ID, la versión, las plataformas y los permisos se leen del archivo firmado.'
      : 'El ID, la versión, las plataformas y los permisos se leen del paquete firmado.';
    $form['intro'] = ['#markup' => '<h1>' . ($release ? 'Editar versión' : 'Añadir versión') . '</h1><p>' . $intro . '</p>'];
    $form['url'] = ['#access' => !$private, '#type' => 'url', '#title' => 'Descarga del archivo .nelkmod', '#required' => TRUE, '#maxlength' => 2048,
      '#default_value' => $release?->get('field_release_url')->value ?? '',
      '#description' => $release ? 'Puedes reparar el enlace conservando exactamente el archivo de esta versión.' : 'Usa una versión nueva firmada con la misma clave de autor.'];
    $form['package'] = ['#type' => 'managed_file', '#title' => 'Paquete privado firmado (.nelkmod)',
      '#access' => $private && $canManageTester, '#required' => $private,
      '#default_value' => $release && \Drupal\nelkano_modules\Service\ModuleHostedPackages::fid($release)
        ? [\Drupal\nelkano_modules\Service\ModuleHostedPackages::fid($release)] : [],
      '#upload_location' => 'private://nelkano-modules/packages', '#accept' => '.nelkmod',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'nelkmod'],
        'FileSizeLimit' => ['fileLimit' => \Drupal\nelkano_modules\Service\PackageVerifier::MAX_ARCHIVE]],
      '#description' => 'Sólo para administración de módulos. Hasta 32 MiB; la versión conserva ID y autor y la reparación conserva exactamente el mismo paquete.'];
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
      $registry = \Drupal::service('nelkano_modules.registry');
      $module = $form_state->get('module');
      $form_state->set('verified', \Drupal\nelkano_modules\Service\ModuleAudience::access($module) === 'tester'
        ? $registry->inspectUpload((int) ($form_state->getValue('package')[0] ?? 0), $module, $form_state->get('release'))
        : $registry->inspect((string) $form_state->getValue('url')));
    }
    catch (\InvalidArgumentException | \RuntimeException $e) { $form_state->setErrorByName(\Drupal\nelkano_modules\Service\ModuleAudience::access($form_state->get('module')) === 'tester' ? 'package' : 'url', $e->getMessage()); }
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