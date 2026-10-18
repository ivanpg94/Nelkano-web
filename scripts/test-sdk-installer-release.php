<?php
/** Read-only public SDK release smoke and transport checks on local Docker. */
use Drupal\Component\Serialization\Yaml;
use Drupal\nelkano_home\Controller\AppUpdatesController;
use Drupal\nelkano_home\Controller\HomeController;
use Drupal\nelkano_home\Service\ReleaseArtifact;
if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$config = \Drupal::config('nelkano_home.docs')->getRawData(); $beforeConfig = serialize($config);
$home = HomeController::create(\Drupal::getContainer()); $updates = new AppUpdatesController();
$manifest = json_decode($updates->latest('sdk-studio-windows-x86_64')->getContent(), TRUE)['latest'];
foreach (['es', 'en'] as $language) {
  $sdkRows = array_values(array_filter($config[$language]['releases_items'], static fn($row) => ($row['platform'] ?? '') === 'sdk' && ($row['version'] ?? '') === '1.0.0-beta'));
  $assert(count($sdkRows) === 1 && !ReleaseArtifact::hidden($sdkRows[0]), 'Missing published SDK beta in ' . $language);
  $row = $sdkRows[0]; $initial = ReleaseArtifact::file($row); $update = ReleaseArtifact::file($row, 'update');
  $assert(($row['version_code'] ?? '') === '2', 'SDK build code changed');
  $assert(str_ends_with($initial['filename'], '.msi') && str_contains($initial['url'], '/instalador'), 'Initial download is not the dedicated MSI');
  $assert(file_get_contents($initial['path'], FALSE, NULL, 0, 8) === hex2bin('d0cf11e0a1b11ae1'), 'Installer is not a Windows Installer compound file');
  $assert(str_ends_with($update['filename'], '.zip') && file_get_contents($update['path'], FALSE, NULL, 0, 2) === 'PK', 'Updater is not the Studio ZIP');
  $assert($home->downloadRelease($language, '1.0.0-beta', 'sdk', TRUE)->getFile()->getPathname() === $initial['path'], 'Installer controller served another artifact');
  $assert($home->downloadRelease($language, '1.0.0-beta', 'sdk')->getFile()->getPathname() === $update['path'], 'Legacy SDK ZIP download changed');
  $assert($manifest['format'] === 'zip' && $manifest['version_code'] === 2 && $manifest['sha256'] === hash_file('sha256', $update['path']) && $manifest['bytes'] === filesize($update['path']), 'Update manifest lost ZIP identity');
  $assert($updates->download('sdk-studio-windows-x86_64', '2')->getFile()->getPathname() === $update['path'], 'Update download is not ZIP');
}
$catalog = (new \Drupal\nelkano_modules\Controller\ModuleController())->catalog();
$assert(str_contains($catalog['#sdk_download']['url'] ?? '', '/instalador'), 'Modules page does not offer the SDK installer');
$transfer = \Drupal::service('book_import_export.transfer'); $bookFile = $transfer->read(dirname(DRUPAL_ROOT) . '/content/books/nelkano-sdk.yml');
$assert($transfer->export($bookFile['book']) == $bookFile, 'Book YAML differs from local Drupal');
$beforePath = getenv('NELKANO_SDK_BOOK_BEFORE');
if ($beforePath) {
  $beforeBook = $transfer->read($beforePath); $beforePages = array_column($beforeBook['pages'], NULL, 'uuid');
  $assert(count($beforePages) === count($bookFile['pages']), 'Installer changes removed book pages');
  foreach ($bookFile['pages'] as $page) {
    $old = $beforePages[$page['uuid']] ?? []; $oldMeta = $old; $newMeta = $page;
    foreach ($newMeta['translations'] as $lang => &$translation) unset($translation['body']); unset($translation);
    foreach ($oldMeta['translations'] as $lang => &$translation) unset($translation['body']); unset($translation);
    $assert($newMeta === $oldMeta, 'Book metadata or private publication changed');
    foreach ($page['translations'] as $lang => $translation) {
      $oldBody = $old['translations'][$lang]['body']['value']; $newBody = $translation['body']['value'];
      preg_match_all('/<img\b[^>]*>/', $oldBody, $oldImages); preg_match_all('/<img\b[^>]*>/', $newBody, $newImages);
      $assert($oldImages[0] === $newImages[0], 'Book screenshots changed');
      if (!$translation['status']) $assert($oldBody === $newBody, 'Private chapter changed');
    }
  }
}
// Validate the real deployment installer against a temporary transport set.
// Hard links share existing bytes; the script is kept in check-only mode.
$directory = \Drupal::service('file_system')->realpath('private://') . '/sdk-release-qa-' . bin2hex(random_bytes(8));
if (!mkdir($directory)) throw new RuntimeException('Cannot create transport QA directory');
$transport = []; $paths = [];
try {
  $source = Yaml::decode(file_get_contents(dirname(DRUPAL_ROOT) . '/config/sync/nelkano_home.docs.yml'));
  foreach (['es', 'en'] as $language) foreach ($source[$language]['releases_items'] ?? [] as $row) {
    if (ReleaseArtifact::hidden($row)) continue;
    foreach (['apk_file', 'installer_file'] as $field) {
      $uri = $row[$field] ?? ''; if ($uri === '') continue;
      $name = basename($uri); if (isset($transport[$name])) continue;
      $path = \Drupal::service('file_system')->realpath($uri); $destination = $directory . '/' . $name;
      if (!$path || !link($path, $destination)) throw new RuntimeException('Cannot prepare transport QA input');
      $paths[] = $destination; $transport[$name] = hash_file('sha256', $path);
    }
  }
  $manifestPath = $directory . '/manifest.json'; $paths[] = $manifestPath;
  file_put_contents($manifestPath, json_encode($transport, JSON_THROW_ON_ERROR));
  putenv('NELKANO_RELEASE_ARTIFACTS_DIR=' . $directory); putenv('NELKANO_RELEASE_ASSETS_CHECK_ONLY=1');
  require __DIR__ . '/install-release-assets.php'; $checks++;
  $msiName = basename($sdkRows[0]['installer_file']); $transport[$msiName] = str_repeat('0', 64);
  file_put_contents($manifestPath, json_encode($transport, JSON_THROW_ON_ERROR));
  $rejected = FALSE;
  try { require __DIR__ . '/install-release-assets.php'; } catch (RuntimeException $e) { $rejected = str_contains($e->getMessage(), 'Missing or corrupt published release artifact: ' . $msiName); }
  $assert($rejected, 'Deployment accepted a corrupt MSI');
} finally {
  putenv('NELKANO_RELEASE_ARTIFACTS_DIR'); putenv('NELKANO_RELEASE_ASSETS_CHECK_ONLY');
  foreach ($paths as $path) if (is_file($path)) unlink($path); rmdir($directory);
}
$assert($beforeConfig === serialize(\Drupal::config('nelkano_home.docs')->getRawData()), 'Read-only release checks modified configuration');
echo 'SDK_INSTALLER_RELEASE_QA_PASS: ' . $checks . " checks.\n";
