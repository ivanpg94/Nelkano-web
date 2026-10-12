<?php

namespace Drupal\nelkano_modules\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nelkano_home\Controller\NelkanoPageContextTrait;

final class ModulePageContext extends ControllerBase {
  use NelkanoPageContextTrait;

  public function context(): array {
    $path = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    return $this->chromeContext($path, 'es', '', '') + [
      'footer_primary' => \Drupal::config('nelkano_home.settings')->get('es.footer_primary'),
    ];
  }
}