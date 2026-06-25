<?php

namespace Drupal\nelkano_home\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

final class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('user.login')) {
      $route->setDefault('_controller', '\Drupal\nelkano_home\Controller\AuthController::login');
      $route->setDefault('_title', 'Iniciar sesion');
    }

    if ($route = $collection->get('user.register')) {
      $route->setDefault('_controller', '\Drupal\nelkano_home\Controller\AuthController::register');
      $route->setDefault('_title', 'Crear cuenta');
    }

    if ($route = $collection->get('user.pass')) {
      $route->setDefault('_controller', '\Drupal\nelkano_home\Controller\AuthController::password');
      $route->setDefault('_title', 'Recuperar contrasena');
    }

    if ($route = $collection->get('user.page')) {
      $route->setDefault('_controller', '\Drupal\nelkano_home\Controller\ProfileController::current');
      $route->setDefault('_title', 'Mi perfil');
      $this->allowControllerRedirect($route);
    }

    if ($route = $collection->get('entity.user.canonical')) {
      $route->setDefault('_controller', '\Drupal\nelkano_home\Controller\ProfileController::view');
      $route->setDefault('_title', 'Perfil');
      $this->allowControllerRedirect($route);
    }

    if ($route = $collection->get('entity.user.edit_form')) {
      $route->setDefault('_controller', '\Drupal\nelkano_home\Controller\ProfileController::edit');
      $route->setDefault('_title', 'Editar perfil');
      $this->allowControllerRedirect($route);
    }

    if ($route = $collection->get('user.edit')) {
      $route->setDefault('_controller', '\Drupal\nelkano_home\Controller\ProfileController::current');
      $route->setDefault('_title', 'Editar perfil');
      $this->allowControllerRedirect($route);
    }
  }

  private function allowControllerRedirect(\Symfony\Component\Routing\Route $route): void {
    $requirements = $route->getRequirements();
    unset($requirements['_user_is_logged_in']);
    $requirements['_permission'] = 'access content';
    $route->setRequirements($requirements);
  }

}
