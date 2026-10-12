<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\nelkano_home\Controller\AppUpdatesController;

final class AppUpdatesForm extends ConfigFormBase {
  public function getFormId(): string { return 'nelkano_app_updates'; }
  protected function getEditableConfigNames(): array { return ['nelkano_home.app_updates']; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('nelkano_home.app_updates');
    $form['intro'] = ['#markup' => '<p>Actualizaciones de la nueva aplicación. Android requiere un APK firmado con la misma clave. Windows requiere un MSI con el mismo Upgrade UUID. Linux/SteamOS requiere un ZIP con Nelkano/bin/Nelkano y su runtime. SDK Studio Windows requiere el ZIP completo generado por packageStudioUpdate, con Studio y sus herramientas. Cambia versión y código solo cuando Ivan lo solicite expresamente (studio-version.properties para Studio); no reutilices códigos para archivos distintos.</p>'];
    foreach (AppUpdatesController::PLATFORMS as $platform => [$app, $extension]) {
      $build = $config->get('active.' . $platform);
      $row = $build ? ($config->get('releases.' . $platform . '.' . $build) ?? []) : [];
      $form[$platform] = ['#type' => 'details', '#title' => $platform, '#open' => TRUE, '#tree' => TRUE];
      $form[$platform]['enabled'] = ['#type' => 'checkbox', '#title' => 'Ofrecer actualización', '#default_value' => (bool) $build];
      $form[$platform]['version'] = ['#type' => 'textfield', '#title' => 'Versión', '#default_value' => $row['version'] ?? '', '#maxlength' => 64];
      $form[$platform]['build'] = ['#type' => 'number', '#title' => 'Código de versión', '#min' => 1, '#max' => 2147483647, '#default_value' => $build ?: ''];
      $form[$platform]['file'] = ['#type' => 'managed_file', '#title' => 'Archivo .' . $extension,
        '#upload_location' => 'private://app-updates/' . $platform . '/',
        '#upload_validators' => ['FileExtension' => ['extensions' => $extension]],
        '#default_value' => isset($row['file_id']) ? [(int) $row['file_id']] : []];
      $form[$platform]['notes'] = ['#type' => 'textarea', '#title' => 'Cambios', '#maxlength' => 8000, '#default_value' => $row['notes'] ?? ''];
    }
    return parent::buildForm($form, $form_state);
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach (AppUpdatesController::PLATFORMS as $platform => [$app, $extension]) {
      $value = $form_state->getValue($platform);
      if (empty($value['enabled'])) continue;
      if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $value['version'] ?? '') || (int) ($value['build'] ?? 0) < 1) {
        $form_state->setErrorByName($platform, 'Indica una versión x.y.z y un código positivo.'); continue;
      }
      $file = File::load((int) ($value['file'][0] ?? 0));
      if (!$file || !str_starts_with($file->getFileUri(), 'private://app-updates/' . $platform . '/')) {
        $form_state->setErrorByName($platform, 'Sube el paquete de esta plataforma.'); continue;
      }
      $path = \Drupal::service('file_system')->realpath($file->getFileUri());
      if (!$path || !is_file($path) || filesize($path) < 1 || filesize($path) > 2147483648) {
        $form_state->setErrorByName($platform, 'El archivo debe tener entre 1 byte y 2 GiB.'); continue;
      }
      $hash = hash_file('sha256', $path);
      $previous = $this->config('nelkano_home.app_updates')->get('releases.' . $platform . '.' . $value['build']);
      if ($previous && ($previous['sha256'] !== $hash || $previous['version'] !== $value['version'])) {
        $form_state->setErrorByName($platform, 'Ese código ya identifica otro paquete: usa uno nuevo.'); continue;
      }
      $form_state->set('release_' . $platform, ['file_id' => (int) $file->id(), 'version' => $value['version'], 'bytes' => filesize($path), 'sha256' => $hash, 'notes' => $value['notes'] ?? '']);
    }
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('nelkano_home.app_updates');
    foreach (AppUpdatesController::PLATFORMS as $platform => $identity) {
      $value = $form_state->getValue($platform);
      if (empty($value['enabled'])) { $config->clear('active.' . $platform); continue; }
      $row = $form_state->get('release_' . $platform);
      $file = File::load($row['file_id']); $file->setPermanent(); $file->save();
      $usage = \Drupal::service('file.usage');
      if (empty($usage->listUsage($file)['nelkano_home']['app_update'][$value['build']])) $usage->add($file, 'nelkano_home', 'app_update', $value['build']);
      $config->set('releases.' . $platform . '.' . $value['build'], $row)->set('active.' . $platform, (int) $value['build']);
    }
    $config->save(); parent::submitForm($form, $form_state);
  }
}
