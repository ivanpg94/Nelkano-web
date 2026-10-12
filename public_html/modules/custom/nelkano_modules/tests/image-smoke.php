<?php
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_MODULE_CATALOG_TEST') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') {
  throw new RuntimeException('Explicit local Docker opt-in required.');
}
require_once __DIR__ . '/ModuleFixtures.php';
use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use NelkanoModuleTests\FakeDownloadClient;
use NelkanoModuleTests\Packages;

$checks = 0;
$check = static function ($ok, $label) use (&$checks) { if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } echo 'PASS: ' . $label . PHP_EOL; $checks++; };
$reject = static function ($test, $label) use ($check) { try { $test(); } catch (InvalidArgumentException $e) { $check(TRUE, $label); return; } throw new RuntimeException('FAIL: ' . $label); };
$prefix = 'moduleimage' . bin2hex(random_bytes(4)); $users = []; $files = []; $nodes = [];
$switcher = \Drupal::service('account_switcher'); $switched = FALSE;
$fake = new FakeDownloadClient(); $registry = new ModuleRegistry($fake, new PackageVerifier());
\Drupal::getContainer()->set('nelkano_modules.registry', $registry);
try {
  foreach (['owner', 'other'] as $name) {
    $u = User::create(['name' => $prefix . $name, 'mail' => $prefix . $name . '@example.invalid', 'status' => 1]); $u->save(); $users[] = $u;
  }
  $switcher->switchTo($users[0]); $switched = TRUE;
  $bytes = file_get_contents(__DIR__ . '/../../nelkano_home/assets/social-card.png');
  $fs = \Drupal::service('file_system'); $directory = 'public://nelkano-modules/images';
  $fs->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $upload = static function ($bytes, $suffix) use (&$files, $directory, $prefix) {
    $file = \Drupal::service('file.repository')->writeData($bytes, $directory . '/' . $prefix . $suffix, FileExists::Error);
    $files[] = (int) $file->id(); $file->setTemporary(); $file->save(); return $file;
  };
  $image = $upload($bytes, '.png');
  $url = 'https://github.com/example/' . $prefix . '/module.nelkmod';
  $fake->responses[$url] = ['status' => 200, 'body' => Packages::make('example.' . $prefix, '1.0.0', sodium_crypto_sign_keypair())];
  $values = ['title' => 'Imagen de prueba', 'description' => 'Ficha de prueba', 'source' => '', 'published' => TRUE, 'image' => (int) $image->id()];
  $node = $registry->saveModule(NULL, NULL, $values, $registry->inspect($url)); $nodes[] = (int) $node->id();
  $check((int) $node->get('field_module_image')->target_id === (int) $image->id(), 'creation stores optional image');
  \Drupal::entityTypeManager()->getStorage('file')->resetCache();
  $check(File::load($image->id())->isPermanent() && !empty(\Drupal::service('file.usage')->listUsage($image)['file']['node'][$node->id()]), 'native image field retains permanent file with usage');
  $html = (string) \Drupal::httpClient()->get('http://127.0.0.1/modulos/' . $node->id())->getBody();
  $check(str_contains($html, 'nm-detail-image') && str_contains($html, 'styles/nelkano_module_detail'), 'public fiche renders image derivative');
  $other = $upload($bytes, '-other.png'); $other->setOwnerId((int) $users[1]->id())->save();
  $reject(fn() => $registry->saveModule($node, (int) $node->getRevisionId(), array_replace($values, ['image' => $other->id()])), 'cannot attach another user image');
  $bad = $upload('<script>bad image</script>', '-bad.png');
  $reject(fn() => $registry->validateImage((int) $bad->id()), 'disguised non-image rejected');
  $large = $upload($bytes . str_repeat('x', 2 * 1024 * 1024), '-large.png');
  $reject(fn() => $registry->validateImage((int) $large->id()), 'oversized upload rejected');
  $svg = $upload('<svg xmlns="http://www.w3.org/2000/svg"></svg>', '.svg');
  $reject(fn() => $registry->validateImage((int) $svg->id()), 'SVG upload rejected');
  $second = $upload($bytes, '-second.png');
  $node = $registry->saveModule($node, (int) $node->getRevisionId(), array_replace($values, ['image' => $second->id()]));
  $check((int) $node->get('field_module_image')->target_id === (int) $second->id(), 'owner can replace image');
  $usage = \Drupal::service('file.usage')->listUsage($image);
  $check(!empty($usage['file']['node'][$node->id()]), 'old image retained for existing revisions');
  $node = $registry->saveModule($node, (int) $node->getRevisionId(), array_replace($values, ['image' => 0]));
  $check($node->get('field_module_image')->isEmpty(), 'owner can remove image');
  $check(count($registry->releases($node)) === 1 && $node->get('field_module_available')->value, 'image changes preserve releases and availability');
  $before = \Drupal::database()->select('node', 'n')->fields('n')->execute()->fetchAll();
  \Drupal::moduleHandler()->loadInclude('nelkano_home', 'install'); nelkano_home_update_11037(); nelkano_home_update_11037();
  $check($before == \Drupal::database()->select('node', 'n')->fields('n')->execute()->fetchAll(), 'image update can repeat without touching existing nodes');
  echo 'SUCCESS: ' . $checks . " image checks.\n";
}
finally {
  if ($switched) { $switcher->switchBack(); }
  foreach ($nodes as $id) { Node::load($id)?->delete(); }
  foreach ($files as $id) { File::load($id)?->delete(); }
  foreach ($users as $u) { $u->delete(); }
}