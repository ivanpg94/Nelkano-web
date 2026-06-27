<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class AdminController extends ControllerBase {

  public function index(): RedirectResponse {
    return $this->redirect('nelkano_home.admin_home');
  }

}
