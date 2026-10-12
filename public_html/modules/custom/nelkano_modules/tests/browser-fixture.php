<?php

if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_MODULE_CATALOG_TEST') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') {
  throw new RuntimeException('Explicit local Docker opt-in required.');
}
require_once __DIR__ . '/ModuleFixtures.php';

use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Drupal\user\Entity\User;
use NelkanoModuleTests\FakeDownloadClient;
use NelkanoModuleTests\Packages;

$state = \Drupal::state(); $key = 'nelkano_modules_browser_fixture';
$fixture = $state->get($key);
if (getenv('NELKANO_MODULE_BROWSER_ACTION') === 'cleanup') {
  if ($fixture) {
    foreach ($fixture['modules'] as $id) { Node::load($id)?->delete(); }
    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    $uploads = $file_storage->getQuery()->accessCheck(FALSE)->condition('uid', $fixture['users'], 'IN')->execute();
    foreach ($file_storage->loadMultiple($uploads) as $file) { $file->delete(); }
    foreach ($fixture['users'] as $id) { User::load($id)?->delete(); }
    $state->delete($key);
  }
  echo "Only synthetic module fixtures removed.\n"; return;
}
if ($fixture) { throw new RuntimeException('Clean previous browser fixture first.'); }
$prefix = 'browsermod' . bin2hex(random_bytes(4));
$fixture = ['modules' => [], 'users' => [], 'prefix' => $prefix];
foreach (['owner', 'other'] as $name) {
  $user = User::create(['name' => $prefix . $name, 'mail' => $prefix . $name . '@example.invalid', 'status' => 1]);
  $user->save(); $fixture['users'][] = (int) $user->id(); $state->set($key, $fixture);
}
$fake = new FakeDownloadClient();
$registry = new ModuleRegistry($fake, new PackageVerifier());
\Drupal::getContainer()->set('nelkano_modules.registry', $registry);
$owner = User::load($fixture['users'][0]);
$switcher = \Drupal::service('account_switcher'); $switcher->switchTo($owner);
$names = ['Colecciones', 'Apariencia nocturna', 'Editor de mandos', 'Biblioteca ordenada', 'Favoritos', 'Panel de guardados', 'Segunda pantalla', 'Amigos', 'Perfiles de juego', 'Sesiones', 'Accesos rápidos', 'Notas de juegos', 'Panel de actividad', 'Capturas', 'Organizador'];
$description = 'Personaliza tu experiencia en Nelkano con herramientas sencillas para organizar y disfrutar tus juegos.';
$pair = sodium_crypto_sign_keypair();
try {
  foreach ($names as $i => $name) {
    $id = 'example.' . $prefix . '.module' . $i;
    $url = 'https://github.com/example/' . $prefix . '/releases/download/v1/module' . $i . '.nelkmod';
    $fake->responses[$url] = ['status' => 200, 'body' => Packages::make($id, '1.0.0', $pair, $i % 2 ? ['jvm-desktop'] : ['android', 'jvm-desktop'])];
    $node = $registry->saveModule(NULL, NULL, ['title' => $name, 'description' => $description . ' ' . $prefix,
      'source' => 'https://github.com/example/' . $prefix, 'published' => TRUE, 'systems' => $i < 13 ? ['gb', 'gba'] : ['nds'], 'notes' => 'Primera versión del módulo.'], $registry->inspect($url));
    $fixture['modules'][] = (int) $node->id(); $state->set($key, $fixture);
    if ($i === 0) {
      $url2 = 'https://github.com/example/' . $prefix . '/releases/download/v2/collections.nelkmod';
      $fake->responses[$url2] = ['status' => 200, 'body' => Packages::make($id, '2.0.0', $pair)];
      $registry->saveVersion($node, NULL, NULL, $registry->inspect($url2), 'Más opciones para organizar la biblioteca.');
    }
  }
  Index::load('nelkano_modules')->indexItems();
}
finally { $switcher->switchBack(); }
$fixture['logins'] = [];
foreach ($fixture['users'] as $uid) {
  $user = User::load($uid); $time = time();
  $fixture['logins'][] = '/user/reset/' . $uid . '/' . $time . '/' . user_pass_rehash($user, $time) . '/login';
}
echo 'FIXTURE:' . json_encode($fixture, JSON_UNESCAPED_SLASHES) . "\n";