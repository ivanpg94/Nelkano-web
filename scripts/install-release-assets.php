<?php
/** Install versioned release assets before config import; never publish a release. */
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

$root = dirname(__DIR__);
$directory = $root . '/release-artifacts';
$manifest = json_decode(file_get_contents($directory . '/manifest.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$docs = Yaml::decode(file_get_contents($root . '/config/sync/nelkano_home.docs.yml'));
$required = [];
foreach (['es', 'en'] as $lang) {
  foreach ($docs[$lang]['releases_items'] ?? [] as $row) {
    $uri = trim($row['apk_file'] ?? '');
    if ($uri === '') continue;
    $name = basename($uri);
    if ($uri !== 'public://nelkano-releases/' . $name || !str_ends_with($name, '.apk')) {
      throw new RuntimeException('Unexpected release URI: ' . $uri);
    }
    $required[$name] = $uri;
  }
}
// Validate the whole bundle before writing anything. Missing/corrupt assets stop deployment.
foreach ($required as $name => $uri) {
  $source = $directory . '/' . $name;
  $expected = $manifest[$name] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !is_file($source) || !hash_equals($expected, hash_file('sha256', $source))) {
    throw new RuntimeException('Missing or corrupt release artifact: ' . $name);
  }
  if (is_file($uri) && !hash_equals($expected, hash_file('sha256', $uri))) {
    throw new RuntimeException('Refusing to overwrite a different existing release: ' . $name);
  }
}
if (getenv('NELKANO_RELEASE_ASSETS_CHECK_ONLY') === '1') {
  echo 'Release asset preflight OK: ' . count($required) . PHP_EOL;
  return;
}
$destination = 'public://nelkano-releases';
if (!\Drupal::service('file_system')->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) {
  throw new RuntimeException('Cannot create release directory');
}
foreach ($required as $name => $uri) {
  if (!is_file($uri) && !copy($directory . '/' . $name, $uri)) throw new RuntimeException('Copy failed: ' . $name);
  if (!hash_equals($manifest[$name], hash_file('sha256', $uri))) throw new RuntimeException('Copied hash mismatch');
  $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  $file = reset($files) ?: File::create(['uri' => $uri, 'uid' => 1]);
  $file->setFilename($name);
  $file->setMimeType('application/vnd.android.package-archive');
  $file->setSize(filesize($uri));
  $file->setPermanent();
  $file->save();
  echo 'Verified release asset: ' . $name . PHP_EOL;
}