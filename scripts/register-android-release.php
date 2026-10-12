<?php
/** Register a verified Android release in local/prepared Drupal configuration. */
use Drupal\file\Entity\File;
if (PHP_SAPI !== 'cli' || getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Drupal preparation only');
$root = dirname(__DIR__);
$metadata = json_decode(file_get_contents(getenv('NELKANO_ANDROID_RELEASE_METADATA') ?: $root . '/release-artifacts/android-3.0.1-beta.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$name = 'Nelkano ' . $metadata['version'] . '.apk';
$source = $root . '/release-artifacts/' . $name;
if (!is_file($source) || !hash_equals($metadata['sha256'], hash_file('sha256', $source)) || filesize($source) !== $metadata['bytes']) throw new RuntimeException('Unverified Android APK');
$uri = 'private://nelkano-releases/' . $name;
if (!\Drupal::service('stream_wrapper_manager')->isValidScheme('private')) throw new RuntimeException('Private files are not configured');
$directory = dirname($uri);
\Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
if (is_file($uri) && !hash_equals($metadata['sha256'], hash_file('sha256', $uri))) throw new RuntimeException('Refusing to change immutable release');
if (!is_file($uri) && !copy($source, $uri)) throw new RuntimeException('Copy failed');
$files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
$file = reset($files) ?: File::create(['uri' => $uri, 'uid' => 1]);
$file->setFilename($name); $file->setMimeType('application/vnd.android.package-archive'); $file->setSize(filesize($uri)); $file->setPermanent(); $file->save();
$config = \Drupal::configFactory()->getEditable('nelkano_home.docs');
foreach (['es', 'en'] as $lang) {
  $rows = $config->get($lang . '.releases_items') ?? [];
  $existing = FALSE;
  foreach ($rows as $row) {
    if (($row['platform'] ?? 'android') !== 'android' || (int) ($row['version_code'] ?? 0) !== $metadata['versionCode']) continue;
    if ($row['version'] !== $metadata['version'] || $row['apk_file'] !== $uri) throw new RuntimeException('Version code already assigned');
    $existing = TRUE;
  }
  if (!$existing) $rows[] = ['visible' => TRUE, 'platform' => 'android', 'version' => $metadata['version'], 'version_code' => (string) $metadata['versionCode'], 'apk_file' => $uri, 'filename' => $name, 'date' => '12/10/2026', 'changes' => $metadata['notes'][$lang]];
  $config->set($lang . '.releases_items', $rows);
}
$config->save();
echo 'Prepared Android ' . $metadata['version'] . ' build ' . $metadata['versionCode'] . PHP_EOL;
