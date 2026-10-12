<?php

// Run with drush php:script inside the local Docker site. No release is published.
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\nelkano_home\Controller\AppUpdatesController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (PHP_SAPI !== 'cli' || getenv('DRUPAL_DB_NAME') !== 'nelkano_drupal' || getenv('DRUPAL_DB_HOST') !== 'database') {
  throw new RuntimeException('QA requires the local Docker site.');
}
$override = new class implements ConfigFactoryOverrideInterface {
  public array $data = [];
  public function loadOverrides($names) { return ['nelkano_home.app_updates' => $this->data, 'nelkano_home.docs' => ['es' => ['releases_items' => array_fill(0, 100, ['version_code' => '0'])]]]; }
  public function getCacheSuffix() { return 'app-updates-process-only-qa'; }
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) { return NULL; }
  public function getCacheableMetadata($name) { return (new CacheableMetadata())->setCacheMaxAge(0); }
};
$factory = \Drupal::configFactory();
$factory->addOverride($override);
$files = [];
$filesystem = \Drupal::service('file_system');
$controller = new AppUpdatesController();
function app_updates_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
try {
  foreach (AppUpdatesController::PLATFORMS as $platform => [$app, $extension]) {
    $directory = 'private://app-updates/' . $platform;
    app_updates_assert($filesystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY), 'Cannot prepare fixture directory');
    $uri = $directory . '/qa-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $bytes = 'immutable QA fixture ' . $platform;
    file_put_contents($uri, $bytes);
    $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'uid' => 1, 'status' => 0, 'filesize' => strlen($bytes), 'filemime' => 'application/octet-stream']);
    $file->save(); $files[] = $file;
    $override->data['active'][$platform] = 1000001;
    $override->data['releases'][$platform][1000001] = ['version' => '0.4.4', 'file_id' => (int) $file->id(), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'notes' => 'Fixture'];
    $factory->reset('nelkano_home.app_updates');
    $manifest = json_decode($controller->latest($platform)->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    app_updates_assert($manifest['latest']['app_id'] === $app && $manifest['latest']['format'] === $extension, 'Wrong platform identity');
    app_updates_assert($manifest['latest']['sha256'] === hash('sha256', $bytes) && $manifest['latest']['bytes'] === strlen($bytes), 'Wrong integrity metadata');
    $download = $controller->download($platform, '1000001');
    app_updates_assert(file_get_contents($download->getFile()->getPathname()) === $bytes, 'Downloaded bytes changed');
    // An already issued download URL remains valid after the active pointer changes.
    $override->data['active'][$platform] = 1000002;
    $factory->reset('nelkano_home.app_updates');
    app_updates_assert(json_decode($controller->latest($platform)->getContent(), TRUE)['latest'] === NULL, 'Missing release must not be offered');
    app_updates_assert(file_get_contents($controller->download($platform, '1000001')->getFile()->getPathname()) === $bytes, 'Previous download URL disappeared');
    try { $controller->download($platform, '999'); throw new RuntimeException('Unknown build accepted'); }
    catch (NotFoundHttpException $expected) {}
    echo $platform . ": identity, checksum, bytes, missing release and history PASS\n";
  }
  echo "APP_UPDATES_WEB_QA_PASS: process-local configuration only; no publication.\n";
}
finally {
  foreach ($files as $file) $file->delete();
  $factory->reset('nelkano_home.app_updates');
}
