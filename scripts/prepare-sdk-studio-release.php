<?php
/** Prepare the first local Studio installer release; production uses install-release-assets.php. */
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only');
$directory = rtrim((string) getenv('NELKANO_SDK_RELEASE_DIR'), '/');
if ($directory === '' || !is_dir($directory) || is_link($directory)) throw new RuntimeException('Set NELKANO_SDK_RELEASE_DIR outside the public web root');
$sourceDirectory = realpath($directory); $publicDirectory = realpath(DRUPAL_ROOT);
if (!$sourceDirectory || !$publicDirectory || $sourceDirectory === $publicDirectory || str_starts_with($sourceDirectory, $publicDirectory . DIRECTORY_SEPARATOR)) throw new RuntimeException('Release inputs must be outside the public web root');
$version = '1.0.0-beta'; $code = '2';
$names = ['apk_file' => 'Nelkano-SDK-Studio-' . $version . '-windows-x86_64.zip', 'installer_file' => 'Nelkano-SDK-Studio-' . $version . '-windows-x86_64.msi'];
$manifest = json_decode(file_get_contents($directory . '/manifest.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$artifacts = [];
foreach ($names as $field => $name) {
  $source = $directory . '/' . $name; $uri = 'private://nelkano-releases/' . $name; $expected = $manifest[$name] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !is_file($source) || is_link($source) || !hash_equals($expected, hash_file('sha256', $source))) throw new RuntimeException('Missing or corrupt Studio artifact: ' . $name);
  if (is_file($uri) && !hash_equals($expected, hash_file('sha256', $uri))) throw new RuntimeException('Refusing to overwrite a different Studio artifact: ' . $name);
  $artifacts[$field] = compact('source', 'uri', 'expected', 'name');
}
$config = \Drupal::configFactory()->getEditable('nelkano_home.docs');
foreach (['es', 'en'] as $lang) {
  foreach ($config->get($lang . '.releases_items') ?? [] as $row) {
    if (($row['platform'] ?? '') !== 'sdk' || ($row['version'] ?? '') !== $version) continue;
    // A published update identity cannot change its bytes. The unpublished beta
    // placeholder may be replaced for its first publication, without a code bump.
    if (\Drupal\nelkano_home\Service\ReleaseArtifact::hidden($row)) continue;
    foreach ($artifacts as $field => $artifact) {
      if (!empty($row[$field]) && (!is_file($row[$field]) || !hash_equals($artifact['expected'], hash_file('sha256', $row[$field])))) throw new RuntimeException('Published Studio release identity already exists with different bytes');
    }
  }
}
if (getenv('NELKANO_SDK_RELEASE_APPLY') !== '1') { echo "Studio installer preflight OK: MSI and ZIP verified, no writes.\n"; return; }
$fs = \Drupal::service('file_system');
if (!$fs->prepareDirectory('private://nelkano-releases', FileSystemInterface::CREATE_DIRECTORY)) throw new RuntimeException('Cannot create private release directory');
foreach ($artifacts as $artifact) {
  if (!is_file($artifact['uri']) && !copy($artifact['source'], $artifact['uri'])) throw new RuntimeException('Cannot copy Studio artifact');
  if (!hash_equals($artifact['expected'], hash_file('sha256', $artifact['uri']))) throw new RuntimeException('Copied Studio hash mismatch');
  $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $artifact['uri']]);
  $file = reset($files) ?: File::create(['uri' => $artifact['uri'], 'uid' => 1]);
  $file->setFilename($artifact['name']); $file->setMimeType(str_ends_with($artifact['name'], '.zip') ? 'application/zip' : 'application/octet-stream');
  $file->setSize(filesize($artifact['uri'])); $file->setPermanent(); $file->save();
}
foreach (['es', 'en'] as $lang) {
  $rows = $config->get($lang . '.releases_items') ?? [];
  $notes = $lang === 'es' ? "Primera beta de SDK Studio para Windows.\nInstalador con Node.js y Java incluidos.\nEditor, visor, exportación firmada y chat con referencia del SDK."
    : "First SDK Studio beta for Windows.\nInstaller with Node.js and Java included.\nEditor, preview, signed exports and chat with SDK reference.";
  $release = ['visible' => TRUE, 'platform' => 'sdk', 'version_code' => $code, 'version' => $version,
    'apk_file' => $artifacts['apk_file']['uri'], 'installer_file' => $artifacts['installer_file']['uri'],
    'filename' => $names['installer_file'], 'date' => '18/10/2026', 'changes' => $notes];
  $found = FALSE;
  foreach ($rows as &$row) if (($row['platform'] ?? '') === 'sdk' && ($row['version'] ?? '') === $version) { $row = $release; $found = TRUE; }
  unset($row); if (!$found) $rows[] = $release;
  $config->set($lang . '.releases_items', $rows);
}
$config->save();
$target = dirname(DRUPAL_ROOT) . '/config/sync/nelkano_home.docs.yml';
if (is_link($target) || file_put_contents($target, Yaml::encode($config->getRawData())) === FALSE) throw new RuntimeException('Cannot export release configuration');
echo "Studio 1.0.0-beta (2) prepared locally: MSI initial download, ZIP updater; configuration exported.\n";
