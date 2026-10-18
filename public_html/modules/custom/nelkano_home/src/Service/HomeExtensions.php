<?php

namespace Drupal\nelkano_home\Service;

use Drupal\Component\Serialization\Yaml;

/** Editable discovery section and the one-time SDK entry publication. */
final class HomeExtensions {

  public const SDK_ROOT = 'de520020-2026-4000-8000-000000000001';

  /** Defaults come from the same configuration shipped to fresh installations. */
  public static function defaults(): array {
    $seed = Yaml::decode(file_get_contents(dirname(__DIR__, 2) . '/config/install/nelkano_home.settings.yml'));
    $defaults = [];
    foreach (['es', 'en'] as $language) {
      $defaults[$language] = array_filter($seed[$language] ?? [], static fn(string $key): bool => str_starts_with($key, 'extensions_'), ARRAY_FILTER_USE_KEY);
    }
    return $defaults;
  }

  /** Add only missing fields; preserve unrelated copy, illustrations and settings. */
  public static function install(): void {
    $config = \Drupal::configFactory()->getEditable('nelkano_home.settings');
    $changed = FALSE;
    foreach (self::defaults() as $language => $values) {
      foreach ($values as $key => $value) {
        if ($config->get($language . '.' . $key) === NULL) {
          $config->set($language . '.' . $key, $value);
          $changed = TRUE;
        }
      }
      $rows = $config->get($language . '.feature_items');
      if (is_array($rows)) {
        $retained = array_values(array_filter($rows, static fn(array $row): bool => !in_array($row['title'] ?? '', ['Módulos y SDK', 'Modules and SDK'], TRUE)));
        if ($retained !== $rows) {
          $config->set($language . '.feature_items', $retained);
          $changed = TRUE;
        }
      }
    }
    if ($changed) {
      $config->save();
    }
    self::publishSdkEntry();
  }

  /** Publish only the existing Spanish root; never publish chapters or drafts. */
  private static function publishSdkEntry(): void {
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'uuid' => self::SDK_ROOT,
      'type' => 'sdk_page',
    ]);
    $root = reset($nodes);
    // Deployment imports the canonical Book by UUID after configuration/updates.
    if (!$root || !$root->hasTranslation('es')) {
      return;
    }
    if ((int) ($root->getBook()['bid'] ?? 0) !== (int) $root->id()) {
      throw new \Drupal\Core\Utility\UpdateException('El UUID del SDK no identifica una portada de Book. Revisa su jerarquía antes de publicar.');
    }
    $spanish = $root->getTranslation('es');
    if (!$spanish->isPublished()) {
      $spanish->setPublished();
      $spanish->setNewRevision(TRUE);
      $spanish->setRevisionTranslationAffected(TRUE);
      $spanish->setRevisionLogMessage('Publicar la portada SDK para la navegación y el bloque Amplía Nelkano.');
      $spanish->save();
    }
  }
}
