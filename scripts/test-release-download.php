<?php
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\nelkano_home\Controller\HomeController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
$fixture = 'temporary://release-hotfix-' . bin2hex(random_bytes(8)) . '_1.apk';
file_put_contents($fixture, 'replacement-apk-test');
$override = new class($fixture) implements ConfigFactoryOverrideInterface {
  public array $row;
  public function __construct($uri) { $this->row = ['version'=>'2.0.0-beta','visible'=>TRUE,'apk_file'=>$uri]; }
  public function loadOverrides($names) { return ['nelkano_home.docs'=>['es'=>['releases_items'=>[$this->row]],'en'=>['releases_items'=>[$this->row]]]]; }
  public function getCacheSuffix() { return 'release-download-hotfix-test'; }
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) { return NULL; }
  public function getCacheableMetadata($name) { return (new CacheableMetadata())->setCacheMaxAge(0); }
};
$factory = \Drupal::configFactory();
$factory->addOverride($override);
function hotfixCheck($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
try {
  foreach (['es','en'] as $lang) {
    $factory->reset('nelkano_home.docs');
    $controller = HomeController::create(\Drupal::getContainer());
    $response = $controller->downloadRelease($lang, '2.0.0-beta');
    hotfixCheck(str_contains($response->headers->get('Content-Disposition'), 'Nelkano 2.0.0-beta.apk'), "$lang stable public filename despite _1 storage name");
    hotfixCheck(file_get_contents($response->getFile()->getPathname()) === 'replacement-apk-test', "$lang serves selected replacement, not original");
    hotfixCheck(str_contains($response->headers->get('Cache-Control'),'no-store'), "$lang replacement is not cached");
  }
  $override->row['visible'] = FALSE;
  $factory->reset('nelkano_home.docs');
  $hidden = FALSE;
  try { $controller->downloadRelease('es','2.0.0-beta'); } catch (NotFoundHttpException $e) { $hidden = TRUE; }
  hotfixCheck($hidden, 'hidden release rejected');
  $override->row['visible'] = TRUE;
  unlink($fixture);
  $factory->reset('nelkano_home.docs');
  $missing = FALSE;
  try { $controller->downloadRelease('es','2.0.0-beta'); } catch (NotFoundHttpException $e) { $missing = TRUE; }
  hotfixCheck($missing, 'missing artifact rejected');
} finally {
  if (is_file($fixture)) unlink($fixture);
}