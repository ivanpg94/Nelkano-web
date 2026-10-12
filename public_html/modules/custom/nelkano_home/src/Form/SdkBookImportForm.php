<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormStateInterface;

/** Private transient upload, required simulation, then explicit application. */
final class SdkBookImportForm extends SdkBookAdminFormBase {
  public function getFormId(): string { return 'nelkano_sdk_book_import'; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->chrome($form, 'Importar SDK de Nelkano', 'Selecciona un YAML del libro. Primero se simula; después puedes aplicar los cambios. Las páginas omitidas se conservan.');
    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['actions'] = ['#type' => 'actions'];
    if ($plan = $form_state->get('sdk_plan')) {
      $form['preview'] = ['#type' => 'table', '#header' => ['Página', 'Cambio previsto'], '#rows' => []];
      $labels = ['create' => 'Crear', 'update' => 'Actualizar con revisión', 'unchanged' => 'Sin cambios'];
      $pages = array_column($form_state->get('sdk_import')['pages'], NULL, 'uuid');
      foreach ($plan as $uuid => $action) {
        $page = $pages[$uuid];
        $form['preview']['#rows'][] = [$page['translations'][$page['langcode']]['title'], $labels[$action]];
      }
      $form['actions']['apply'] = ['#type' => 'submit', '#name' => 'apply', '#value' => 'Importar cambios'];
    }
    else {
      $form['yaml'] = ['#type' => 'file', '#title' => 'Archivo YAML', '#description' => 'Solo el libro SDK de Nelkano, hasta 2 MiB. El archivo se procesa temporalmente; no se publica ni se guarda como adjunto.', '#attributes' => ['accept' => '.yml,.yaml']];
      $form['actions']['preview'] = ['#type' => 'submit', '#name' => 'preview', '#value' => 'Simular importación'];
    }
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      if ($form_state->get('sdk_plan')) {
        if ($form_state->get('sdk_snapshot') !== hash('sha256', serialize($this->currentData()))) {
          throw new \InvalidArgumentException('El libro cambió desde la simulación. Vuelve a Importar para repetirla con la versión actual.');
        }
        $this->transfer()->import($form_state->get('sdk_import'), TRUE);
      }
      else {
        $files = \Drupal::request()->files->get('files', []);
        $file = $files['yaml'] ?? NULL;
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()
          || $file->getSize() > 2 * 1024 * 1024 || !in_array(strtolower($file->getClientOriginalExtension()), ['yml', 'yaml'], TRUE)) {
          throw new \InvalidArgumentException('Selecciona un archivo YAML válido de hasta 2 MiB.');
        }
        $data = $this->transfer()->read($file->getPathname());
        self::assertScope($data);
        $plan = $this->transfer()->import($data, TRUE);
        $form_state->set('sdk_import', $data)->set('sdk_plan', $plan)
          ->set('sdk_snapshot', hash('sha256', serialize($this->currentData())));
      }
    }
    catch (\InvalidArgumentException | \RuntimeException | \Symfony\Component\Yaml\Exception\ParseException $e) {
      $form_state->setErrorByName('yaml', $e->getMessage());
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'apply') {
      try {
        $this->transfer()->import($form_state->get('sdk_import'));
        $this->messenger()->addStatus('Libro importado. Se han conservado las páginas omitidas y creado revisiones para los cambios.');
        $form_state->setRedirect('nelkano_home.admin_sdk');
      }
      catch (\InvalidArgumentException | \RuntimeException $e) { $this->messenger()->addError($e->getMessage()); $form_state->setRebuild(); }
    }
    else {
      $this->messenger()->addStatus('Simulación completada. No se ha modificado el libro.');
      $form_state->setRebuild();
    }
  }
}
