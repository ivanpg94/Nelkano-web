<?php
/** Install release assets before config import; never change publication. */
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

require_once __DIR__ . '/release-file-io.php';
// Hashes/copies on shared hosting can exceed MySQL's idle timeout. Keep the
// existing connection alive during I/O; do not retry or replay database writes.
$lastDatabasePulse=0.0;
$releaseIo=new NelkanoReleaseFileIo(static function() use (&$lastDatabasePulse): void {
  $now=microtime(true);
  if($now-$lastDatabasePulse>=1.0){\Drupal::database()->query('SELECT 1');$lastDatabasePulse=$now;}
});
$root = dirname(__DIR__);
$directory = getenv('NELKANO_RELEASE_ARTIFACTS_DIR') ?: $root . '/release-artifacts';
$manifest = json_decode(file_get_contents($directory . '/manifest.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$docs = Yaml::decode(file_get_contents($root . '/config/sync/nelkano_home.docs.yml'));
$required = [];
foreach (['es', 'en'] as $lang) {
  foreach ($docs[$lang]['releases_items'] ?? [] as $row) {
    foreach (['apk_file', 'installer_file'] as $field) {
      $uri = trim($row[$field] ?? '');
      if ($uri === '') continue;
      $name = basename($uri);
      if (!in_array($uri, ['public://nelkano-releases/' . $name, 'private://nelkano-releases/' . $name], TRUE) || !preg_match('/\.(apk|exe|msi|zip)$/D', $name)) throw new RuntimeException('Unexpected release URI: ' . $uri);
      $visible = $row['visible'] ?? TRUE;
      $published = \Drupal\nelkano_home\Service\ReleaseArtifact::sameRelease($row, \Drupal\nelkano_home\Service\ReleaseArtifact::newest($docs['es']['releases_items'] ?? [], \Drupal\nelkano_home\Service\ReleaseArtifact::platform($row)));
      $required[$name] = ['uri' => $uri, 'published' => $published || ($required[$name]['published'] ?? FALSE)];
    }
  }
}
$repairPath=$directory.'/repair-3.0.5.json';
$repair=is_file($repairPath)?json_decode(file_get_contents($repairPath),TRUE,512,JSON_THROW_ON_ERROR):[];
$replacements=[];
$install = [];
// Validate the full published set before writing anything. Hidden platforms can
// be provisioned later and must not block the current Android delivery.
foreach ($required as $name => $entry) {
  $uri = $entry['uri'];
  $source = $directory . '/' . $name;
  $expected = $manifest[$name] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !is_file($source) || !hash_equals($expected, $releaseIo->digest($source))) {
    if (!$entry['published']) { echo 'Skipped unpublished artifact without a verified source: ' . $name . PHP_EOL; continue; }
    throw new RuntimeException('Missing or corrupt published release artifact: ' . $name);
  }
  if (str_starts_with($uri, 'private://') && !\Drupal::service('stream_wrapper_manager')->isValidScheme('private')) {
    if (!$entry['published']) { echo 'Skipped unpublished private artifact: ' . $name . PHP_EOL; continue; }
    throw new RuntimeException('Private files directory is not configured. Set $settings["file_private_path"] in production settings.php to a writable directory outside public_html, then rebuild caches.');
  }
  if (is_file($uri) && !hash_equals($expected, $releaseIo->digest($uri))) {
    $existing=$releaseIo->digest($uri);
    // The single explicitly requested 3.0.5 repair; all other collisions remain forbidden.
    if($name!=='Nelkano 3.0.5.apk'||($repair['file']??'')!==$name||($repair['version']??'')!=='3.0.5'||($repair['versionCode']??0)!==15
      ||($repair['previous_sha256']??'')!=='1e15c951e36372aefd4280ed4e053d157eb052567bfdbd6a6c1aa7956690517f'
      ||!hash_equals($repair['previous_sha256'],$existing)||!hash_equals($repair['sha256']??'',$expected))throw new RuntimeException('Refusing to overwrite a different existing release: '.$name);
    $replacements[$name]=$existing;
  }
  $install[$name] = $uri;
}
if (getenv('NELKANO_RELEASE_ASSETS_CHECK_ONLY') === '1') { echo 'Release asset preflight OK: ' . count($install) . PHP_EOL; return; }
foreach ($install as $name => $uri) {
  $destination = dirname($uri);
  $realDestination = \Drupal::service('file_system')->realpath($destination);
  if (is_string($realDestination) && is_dir($realDestination)) $destination = $realDestination;
  if (!\Drupal::service('file_system')->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) throw new RuntimeException('Cannot create release directory');
  if(isset($replacements[$name])) {
    $backups='private://nelkano-release-repairs';
    $realBackups=\Drupal::service('file_system')->realpath($backups);
    if(is_string($realBackups)&&is_dir($realBackups))$backups=$realBackups;
    if(!\Drupal::service('file_system')->prepareDirectory($backups,FileSystemInterface::CREATE_DIRECTORY))throw new RuntimeException('Cannot preserve previous release.');
    $backup=$backups.'/'.$name.'.'.$replacements[$name].'.bak';
    if(!is_file($backup)&&!$releaseIo->copy($uri,$backup))throw new RuntimeException('Cannot back up previous release.');
    if(!hash_equals($replacements[$name],$releaseIo->digest($backup)))throw new RuntimeException('Previous release backup mismatch.');
    $temporary=$uri.'.repair-'.bin2hex(random_bytes(6));
    try {if(!$releaseIo->copy($directory.'/'.$name,$temporary)||!hash_equals($manifest[$name],$releaseIo->digest($temporary))||!rename($temporary,$uri))throw new RuntimeException('Verified release repair failed.');}
    finally {if(is_file($temporary))unlink($temporary);}
  }
  clearstatcache(TRUE, $uri);
  if (!is_file($uri) && !$releaseIo->copy($directory . '/' . $name, $uri)) throw new RuntimeException('Copy failed: ' . $name);
  if (!hash_equals($manifest[$name], $releaseIo->digest($uri))) throw new RuntimeException('Copied hash mismatch');
  $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  $file = reset($files) ?: File::create(['uri' => $uri, 'uid' => 1]);
  $file->setFilename($name);
  $file->setMimeType(str_ends_with($name, '.apk') ? 'application/vnd.android.package-archive' : (str_ends_with($name, '.zip') ? 'application/zip' : 'application/octet-stream'));
  $file->setSize(filesize($uri)); $file->setPermanent(); $file->save();
  echo 'Verified release asset: ' . $name . PHP_EOL;
}