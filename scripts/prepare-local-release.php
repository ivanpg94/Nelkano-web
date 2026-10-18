<?php
/** Register a verified release set in local Drupal, without changing historical rows. */
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only');
$planPath = (string) getenv('NELKANO_RELEASE_PLAN');
$directory = realpath(dirname($planPath));
$public = realpath(DRUPAL_ROOT);
if (!$directory || !$public || is_link($planPath) || !is_file($planPath)
  || $directory === $public || str_starts_with($directory, $public . DIRECTORY_SEPARATOR)) {
  throw new RuntimeException('Set NELKANO_RELEASE_PLAN to a release plan outside the public web root');
}
$plan = json_decode(file_get_contents($planPath), TRUE, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents($directory . '/manifest.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$version = $plan['version'] ?? ''; $code = (string) ($plan['version_code'] ?? '');
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $version)
  || !ctype_digit($code) || (int) $code < 1 || (int) $code > 2147483647) throw new RuntimeException('Invalid release identity');
$definitions = ['android' => ['apk_file' => 'apk'], 'windows' => ['apk_file' => 'msi', 'installer_file' => 'exe'], 'sdk' => ['apk_file' => 'zip', 'installer_file' => 'exe']];
$entries = $plan['releases'] ?? [];
if (array_diff(array_keys($entries), array_keys($definitions)) || count($entries) !== 3) throw new RuntimeException('Expected Android, Windows and SDK releases');
$config = \Drupal::configFactory()->getEditable('nelkano_home.docs');
// One explicitly authorized, unpublished production draft may be rebuilt locally.
// Without both the exact identity and previous hashes, collisions remain forbidden.
$replaceIdentity = (string) getenv('NELKANO_REPLACE_LOCAL_DRAFT');
$replaceDraft = $replaceIdentity !== '';
$previousManifest = [];
if ($replaceDraft) {
  if ($replaceIdentity !== '3.0.3-beta:13' || $replaceIdentity !== $version . ':' . $code) throw new RuntimeException('Local draft replacement is not authorized for this identity');
  $previousPath = (string) getenv('NELKANO_RELEASE_PREVIOUS_MANIFEST');
  $previousDirectory = realpath(dirname($previousPath));
  if (!$previousDirectory || !is_file($previousPath) || is_link($previousPath)
    || $previousDirectory === $public || str_starts_with($previousDirectory, $public . DIRECTORY_SEPARATOR)) throw new RuntimeException('Previous release manifest must be outside the public web root');
  $previousManifest = json_decode(file_get_contents($previousPath), TRUE, 512, JSON_THROW_ON_ERROR);
}
$artifacts = [];
foreach ($definitions as $platform => $fields) {
  foreach (['es', 'en'] as $language) if (!is_string($entries[$platform]['notes'][$language] ?? NULL) || trim($entries[$platform]['notes'][$language]) === '') throw new RuntimeException('Missing release notes');
  foreach ($fields as $field => $extension) {
    $name = $entries[$platform][$field] ?? '';
    if ($name === '' && $platform !== 'android') continue;
    if (!is_string($name) || $name === '' || basename($name) !== $name || strpbrk($name, "/\\\r\n") !== FALSE || !str_ends_with($name, '.' . $extension)) throw new RuntimeException('Invalid artifact filename');
    $source = $directory . '/' . $name; $uri = 'private://nelkano-releases/' . $name;
    $sha256 = $manifest[$name] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/D', $sha256) || !is_file($source) || is_link($source) || !hash_equals($sha256, hash_file('sha256', $source))) throw new RuntimeException('Missing or corrupt release: ' . $name);
    $prefix = file_get_contents($source, FALSE, NULL, 0, 8);
    $magic = match ($extension) { 'apk', 'zip' => str_starts_with($prefix, 'PK'), 'exe' => str_starts_with($prefix, 'MZ'), 'msi' => $prefix === hex2bin('d0cf11e0a1b11ae1') };
    if (!$magic) throw new RuntimeException('Artifact bytes do not match its format: ' . $name);
    $previousSha256 = is_file($uri) ? hash_file('sha256', $uri) : '';
    $replacing = $previousSha256 !== '' && !hash_equals($sha256, $previousSha256);
    if ($replacing && (!$replaceDraft || !preg_match('/^[a-f0-9]{64}$/D', $previousManifest[$name] ?? '')
      || !hash_equals($previousManifest[$name], $previousSha256))) throw new RuntimeException('Refusing to overwrite a different artifact: ' . $name);
    $artifacts[$platform][$field] = compact('source', 'uri', 'name', 'sha256', 'extension', 'previousSha256', 'replacing');
  }
  if (empty($artifacts[$platform])) throw new RuntimeException('Each release needs a downloadable artifact');
  foreach (['es', 'en'] as $language) foreach ($config->get($language . '.releases_items') ?? [] as $row) {
    if (($row['platform'] ?? 'android') !== $platform || (($row['version'] ?? '') !== $version && (string) ($row['version_code'] ?? '') !== $code)) continue;
    if (($row['version'] ?? '') !== $version || !in_array((string) ($row['version_code'] ?? ''), ['', $code], TRUE)) throw new RuntimeException('Release identity collision for ' . $platform);
    foreach ($artifacts[$platform] as $field => $artifact) {
      if (empty($row[$field])) continue;
      if (!is_file($row[$field])) throw new RuntimeException('Registered release file is missing: ' . $platform);
      $registeredHash = hash_file('sha256', $row[$field]);
      if (!hash_equals($artifact['sha256'], $registeredHash)
        && (!$artifact['replacing'] || $row[$field] !== $artifact['uri'] || !hash_equals($artifact['previousSha256'], $registeredHash))) throw new RuntimeException('Registered release has different bytes: ' . $platform);
    }
  }
}
$artifactCount = array_sum(array_map('count', $artifacts));
if (getenv('NELKANO_RELEASE_APPLY') !== '1') { echo "Local release preflight OK: $version ($code), $artifactCount artifacts; no writes.\n"; return; }
$fs = \Drupal::service('file_system');
$changed = [];
foreach ($artifacts as $fields) foreach ($fields as $artifact) if ($artifact['replacing']) $changed[] = $artifact;
if ($changed) {
  $privateRoot = $fs->realpath('private://');
  if (!$privateRoot || $privateRoot === $public || str_starts_with($privateRoot, $public . DIRECTORY_SEPARATOR)) throw new RuntimeException('Draft backups must be outside the public web root');
  $backupBase = $privateRoot . '/release-draft-backups';
  if (is_link($backupBase)) throw new RuntimeException('Unsafe draft backup directory');
  $backupPath = $backupBase . '/' . $version . '-' . $code . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
  if (!mkdir($backupPath, 0770, TRUE)) throw new RuntimeException('Cannot create draft backup');
  foreach ($changed as $artifact) {
    $oldPath = $fs->realpath($artifact['uri']); $backupFile = $backupPath . '/' . $artifact['name'];
    if (!$oldPath || (!@link($oldPath, $backupFile) && !copy($oldPath, $backupFile))
      || !hash_equals($artifact['previousSha256'], hash_file('sha256', $backupFile))) throw new RuntimeException('Cannot preserve previous draft bytes');
  }
  if (file_put_contents($backupPath . '/catalog-before.json', json_encode($config->getRawData(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === FALSE
    || file_put_contents($backupPath . '/manifest-before.json', json_encode($previousManifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === FALSE) throw new RuntimeException('Cannot preserve draft metadata');
  echo 'Local draft backup: ' . $backupPath . PHP_EOL;
}
$releaseDirectory = 'private://nelkano-releases';
if (!$fs->prepareDirectory($releaseDirectory, FileSystemInterface::CREATE_DIRECTORY)) throw new RuntimeException('Cannot create private release directory');
foreach ($artifacts as $fields) foreach ($fields as $artifact) {
  if ($artifact['replacing']) {
    $temporary = $artifact['uri'] . '.new-' . bin2hex(random_bytes(8));
    if (!copy($artifact['source'], $temporary) || !hash_equals($artifact['sha256'], hash_file('sha256', $temporary))
      || !rename($temporary, $artifact['uri'])) {
      if (is_file($temporary)) unlink($temporary);
      throw new RuntimeException('Cannot replace local draft artifact');
    }
    clearstatcache();
  }
  elseif (!is_file($artifact['uri']) && !copy($artifact['source'], $artifact['uri'])) throw new RuntimeException('Cannot copy release');
  if (!hash_equals($artifact['sha256'], hash_file('sha256', $artifact['uri']))) throw new RuntimeException('Copied hash mismatch');
  $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $artifact['uri']]);
  $file = reset($files) ?: File::create(['uri' => $artifact['uri'], 'uid' => 1]);
  $file->setFilename($artifact['name']);
  $file->setMimeType(match ($artifact['extension']) { 'apk' => 'application/vnd.android.package-archive', 'zip' => 'application/zip', default => 'application/octet-stream' });
  $file->setSize(filesize($artifact['uri'])); $file->setPermanent(); $file->save();
}
foreach (['es', 'en'] as $language) {
  $rows = $config->get($language . '.releases_items') ?? [];
  foreach ($entries as $platform => $entry) {
    $release = ['visible' => TRUE, 'platform' => $platform, 'version' => $version, 'version_code' => $code,
      'filename' => $entry['installer_file'] ?? $entry['apk_file'],
      'date' => $plan['date'], 'changes' => $entry['notes'][$language]];
    if (isset($artifacts[$platform]['apk_file'])) $release['apk_file'] = $artifacts[$platform]['apk_file']['uri'];
    if (isset($artifacts[$platform]['installer_file'])) $release['installer_file'] = $artifacts[$platform]['installer_file']['uri'];
    $found = FALSE;
    foreach ($rows as &$row) if (($row['platform'] ?? 'android') === $platform && ($row['version'] ?? '') === $version) { $row = $release; $found = TRUE; }
    unset($row); if (!$found) $rows[] = $release;
  }
  $config->set($language . '.releases_items', $rows);
}
$config->save();
$target = dirname(DRUPAL_ROOT) . '/config/sync/nelkano_home.docs.yml';
if (is_link($target) || file_put_contents($target, Yaml::encode($config->getRawData())) === FALSE) throw new RuntimeException('Cannot export release configuration');
echo "Prepared local Android, Windows and SDK $version ($code); historical rows preserved, configuration exported.\n";
