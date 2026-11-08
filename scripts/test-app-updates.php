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
  public ?array $docs = NULL;
  public function loadOverrides($names) { return ['nelkano_home.app_updates' => $this->data, 'nelkano_home.docs' => $this->docs ?? ['es' => ['releases_items' => array_fill(0, 100, ['version_code' => '0', 'platform'=>'qa-unrelated', 'visible'=>FALSE])]]]; }
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
    // Advancing the active pointer retires all previous download URLs.
    $override->data['active'][$platform] = 1000002;
    $factory->reset('nelkano_home.app_updates');
    app_updates_assert(json_decode($controller->latest($platform)->getContent(), TRUE)['latest'] === NULL, 'Missing release must not be offered');
    try { $controller->download($platform, '1000001'); throw new RuntimeException('Previous download URL remained available'); } catch (NotFoundHttpException $expected) {}
    try { $controller->download($platform, '999'); throw new RuntimeException('Unknown build accepted'); }
    catch (NotFoundHttpException $expected) {}
    echo $platform . ": identity, checksum, bytes, missing release and history PASS\n";
  }
  // The editor's managed-file catalogue is the path used by manual production uploads.
  $catalog = [['platform'=>'sdk','version'=>'3.0.6','version_code'=>16,'visible'=>FALSE]];
  $payloads = [];
  foreach (['android'=>'android-arm64','windows'=>'windows-x86_64','steamos'=>'linux-x86_64'] as $product=>$channel) {
    $extension = AppUpdatesController::PLATFORMS[$channel][1];
    $uri = 'private://nelkano-releases/qa-manual-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $bytes = 'manual 3.0.7 package ' . $channel;
    file_put_contents($uri, $bytes);
    $file = File::create(['uri'=>$uri,'filename'=>basename($uri),'uid'=>1,'status'=>0,'filesize'=>strlen($bytes),'filemime'=>'application/octet-stream']);
    $file->save(); $files[] = $file;
    $catalog[] = ['platform'=>$product,'version'=>'3.0.7','version_code'=>17,'visible'=>TRUE,'apk_file'=>$uri,'changes'=>'Manual release fixture'];
    $payloads[$channel] = $bytes;
  }
  $override->docs = ['es'=>['releases_items'=>$catalog]];
  $factory->reset('nelkano_home.docs');
  foreach ($payloads as $channel=>$bytes) {
    $latest = json_decode($controller->latest($channel)->getContent(), TRUE)['latest'];
    app_updates_assert($latest['version']==='3.0.7' && $latest['version_code']===17 && $latest['format']===AppUpdatesController::PLATFORMS[$channel][1], 'Manual upload identity mismatch: ' . $channel);
    app_updates_assert($latest['bytes']===strlen($bytes) && $latest['sha256']===hash('sha256',$bytes), 'Manual upload integrity mismatch');
    app_updates_assert(file_get_contents($controller->download($channel,'17')->getFile()->getPathname())===$bytes, 'Manual download mismatch');
  }
  app_updates_assert(json_decode($controller->latest('sdk-studio-windows-x86_64')->getContent(), TRUE)['latest']===NULL, 'Hidden SDK fell back to a former channel');
  try { $controller->download('sdk-studio-windows-x86_64','16'); throw new RuntimeException('Hidden SDK download accepted'); } catch (NotFoundHttpException) {}
  echo "MANUAL_UPLOADS_PASS: Android/Windows/SteamOS 3.0.7/17; SDK hidden.\n";
  echo "APP_UPDATES_WEB_QA_PASS: process-local configuration only; no publication.\n";
}
finally {
  foreach ($files as $file) $file->delete();
  $factory->reset('nelkano_home.app_updates');
}
