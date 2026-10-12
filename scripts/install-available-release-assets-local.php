<?php
/** Local-only preparation while other platform builds run. Full installer remains the deployment preflight. */
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only');
$manifest = json_decode(file_get_contents('/opt/drupal/release-artifacts/manifest.json'), TRUE, flags: JSON_THROW_ON_ERROR);
foreach (\Drupal::config('nelkano_home.docs')->get('es.releases_items') as $row) {
  $uri = $row['apk_file'] ?? ''; if (!str_starts_with($uri, 'private://nelkano-releases/')) continue;
  $name = basename($uri); $source = '/opt/drupal/release-artifacts/' . $name;
  if (!is_file($source)) continue;
  if (!hash_equals($manifest[$name], hash_file('sha256', $source))) throw new RuntimeException('Corrupt source artifact');
  if (is_file($uri) && !hash_equals($manifest[$name], hash_file('sha256', $uri))) throw new RuntimeException('Refusing to overwrite existing artifact');
  $directory = dirname($uri); \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
  if (!is_file($uri)) copy($source, $uri);
  $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  $file = reset($files) ?: File::create(['uri' => $uri, 'uid' => 1]);
  $file->setFilename($name); $file->setMimeType(str_ends_with($name,'.apk') ? 'application/vnd.android.package-archive' : 'application/octet-stream');
  $file->setSize(filesize($uri)); $file->setPermanent(); $file->save(); echo 'Prepared: ' . $name . PHP_EOL;
}
