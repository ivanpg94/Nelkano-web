<?php

namespace Drupal\nelkano_modules\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\SecureDownloadClient;

final class ModuleForm extends FormBase {
  public function getFormId(): string { return 'nelkano_module_form'; }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $module = NULL): array {
    if ($module && !ModuleRegistry::canEdit($module, $this->currentUser())) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    if (!$form_state->get('initialized')) {
      $form_state->set('initialized', TRUE)->set('module', $module)->set('revision', $module ? (int) $module->getRevisionId() : NULL);
    }
    $form['#attributes']['class'][] = 'nm-editor';
    $form['intro'] = ['#markup' => '<h1>' . ($module ? 'Editar módulo' : 'Publicar un módulo') . '</h1><p>Comparte tu módulo con la comunidad. El archivo se descarga desde tu repositorio.</p>'];
    $form['title'] = ['#type' => 'textfield', '#title' => 'Nombre del módulo', '#required' => TRUE, '#maxlength' => 255, '#default_value' => $module?->label() ?? ''];
    $form['description'] = ['#type' => 'textarea', '#title' => 'Descripción', '#required' => TRUE, '#maxlength' => 12000, '#rows' => 6,
      '#description' => 'Explica qué hace y cómo se utiliza. Texto sin formato.', '#default_value' => $module?->get('field_module_description')->value ?? ''];
    $form['systems'] = ['#type' => 'checkboxes', '#title' => 'Sistemas compatibles',
      '#options' => \Drupal\nelkano_modules\Service\ModuleSystems::options(),
      '#default_value' => $module ? array_column($module->get('field_module_systems')->getValue(), 'value') : [],
      '#attributes' => ['class' => ['nm-system-options']],
      '#description' => 'Marca todos los sistemas para los que sirve tu módulo. Sin selección, la compatibilidad queda sin especificar y la ficha no aparece al filtrar por un sistema.'];
    $form['image'] = ['#type' => 'managed_file', '#title' => 'Imagen del módulo',
      '#default_value' => $module && !$module->get('field_module_image')->isEmpty() ? [(int) $module->get('field_module_image')->target_id] : [],
      '#after_build' => [[self::class, 'imagePreview']],
      '#upload_location' => 'public://nelkano-modules/images', '#accept' => 'image/png,image/jpeg,image/webp',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'png jpg jpeg webp'],
        'FileSizeLimit' => ['fileLimit' => 2 * 1024 * 1024], 'FileIsImage' => []],
      '#description' => 'Opcional. PNG, JPG o WebP, hasta 2 MB y 4096 × 4096 píxeles. Se muestra en el catálogo y en la ficha. Recomendado: formato horizontal 16:9.'];
    $form['source'] = ['#type' => 'url', '#title' => 'Repositorio del código fuente', '#maxlength' => 2048,
      '#description' => 'Opcional. Enlace HTTPS al proyecto para colaborar o mantenerlo.', '#default_value' => $module?->get('field_module_source')->value ?? ''];
    if (!$module) {
      $form['url'] = ['#type' => 'url', '#title' => 'Descarga de la primera versión (.nelkmod)', '#required' => TRUE, '#maxlength' => 2048,
        '#description' => 'Enlace HTTPS público al archivo firmado exportado con el SDK. Por ejemplo, un archivo adjunto a una release de GitHub.'];
      $form['notes'] = ['#type' => 'textarea', '#title' => 'Notas de esta versión', '#maxlength' => 8000, '#rows' => 3];
    }
    $form['published'] = ['#type' => 'checkbox', '#title' => 'Mostrar la ficha públicamente', '#default_value' => $module ? $module->isPublished() : TRUE];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $module ? 'Guardar cambios' : 'Publicar módulo'];
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => 'Cancelar', '#url' => $module
      ? Url::fromRoute('nelkano_modules.detail', ['module' => $module->id()]) : Url::fromRoute('nelkano_modules.catalog')];
    return $form;
  }

  /** Include the preview in the native upload/remove AJAX replacement. */
  public static function imagePreview(array $element, FormStateInterface $form_state): array {
    $element['upload_button']['#value'] = 'Subir imagen';
    $element['remove_button']['#value'] = 'Quitar imagen';
    $fid = (int) ($element['#value']['fids'][0] ?? 0);
    $file = $fid ? \Drupal\file\Entity\File::load($fid) : NULL;
    if ($file && $file->access('download')) {
      $image = \Drupal::service('image.factory')->get($file->getFileUri());
      if ($image->isValid() && $image->getFileSize() <= 2 * 1024 * 1024 && $image->getWidth() <= 4096 && $image->getHeight() <= 4096) {
        $element['preview'] = ['#theme' => 'image_style', '#style_name' => 'nelkano_module_card',
          '#uri' => $file->getFileUri(), '#alt' => 'Vista previa de la imagen del módulo',
          '#prefix' => '<div class="nm-image-preview">', '#suffix' => '</div>', '#weight' => 20];
      }
    }
    return $element;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['title', 'description'] as $key) {
      if (trim((string) $form_state->getValue($key)) === '') { $form_state->setErrorByName($key, 'Completa este campo.'); }
    }
    try {
      if ($form_state->getValue('source')) { SecureDownloadClient::validateUrl($form_state->getValue('source')); }
    }
    catch (\InvalidArgumentException $e) { $form_state->setErrorByName('source', $e->getMessage()); }
    try {
      \Drupal::service('nelkano_modules.registry')->validateImage((int) ($form_state->getValue('image')[0] ?? 0), $form_state->get('module'));
    }
    catch (\InvalidArgumentException $e) { $form_state->setErrorByName('image', $e->getMessage()); }
    // Do not download the external package when local fields are already invalid.
    if (!$form_state->hasAnyErrors() && !$form_state->get('module')) {
      try {
        $form_state->set('verified', \Drupal::service('nelkano_modules.registry')->inspect($form_state->getValue('url')));
      }
      catch (\InvalidArgumentException | \RuntimeException $e) { $form_state->setErrorByName('url', $e->getMessage()); }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $node = \Drupal::service('nelkano_modules.registry')->saveModule($form_state->get('module'), $form_state->get('revision'),
        ['title' => $form_state->getValue('title'), 'description' => $form_state->getValue('description'),
          'source' => $form_state->getValue('source') ?: '', 'published' => (bool) $form_state->getValue('published'),
          'systems' => array_values(array_filter($form_state->getValue('systems') ?? [])),
          'image' => (int) ($form_state->getValue('image')[0] ?? 0),
          'notes' => $form_state->getValue('notes') ?? ''], $form_state->get('verified'));
      $this->messenger()->addStatus('La ficha del módulo se ha guardado.');
      $form_state->setRedirect('nelkano_modules.detail', ['module' => $node->id()]);
    }
    catch (\InvalidArgumentException | \Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
      $this->messenger()->addError($e->getMessage()); $form_state->setRebuild();
    }
  }
}