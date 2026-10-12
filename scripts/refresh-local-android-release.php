<?php
/** Refresh the explicitly approved, not-yet-deployed Android beta in local Docker. */
use Drupal\file\Entity\File;
if (PHP_SAPI !== 'cli' || getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local preparation only');
$metadata = json_decode(file_get_contents(dirname(__DIR__) . '/release-artifacts/android-3.0.1-beta.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$expectedOld = getenv('NELKANO_REPLACED_ANDROID_SHA256');
if (!preg_match('/^[a-f0-9]{64}$/D', $expectedOld ?: '') || $metadata['version'] !== '3.0.1-beta' || $metadata['versionCode'] !== 11) throw new RuntimeException('Explicit previous hash and build 11 required');
$name = 'Nelkano 3.0.1-beta.apk'; $uri = 'private://nelkano-releases/' . $name;
$source = dirname(__DIR__) . '/release-artifacts/' . $name;
if (!hash_equals($metadata['sha256'], hash_file('sha256', $source)) || filesize($source) !== $metadata['bytes']) throw new RuntimeException('Unverified APK');
$current = hash_file('sha256', $uri);
if (!hash_equals($expectedOld, $current) && !hash_equals($metadata['sha256'], $current)) throw new RuntimeException('Local APK differs from the approved previous build');
$config = \Drupal::configFactory()->getEditable('nelkano_home.docs');
$add = ['es' => 'Los controles táctiles se ocultan al usar el mando y reaparecen al tocar la pantalla.', 'en' => 'Touch controls hide when using the controller and reappear when touching the screen.'];
$notes = []; $changed = [];
foreach (['es', 'en'] as $lang) {
  $rows = $config->get($lang . '.releases_items'); $found = 0;
  foreach ($rows as &$row) {
    if (($row['platform'] ?? 'android') !== 'android' || $row['version'] !== '3.0.1-beta') continue;
    if ((int) $row['version_code'] !== 11 || $row['apk_file'] !== $uri) throw new RuntimeException('Unexpected local release identity');
    if (!str_contains($row['changes'], $add[$lang])) $row['changes'] = rtrim($row['changes']) . "\n" . $add[$lang];
    $notes[$lang] = $row['changes']; $found++;
  }
  unset($row);
  if ($found !== 1) throw new RuntimeException('Expected exactly one release per language');
  $changed[$lang] = $rows;
}
if ($current !== $metadata['sha256']) {
  $stage = $uri . '.approved-refresh';
  if (!copy($source, $stage) || !hash_equals($metadata['sha256'], hash_file('sha256', $stage)) || !rename($stage, $uri)) throw new RuntimeException('Local APK replacement failed');
}
$files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
$file = reset($files); if (!$file) throw new RuntimeException('Managed release file is missing');
$file->setSize($metadata['bytes']); $file->save();
foreach ($changed as $lang => $rows) $config->set($lang . '.releases_items', $rows);
$config->save();
file_put_contents('/tmp/nelkano-301-notes.json', json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Updated local APK and appended one note; existing text preserved.\n";
