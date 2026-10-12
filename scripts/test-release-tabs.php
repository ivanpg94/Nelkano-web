<?php
/** Process-local release-tab QA; does not modify saved configuration. */
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\nelkano_home\Controller\HomeController;
if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only');
$override = new class implements ConfigFactoryOverrideInterface {
  public array $rows = [];
  public function loadOverrides($names) { return ['nelkano_home.docs' => ['es' => ['releases_items' => $this->rows, 'releases_version' => ''], 'en' => ['releases_items' => $this->rows, 'releases_version' => '']]]; }
  public function getCacheSuffix() { return 'release-tabs-process-qa'; }
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) { return NULL; }
  public function getCacheableMetadata($name) { return (new CacheableMetadata())->setCacheMaxAge(0); }
};
$factory = \Drupal::configFactory(); $factory->addOverride($override);
$home = HomeController::create(\Drupal::getContainer()); $checks = 0;
$cases = [
  [[], 'windows', [], NULL],
  [[['platform' => 'android', 'visible' => FALSE, 'version' => '3.0.0-beta']], 'android', [], NULL],
  [[['platform' => 'android', 'visible' => TRUE, 'version' => '3.0.0-beta'], ['platform' => 'windows', 'visible' => FALSE, 'version' => '1.0.0-beta']], 'windows', ['android'], 'android'],
  [[['platform' => 'windows', 'visible' => TRUE, 'version' => '1.0.0-beta']], 'android', ['windows'], 'windows'],
  [[['platform' => 'android', 'visible' => TRUE, 'version' => '3.0.0-beta'], ['platform' => 'sdk', 'visible' => TRUE, 'version' => '1.0.0-beta']], 'sdk', ['android', 'sdk'], 'sdk'],
];
foreach (['es', 'en'] as $language) foreach ($cases as [$rows, $requested, $expected, $active]) {
  $override->rows = array_replace(array_fill(0, 100, ['visible' => FALSE]), $rows); $factory->reset('nelkano_home.docs');
  \Drupal::request()->query->set('plataforma', $requested);
  $html = ($language === 'es' ? $home->releasesSpanish() : $home->releasesEnglish())->getContent();
  $doc = new DOMDocument(); @$doc->loadHTML($html); $xpath = new DOMXPath($doc);
  $tabs = $xpath->query('//nav[contains(@class,"r-release-tabs")]/a'); $actual = [];
  foreach ($tabs as $tab) { parse_str(parse_url($tab->getAttribute('href'), PHP_URL_QUERY), $query); $actual[] = $query['plataforma']; if ($tab->getAttribute('aria-current') === 'true' && $query['plataforma'] !== $active) throw new RuntimeException('Wrong active tab'); }
  if ($actual !== $expected) throw new RuntimeException('Unexpected platform tabs'); $checks++;
  if (($xpath->query('//nav[contains(@class,"r-release-tabs")]')->length > 0) !== (bool) $expected) throw new RuntimeException('Empty tab menu still visible'); $checks++;
  if ($active !== NULL && $xpath->query('//nav[contains(@class,"r-release-tabs")]/a[@aria-current="true"]')->length !== 1) throw new RuntimeException('Missing selected platform'); $checks++;
  if (!$expected && !str_contains($html, $language === 'es' ? 'Todavia no hay versiones publicadas.' : 'No releases have been published yet.')) throw new RuntimeException('Missing empty state'); $checks++;
}
echo "RELEASE_TABS_QA_PASS: $checks checks; ES/EN, empty/hidden releases, available platforms and selection fallback.\n";