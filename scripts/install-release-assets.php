<?php
/** Install release assets before config import; never change publication. */
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

$root = dirname(__DIR__);
$directory = getenv('NELKANO_RELEASE_ARTIFACTS_DIR') ?: $root . '/release-artifacts';
$manifest = json_decode(file_get_contents($directory . '/manifest.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$docs = Yaml::decode(file_get_contents($root . '/config/sync/nelkano_home.docs.yml'));
$required = [];
foreach (['es', 'en'] as $lang) {
  foreach ($docs[$lang]['releases_items'] ?? [] as $row) {
    $uri = trim($row['apk_file'] ?? '');
    if ($uri === '') continue;
    $name = basename($uri);
    if (!in_array($uri, ['public://nelkano-releases/' . $name, 'private://nelkano-releases/' . $name], TRUE) || !preg_match('/\.(apk|msi|zip)$/D', $name)) throw new RuntimeException('Unexpected release URI: ' . $uri);
    $visible = $row['visible'] ?? TRUE;
    $published = $visible !== FALSE && !in_array(strtolower(trim((string) $visible)), ['0', 'false', 'no', 'off'], TRUE);
    $required[$name] = ['uri' => $uri, 'published' => $published || ($required[$name]['published'] ?? FALSE)];
  }
}
$install = [];
// Validate the full published set before writing anything. Hidden platforms can
// be provisioned later and must not block the current Android delivery.
foreach ($required as $name => $entry) {
  $uri = $entry['uri'];
  $source = $directory . '/' . $name;
  $expected = $manifest[$name] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !is_file($source) || !hash_equals($expected, hash_file('sha256', $source))) {
    if (!$entry['published']) { echo 'Skipped unpublished artifact without a verified source: ' . $name . PHP_EOL; continue; }
    throw new RuntimeException('Missing or corrupt published release artifact: ' . $name);
  }
  if (str_starts_with($uri, 'private://') && !\Drupal::service('stream_wrapper_manager')->isValidScheme('private')) {
    if (!$entry['published']) { echo 'Skipped unpublished private artifact: ' . $name . PHP_EOL; continue; }
    throw new RuntimeException('Private files directory is not configured. Set $settings["file_private_path"] in production settings.php to a writable directory outside public_html, then rebuild caches.');
  }
  if (is_file($uri) && !hash_equals($expected, hash_file('sha256', $uri))) throw new RuntimeException('Refusing to overwrite a different existing release: ' . $name);
  $install[$name] = $uri;
}
if (getenv('NELKANO_RELEASE_ASSETS_CHECK_ONLY') === '1') { echo 'Release asset preflight OK: ' . count($install) . PHP_EOL; return; }
foreach ($install as $name => $uri) {
  $destination = dirname($uri);
  if (!\Drupal::service('file_system')->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) throw new RuntimeException('Cannot create release directory');
  if (!is_file($uri) && !copy($directory . '/' . $name, $uri)) throw new RuntimeException('Copy failed: ' . $name);
  if (!hash_equals($manifest[$name], hash_file('sha256', $uri))) throw new RuntimeException('Copied hash mismatch');
  $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  $file = reset($files) ?: File::create(['uri' => $uri, 'uid' => 1]);
  $file->setFilename($name);
  $file->setMimeType(str_ends_with($name, '.apk') ? 'application/vnd.android.package-archive' : (str_ends_with($name, '.zip') ? 'application/zip' : 'application/octet-stream'));
  $file->setSize(filesize($uri)); $file->setPermanent(); $file->save();
  echo 'Verified release asset: ' . $name . PHP_EOL;
}