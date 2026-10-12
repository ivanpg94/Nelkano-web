<?php
/** Explicit catalog initialization for this release; transport files before configuration. */
if (getenv('NELKANO_INSTALL_PLATFORM_CATALOG') !== '1') throw new RuntimeException('Explicit release initialization required');
$rows = json_decode(file_get_contents(dirname(__DIR__) . '/config/multiplatform-releases.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$config = \Drupal::configFactory()->getEditable('nelkano_home.docs');
foreach (['es','en'] as $lang) {
  $existing = $config->get($lang . '.releases_items') ?? [];
  foreach ($existing as &$row) { $row['platform'] = trim($row['platform'] ?? '') ?: 'android'; $row['version_code'] = (string) ($row['version_code'] ?? ''); }
  unset($row);
  foreach ($rows as $new) {
    $existing = array_values(array_filter($existing, static fn($row) => $row['platform'] !== $new['platform'] || $row['version'] !== $new['version']));
    $new['changes'] = $new['changes'][$lang]; $existing[] = $new;
  }
  $config->set($lang . '.releases_items', $existing);
  $config->set($lang . '.releases_intro', $lang === 'es' ? 'Descargas y cambios de Nelkano para Android, Windows y SteamOS, y de SDK Studio. Solo aparecen las versiones publicadas.' : 'Downloads and changes for Nelkano on Android, Windows and SteamOS, and SDK Studio. Only published releases are shown.');
}
$config->save();
echo "Catalog initialized: Android visible; Windows, SteamOS and SDK hidden.\n";
