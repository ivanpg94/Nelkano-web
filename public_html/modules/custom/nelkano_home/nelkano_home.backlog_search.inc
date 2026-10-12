<?php

/**
 * @file
 * Additive Search API configuration for private HU content.
 */

use Drupal\Component\Serialization\Yaml;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;

/** Install missing configuration without rewriting nodes or existing indexes. */
function nelkano_home_ensure_backlog_search(): void {
  \Drupal::service('module_installer')->install(['search_api', 'search_api_db']);
  foreach (['server' => Server::class, 'index' => Index::class] as $kind => $class) {
    if (!$class::load('nelkano_backlog')) {
      $path = __DIR__ . '/config/backlog/search_api.' . $kind . '.nelkano_backlog.yml';
      $class::create(Yaml::decode(file_get_contents($path)))->save();
    }
  }
}