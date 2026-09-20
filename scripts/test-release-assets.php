<?php
/** Local release selection and deployment integrity checks; no config writes. */
use Drupal\nelkano_home\Controller\HomeController;
if (getenv('NELKANO_RELEASE_ASSETS_TEST') !== '1') throw new RuntimeException('Local test requires NELKANO_RELEASE_ASSETS_TEST=1');
function releaseCheck(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
  echo 'PASS: ' . $message . PHP_EOL;
}
$controller = HomeController::create(\Drupal::getContainer());
$reflection = new ReflectionClass($controller);
$rowsMethod = $reflection->getMethod('releaseRows');
$latestMethod = $reflection->getMethod('latestReleaseDownload');
$modulePath = \Drupal::service('extension.list.module')->getPath('nelkano_home');
foreach (['es', 'en'] as $lang) {
  $content = \Drupal::config('nelkano_home.docs')->get($lang);
  $latest = $latestMethod->invoke($controller, $lang, $modulePath);
  releaseCheck(str_contains(rawurldecode($latest['url']), '/1.0.0-beta'), $lang . ' home selects latest published APK, excluding draft');
  foreach ($content['releases_items'] as &$row) {
    if ($row['version'] === '2.0.0-beta') $row['visible'] = TRUE;
  }
  unset($row);
  $rows = $rowsMethod->invoke($controller, $content, $modulePath);
  releaseCheck($rows[0]['version'] === '2.0.0-beta' && str_contains(rawurldecode($rows[0]['url']), '/2.0.0-beta'), $lang . ' publishing 2.0 selects its APK regardless of row order');
  $content['releases_items'][] = ['version' => '99.0.0', 'visible' => TRUE, 'apk_file' => 'public://nelkano-releases/missing-test.apk'];
  $rows = $rowsMethod->invoke($controller, $content, $modulePath);
  releaseCheck($rows[0]['url'] === '', $lang . ' missing file never exposes a broken download');
}
$manifestPath = dirname(__DIR__) . '/release-artifacts/manifest.json';
$original = file_get_contents($manifestPath);
putenv('NELKANO_RELEASE_ASSETS_CHECK_ONLY=1');
$installer = __DIR__ . '/install-release-assets.php';
require $installer;
try {
  $bad = json_decode($original, TRUE, 512, JSON_THROW_ON_ERROR);
  $bad['Nelkano 2.0.0-beta.apk'] = str_repeat('0', 64);
  file_put_contents($manifestPath, json_encode($bad, JSON_THROW_ON_ERROR));
  $rejected = FALSE;
  try { require $installer; } catch (RuntimeException $e) { $rejected = str_contains($e->getMessage(), 'corrupt release artifact'); }
  releaseCheck($rejected, 'deployment rejects a corrupt or mismatched APK before import');
} finally {
  file_put_contents($manifestPath, $original);
  putenv('NELKANO_RELEASE_ASSETS_CHECK_ONLY');
}