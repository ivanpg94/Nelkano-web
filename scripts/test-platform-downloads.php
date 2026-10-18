<?php
/** Process-local catalog QA; never publishes test releases or modifies stored configuration. */
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\nelkano_home\Controller\HomeController;
use Drupal\nelkano_home\Controller\AppUpdatesController;
use Drupal\nelkano_home\Service\ReleaseArtifact;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only');
$override = new class implements ConfigFactoryOverrideInterface {
  public array $rows = [];
  public function loadOverrides($names) { return ['nelkano_home.docs' => ['es' => ['releases_items' => $this->rows], 'en' => ['releases_items' => $this->rows]]]; }
  public function getCacheSuffix() { return 'platform-download-process-qa'; }
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) { return NULL; }
  public function getCacheableMetadata($name) { return (new CacheableMetadata())->setCacheMaxAge(0); }
};
$factory = \Drupal::configFactory(); $original = $factory->getEditable('nelkano_home.docs')->getRawData(); $factory->addOverride($override); $uris = []; $files = []; $checks = 0;
$check = static function($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$home = HomeController::create(\Drupal::getContainer()); $updates = new AppUpdatesController();
try {
  $directory = 'private://nelkano-releases'; \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
  foreach (ReleaseArtifact::PLATFORMS as $platform => [$label, $format, $channel]) {
    $uri = $directory . '/qa-' . bin2hex(random_bytes(10)) . '.' . $format; file_put_contents($uri, 'fixture-' . $platform); $uris[] = $uri; $file = \Drupal\file\Entity\File::create(['uri' => $uri, 'filename' => basename($uri), 'uid' => 1, 'status' => 0]); $file->save(); $files[$platform] = $file;
    $override->rows[] = ['visible' => TRUE, 'platform' => $platform, 'version' => $platform === 'android' ? '3.0.0-beta' : '1.0.0-beta', 'version_code' => '12345', 'apk_file' => $uri, 'changes' => 'Fixture', 'date' => '12/10/2026'];
  }
  $installerUri = $directory . '/qa-sdk-installer-' . bin2hex(random_bytes(10)) . '.msi';
  file_put_contents($installerUri, 'fixture-sdk-msi'); $uris[] = $installerUri;
  $installerFile = \Drupal\file\Entity\File::create(['uri' => $installerUri, 'filename' => basename($installerUri), 'uid' => 1, 'status' => 0]);
  $installerFile->save(); $files['sdk-installer'] = $installerFile;
  foreach ($override->rows as &$row) if ($row['platform'] === 'sdk') $row['installer_file'] = $installerUri;
  unset($row);
  $factory->reset('nelkano_home.docs');
  $html = $home->spanish()->getContent(); $doc = new DOMDocument(); @$doc->loadHTML($html); $xpath = new DOMXPath($doc);
  $check($xpath->query('//*[@data-install-platform]')->length === 3, 'Install picker must have Android/Windows/SteamOS');
  $check(!str_contains($html, 'Descargar SDK'), 'SDK must stay outside the Home picker');
  $check(!empty(ReleaseArtifact::latestPublished('sdk')['url']), 'Published SDK missing from Modules lookup');
  $module_controller = new \Drupal\nelkano_modules\Controller\ModuleController();
  $module_build = $module_controller->catalog();
  $check(!empty($module_build['#sdk_download']['url']), 'Modules page did not receive the SDK download');
  foreach (['es','en'] as $language) {
    foreach ($override->rows as $row) {
      $platform = $row['platform']; \Drupal::request()->query->set('plataforma', $platform);
      $page = ($language === 'es' ? $home->releasesSpanish() : $home->releasesEnglish())->getContent();
      $check(str_contains($page, 'r-release-tabs'), 'Versions platform tabs missing');
      $channel = ReleaseArtifact::PLATFORMS[$platform][2];
      $check(str_contains($page, ReleaseArtifact::PLATFORMS[$platform][0]), 'Platform missing from existing versions page');
      if ($platform === 'sdk') $check(str_contains($page, '/sdk/1.0.0-beta/instalador'), 'Versions SDK card lost the MSI URL');
      if ($platform === 'sdk') {
        $initial = ReleaseArtifact::file($row);
        $check(str_ends_with($initial['filename'], '.msi') && str_contains($initial['url'], '/instalador'), 'Initial SDK link must use dedicated MSI route');
        $check(file_get_contents($initial['path']) === 'fixture-sdk-msi', 'Initial SDK must resolve MSI bytes');
        $check(file_get_contents($home->downloadRelease($language, $row['version'], 'sdk', TRUE)->getFile()->getPathname()) === 'fixture-sdk-msi', 'Dedicated installer controller must serve MSI');
      }
      $download = $home->downloadRelease($language, $row['version'], $platform);
      $check(file_get_contents($download->getFile()->getPathname()) === 'fixture-' . $platform, 'Version collision served wrong platform');
      $manifest = json_decode($updates->latest($channel)->getContent(), TRUE);
      $check($manifest['latest']['version'] === $row['version'] && $manifest['latest']['version_code'] === 12345, 'Updater lost beta/build metadata');
      $check($manifest['latest']['format'] === ReleaseArtifact::PLATFORMS[$platform][1], 'Updater format changed to installer');
      $check($manifest['latest']['sha256'] === hash('sha256', 'fixture-' . $platform), 'Updater checksum mismatch');
      $check(file_get_contents($updates->download($channel, '12345')->getFile()->getPathname()) === 'fixture-' . $platform, 'Updater downloaded wrong platform');
    }
  }
  foreach ($override->rows as &$row) if ($row['platform'] !== 'android') $row['visible'] = FALSE;
  unset($row); $factory->reset('nelkano_home.docs');
  $html = $home->spanish()->getContent(); @$doc->loadHTML($html); $xpath = new DOMXPath($doc);
  $check($xpath->query('//*[@data-install-platform]')->length === 1, 'Hidden platforms leaked into select');
  $check(!str_contains($html, 'Descargar SDK'), 'Hidden SDK button still present');
  foreach ($override->rows as $row) {
    if ($row['platform'] === 'android') continue;
    $channel = ReleaseArtifact::PLATFORMS[$row['platform']][2];
    $check(json_decode($updates->latest($channel)->getContent(), TRUE)['latest'] === NULL, 'Hidden release offered as update');
    try { $home->downloadRelease('es', $row['version'], $row['platform']); throw new RuntimeException('Hidden public download accepted'); } catch (NotFoundHttpException $expected) { $checks++; }
    try { $updates->download($channel, '12345'); throw new RuntimeException('Hidden updater download accepted'); } catch (NotFoundHttpException $expected) { $checks++; }
    $check(nelkano_home_file_download($row['apk_file']) === -1, 'Private file bypass allowed');
    if ($row['platform'] === 'sdk') {
      try { $home->downloadRelease('es', $row['version'], 'sdk', TRUE); throw new RuntimeException('Hidden installer download accepted'); } catch (NotFoundHttpException $expected) { $checks++; }
      $check(nelkano_home_file_download($installerUri) === -1, 'Private installer bypass allowed');
    }
  }
  // Check real admin render arrays and reject wrong extensions without storing a row.
  $admin = \Drupal\nelkano_home\Form\NelkanoDocsSettingsForm::create(\Drupal::getContainer());
  $state = (new FormState())->setUserInput([]); $form = $admin->buildForm([], $state, 'releases');
  $found = ['platforms' => [], 'formats' => []];
  $walk = function($branch) use (&$walk, &$found) {
    if (($branch['#type'] ?? '') === 'details' && in_array($branch['#title'] ?? '', ['Android', 'Windows', 'SteamOS', 'SDK'], TRUE)) $found['platforms'][] = $branch['#title'];
    if (($branch['#type'] ?? '') === 'managed_file' && ($branch['#upload_location'] ?? '') === 'private://nelkano-releases') $found['formats'][] = $branch['#upload_validators']['FileExtension']['extensions'];
    foreach ($branch as $key => $child) if (is_array($child) && !str_starts_with((string) $key, '#')) $walk($child);
  }; $walk($form); $check(count($found['platforms']) === 4 && count(array_unique($found['formats'])) === 3, 'Admin platform groups and format-specific upload fields missing');
  $posted = [];
  foreach ($override->rows as $row) { $row['apk_file'] = [$files[$row['platform']]->id()]; if ($row['platform'] === 'sdk') $row['installer_file'] = [$installerFile->id()]; $posted[$row['platform']]['rows'][] = $row; }
  $state = (new FormState())->setUserInput([])->setValues(['active_language' => 'es', 'page_key' => 'releases', 'es' => ['releases' => ['releases_items' => $posted]]]);
  $trigger = ['#r_section' => 'releases', '#r_keys' => ['releases_items']]; $state->setTriggeringElement($trigger);
  $admin->validateForm($form, $state); $check(!$state->hasAnyErrors(), 'Valid platform rows rejected');
  $admin->submitForm($form, $state);
  $stored = $factory->getEditable('nelkano_home.docs')->getRawData();
  foreach (['es', 'en'] as $language) {
    $check(count($stored[$language]['releases_items']) === 4, 'Admin did not persist four rows');
    foreach ($stored[$language]['releases_items'] as $row) {
      $check(($row['visible'] ? TRUE : FALSE) === ($row['platform'] === 'android'), 'Publication did not synchronize both languages');
      $check($row['apk_file'] === $files[$row['platform']]->getFileUri(), 'Managed artifact URI not saved');
      if ($row['platform'] === 'sdk') $check($row['installer_file'] === $installerUri, 'SDK installer not synchronized across languages');
    }
  }
  $bad = $posted; $bad['windows']['rows'][0]['apk_file'] = [$files['android']->id()];
  $invalid = (new FormState())->setUserInput([])->setValues(['active_language' => 'es', 'es' => ['releases' => ['releases_items' => $bad]]]);
  $admin->validateForm($form, $invalid); $check($invalid->hasAnyErrors(), 'APK accepted as Windows MSI');
  $badInstaller = $posted; $badInstaller['sdk']['rows'][0]['installer_file'] = [$files['sdk']->id()];
  $invalidInstaller = (new FormState())->setUserInput([])->setValues(['active_language' => 'es', 'es' => ['releases' => ['releases_items' => $badInstaller]]]);
  $admin->validateForm($form, $invalidInstaller); $check($invalidInstaller->hasAnyErrors(), 'ZIP accepted as SDK installer');
  echo 'PLATFORM_DOWNLOADS_QA_PASS: ' . $checks . " checks; process-local only.\n";
} finally { $factory->getEditable('nelkano_home.docs')->setData($original)->save(); foreach ($files as $file) $file->delete(); foreach ($uris as $uri) if (is_file($uri)) unlink($uri); $factory->reset('nelkano_home.docs'); }
