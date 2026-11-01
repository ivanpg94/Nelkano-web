<?php
/** Local fixtures only. Never deletes an existing user's module. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')
  || getenv('NELKANO_LOCAL_MODULE_DELETE_TEST') !== '1'
  || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal'
  || \Drupal::database()->getConnectionOptions()['host'] !== 'database') {
  throw new RuntimeException('Explicit local Docker opt-in required.');
}
require_once '/opt/drupal/web/modules/custom/nelkano_modules/tests/ModuleFixtures.php';
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\file\Entity\File;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\Core\Session\AnonymousUserSession;
use GuzzleHttp\Cookie\CookieJar;
use NelkanoModuleTests\Packages;
$prefix = 'delqa' . bin2hex(random_bytes(4));
$users = []; $nodes = []; $files = []; $roles = []; $switched = FALSE; $checks = 0;
$switch = \Drupal::service('account_switcher');
$check = static function ($value, $label) use (&$checks) { if (!$value) throw new RuntimeException('FAIL: ' . $label); $checks++; echo 'PASS: ' . $label . "\n"; };
$as = static function ($user) use ($switch, &$switched) { if ($switched) $switch->switchBack(); $switch->switchTo($user); $switched = TRUE; };
$registry = \Drupal::service('nelkano_modules.registry');
$key = sodium_crypto_sign_keypair();
$values = static fn($title, $access = 'public', $image = 0) => ['title' => $title, 'description' => 'Disposable deletion QA fixture', 'source' => '', 'published' => TRUE, 'access' => $access, 'systems' => [], 'image' => $image, 'notes' => 'QA'];
$make = static function ($id, $access = 'public', $image = 0, $package = 0) use ($registry, $key, $values, &$nodes, $prefix) {
  $bytes = Packages::zip(Packages::files('example.' . $prefix . $id, '1.0.0', $key));
  $verified = (new PackageVerifier())->verify($bytes) + ['url' => 'https://example.com/' . $prefix . '.nelkmod', 'etag' => '', 'modified' => ''];
  if ($package) $verified = array_merge($verified, ['url' => '', 'package_fid' => $package]);
  $node = $registry->saveModule(NULL, NULL, $values($prefix . $id, $access, $image), $verified);
  $nodes[] = (int) $node->id(); return $node;
};
try {
  $role = Role::create(['id' => $prefix, 'label' => $prefix, 'permissions' => ['administer nelkano modules']]); $role->save(); $roles[] = $role;
  foreach (['owner', 'stranger', 'admin'] as $name) { $user = User::create(['name' => $prefix . $name, 'mail' => $prefix . $name . '@nelkano.test', 'status' => 1]); if ($name === 'admin') $user->addRole($role->id()); $user->save(); $users[$name] = $user; }
  $as($users['owner']); $module = $make('public'); $id = (int) $module->id();
  $releaseIds = array_map(static fn($release) => (int) $release->id(), $registry->releases($module));
  foreach ([new AnonymousUserSession(), $users['stranger']] as $denied) {
    $as($denied);
    try { $registry->deleteModule($module, (int) $module->getRevisionId()); throw new RuntimeException('Unauthorized deletion accepted'); }
    catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) { $check(TRUE, 'unauthorized deletion denied'); }
  }
  $as($users['owner']); $revision = (int) $module->getRevisionId();
  $registry->saveModule($module, $revision, $values($prefix . 'updated'));
  try { $registry->deleteModule($module, $revision); throw new RuntimeException('Stale deletion accepted'); }
  catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) { $check(TRUE, 'changed module revision cannot be deleted from stale confirmation'); }
  $storage = \Drupal::entityTypeManager()->getStorage('node'); $storage->resetCache([$id]); $module = $storage->load($id);
  \Drupal::service('router.builder')->rebuild();
  $jar = new CookieJar(); $http = \Drupal::httpClient();
  $get = static fn($path) => $http->get('http://127.0.0.1' . $path, ['cookies' => $jar, 'http_errors' => FALSE, 'allow_redirects' => FALSE]);
  $login = static function ($user) use ($jar, $get) { $jar->clear(); $time = time(); if ($get('/user/reset/' . $user->id() . '/' . $time . '/' . user_pass_rehash($user, $time) . '/login')->getStatusCode() !== 302) throw new RuntimeException('QA login failed'); };
  $login($users['stranger']); $check($get('/modulos/' . $id . '/eliminar')->getStatusCode() === 403, 'confirmation route denies another owner');
  $login($users['owner']); $page = $get('/modulos/' . $id . '/eliminar'); $html = (string) $page->getBody();
  if ($page->getStatusCode() !== 200 || !str_contains($html, 'Eliminar módulo')) { echo 'CONFIRMATION STATUS: ' . $page->getStatusCode() . ' LOCATION: ' . $page->getHeaderLine('Location') . ' BODY: ' . mb_substr(strip_tags($html), 0, 500) . "\n"; }
  $check($page->getStatusCode() === 200 && str_contains($html, 'Eliminar módulo'), 'owner receives explicit deletion confirmation');
  $check(Node::load($id) !== NULL, 'opening confirmation does not delete');
  $check(str_contains((string) $get('/modulos/' . $id)->getBody(), '/modulos/' . $id . '/eliminar'), 'module detail exposes deletion button');
  $check(str_contains((string) $get('/modulos/mis-modulos')->getBody(), '/modulos/' . $id . '/eliminar'), 'my modules exposes deletion button');
  $dom = new DOMDocument(); @$dom->loadHTML($html); $params = [];
  foreach ($dom->getElementsByTagName('input') as $input) { $name = $input->getAttribute('name'); if ($name !== '') $params[$name] = $input->getAttribute('value'); }
  $post = static fn($data) => $http->post('http://127.0.0.1/modulos/' . $id . '/eliminar', ['cookies' => $jar, 'http_errors' => FALSE, 'allow_redirects' => FALSE, 'form_params' => $data]);
  $bad = $params; $bad['form_token'] = 'invalid'; $post($bad);
  $storage->resetCache([$id]); $check($storage->load($id) !== NULL, 'invalid CSRF token cannot delete');
  // Refresh the confirmation after invalid form submission.
  $html = (string) $get('/modulos/' . $id . '/eliminar')->getBody(); @$dom->loadHTML($html); $params = [];
  foreach ($dom->getElementsByTagName('input') as $input) { $name = $input->getAttribute('name'); if ($name !== '') $params[$name] = $input->getAttribute('value'); }
  $registry->saveModule($module, (int) $module->getRevisionId(), $values($prefix . 'changed-after-review'));
  $post($params); $storage->resetCache();
  $check($storage->load($id) !== NULL, 'HTTP stale confirmation does not delete a changed module');
  $html = (string) $get('/modulos/' . $id . '/eliminar')->getBody(); @$dom->loadHTML($html); $params = [];
  foreach ($dom->getElementsByTagName('input') as $input) { $name = $input->getAttribute('name'); if ($name !== '') $params[$name] = $input->getAttribute('value'); }
  $response = $post($params); $storage->resetCache();
  $check(in_array($response->getStatusCode(), [302, 303], TRUE) && !$storage->load($id), 'confirmed owner deletion removes module');
  foreach ($releaseIds as $rid) $check(!$storage->load($rid), 'module versions are removed with the module');
  $as($users['admin']);
  $packageDirectory = 'private://nelkano-modules/packages';
  \Drupal::service('file_system')->prepareDirectory($packageDirectory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $packageUri = $packageDirectory . '/' . $prefix . '.nelkmod';
  file_put_contents($packageUri, Packages::zip(Packages::files('example.' . $prefix . 'tester', '1.0.0', $key)));
  $packageFile = File::create(['uri' => $packageUri, 'filename' => $prefix . '.nelkmod', 'uid' => $users['admin']->id(), 'status' => 0]);
  $packageFile->save(); $files[] = (int) $packageFile->id();
  $tester = $make('tester', 'tester', 0, (int) $packageFile->id());
  $as($users['owner']);
  try { $registry->deleteModule($tester, (int) $tester->getRevisionId()); throw new RuntimeException('Tester deletion accepted'); }
  catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) { $check(TRUE, 'ordinary author cannot delete Tester module'); }
  $as($users['admin']); $registry->deleteModule($tester, (int) $tester->getRevisionId()); $storage->resetCache();
  $check(!$storage->load($tester->id()), 'administrator can delete Tester module');
  \Drupal::entityTypeManager()->getStorage('file')->resetCache(); $packageFile = File::load($packageFile->id());
  $check($packageFile && $packageFile->isTemporary() && is_file($packageUri), 'private package scheduled for safe unused-file cleanup');
  $as($users['owner']);
  $directory = 'public://nelkano-modules/images'; \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $uri = $directory . '/' . $prefix . '.png'; file_put_contents($uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7WQAAAAASUVORK5CYII='));
  $file = File::create(['uri' => $uri, 'filename' => $prefix . '.png', 'uid' => $users['owner']->id(), 'status' => 0]); $file->save(); $files[] = (int) $file->id();
  $imageModule = $make('image', 'public', (int) $file->id());
  $sharedImageModule = $make('sharedimage', 'public', (int) $file->id());
  $registry->deleteModule($imageModule, (int) $imageModule->getRevisionId());
  \Drupal::entityTypeManager()->getStorage('file')->resetCache();
  $check(File::load($file->id())->isPermanent(), 'image still used by another module remains permanent');
  $registry->deleteModule($sharedImageModule, (int) $sharedImageModule->getRevisionId());
  \Drupal::entityTypeManager()->getStorage('file')->resetCache(); $file = File::load($file->id());
  $check($file && $file->isTemporary() && is_file($uri), 'unused module image queued for ordinary cleanup without deleting bytes during transaction');
  echo 'SUCCESS: ' . $checks . " module deletion checks.\n";
}
finally {
  if ($switched) $switch->switchBack();
  foreach ($nodes as $nid) if ($node = Node::load($nid)) $node->delete();
  foreach ($files as $fid) if ($file = File::load($fid)) $file->delete();
  foreach ($users as $user) $user->delete();
  foreach ($roles as $role) $role->delete();
}
