<?php

if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_MODULE_CATALOG_TEST') !== '1'
  || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') {
  throw new RuntimeException('Explicit local Docker test opt-in required.');
}
require_once __DIR__ . '/ModuleFixtures.php';

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;
use NelkanoModuleTests\FakeDownloadClient;
use NelkanoModuleTests\Packages;

$checks = 0;
$check = static function ($ok, string $label) use (&$checks) { if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } $checks++; echo "PASS: $label\n"; };
$reject = static function (callable $test, string $label) use ($check) {
  try { $test(); }
  catch (InvalidArgumentException | \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) { $check(TRUE, $label); return; }
  throw new RuntimeException('FAIL: accepted ' . $label);
};
$snapshot = static function (): array {
  $result = [];
  $db = \Drupal::database();
  foreach ($db->schema()->findTables('%') as $table) {
    if (preg_match('/^(node|taxonomy_term|users|user__|nelkano_)/', $table)) {
      $rows = [];
      foreach ($db->select($table, 't')->fields('t')->execute()->fetchAll(PDO::FETCH_ASSOC) as $row) { ksort($row); $rows[] = json_encode($row); }
      sort($rows); $result[$table] = hash('sha256', implode("\n", $rows));
    }
  }
  ksort($result); return $result;
};
$before = $snapshot();
\Drupal::moduleHandler()->loadInclude('nelkano_home', 'install');
nelkano_home_update_11036();
require_once __DIR__ . '/../nelkano_modules.setup.inc';
nelkano_modules_setup();
$check($before === $snapshot(), 'update/setup repeat preserves every existing content table');
$check(\Drupal\user\Entity\Role::load('authenticated')->hasPermission('create nelkano modules'), 'authenticated users can publish own modules');

$fake = new FakeDownloadClient();
$registry = new ModuleRegistry($fake, new PackageVerifier());
\Drupal::getContainer()->set('nelkano_modules.registry', $registry);
$http = \Drupal::httpClient();
$jar = new CookieJar();
$call = static fn($path, $options = []) => $http->get('http://127.0.0.1' . $path, $options + ['cookies' => $jar, 'http_errors' => FALSE, 'allow_redirects' => FALSE]);
$login = static function ($user) use ($jar, $call) {
  $jar->clear(); $time = time();
  if ($call('/user/reset/' . $user->id() . '/' . $time . '/' . user_pass_rehash($user, $time) . '/login')->getStatusCode() !== 302) { throw new RuntimeException('Login failed'); }
};
$prefix = 'catalogtest' . bin2hex(random_bytes(4));
$owner = NULL; $stranger = NULL; $modules = []; $switched = FALSE;
$switcher = \Drupal::service('account_switcher');
$key = sodium_crypto_sign_keypair();
$url1 = 'https://github.com/example/' . $prefix . '/releases/download/v1/a.nelkmod';
$url2 = 'https://github.com/example/' . $prefix . '/releases/download/v2/a.nelkmod';
$url3 = 'https://github.com/example/' . $prefix . '/releases/download/v3/a.nelkmod';
$id = 'example.' . $prefix;
$values = ['title' => 'Emulación ' . $prefix . ' <script>alert(1)</script>', 'description' => 'Sincronización y colecciones de juegos.', 'source' => 'https://github.com/example/' . $prefix, 'published' => TRUE, 'notes' => 'Primera versión'];
try {
  $check($call('/modulos')->getStatusCode() === 200, 'anonymous catalog');
  foreach (['/modulos/crear', '/modulos/mis-modulos'] as $path) { $check($call($path)->getStatusCode() === 403, 'anonymous denied ' . $path); }
  foreach (['owner', 'other'] as $kind) {
    $user = User::create(['name' => $prefix . $kind, 'mail' => $prefix . $kind . '@example.invalid', 'status' => 1]); $user->save();
    if ($kind === 'owner') { $owner = $user; } else { $stranger = $user; }
  }
  $switcher->switchTo($owner); $switched = TRUE;
  $fake->responses[$url1] = ['status' => 200, 'body' => Packages::make($id, '1.0.0', $key, ['android']), 'etag' => '"v1"'];
  $first = $registry->saveModule(NULL, NULL, $values, $registry->inspect($url1)); $modules[] = (int) $first->id();
  $check((int) $first->getOwnerId() === (int) $owner->id() && $first->get('field_module_available')->value, 'node owned by submitting user and available');
  $releases = $registry->releases($first); $v1 = $releases[0];
  $check(!$v1->isPublished() && $v1->get('field_release_version')->value === '1.0.0', 'release metadata derived from signed package');
  $fake->responses[$url2] = ['status' => 200, 'body' => Packages::make($id, '2.0.0', $key, ['jvm-desktop'])];
  $v2 = $registry->saveVersion($first, NULL, NULL, $registry->inspect($url2), 'Segunda versión');
  $check(count($registry->releases($first)) === 2, 'old release retained');
  $check($registry->releases($first)[0]->id() === $v2->id(), 'versions sorted semantically newest first');
  $reject(fn() => $registry->saveVersion($first, NULL, NULL, $registry->inspect($url2), ''), 'duplicate version denied');
  $fake->responses[$url3] = ['status' => 200, 'body' => Packages::make($id, '3.0.0', sodium_crypto_sign_keypair())];
  $reject(fn() => $registry->saveVersion($first, NULL, NULL, $registry->inspect($url3), ''), 'publisher key change denied');
  $fake->responses[$url3] = ['status' => 200, 'body' => Packages::make('example.different', '3.0.0', $key)];
  $reject(fn() => $registry->saveVersion($first, NULL, NULL, $registry->inspect($url3), ''), 'different module ID denied');
  $fake->responses[$url3] = ['status' => 200, 'body' => '<html>not a package</html>'];
  $reject(fn() => $registry->inspect($url3), 'link extension alone does not validate package');
  $reject(fn() => $registry->saveModule(NULL, NULL, $values, $registry->inspect($url1)), 'duplicate module ID denied');
  $switcher->switchBack(); $switched = FALSE;
  $check(!$v1->access('view', new AnonymousUserSession()), 'release node private to non-owner');
  $switcher->switchTo($stranger); $switched = TRUE;
  $reject(fn() => $registry->saveModule($first, (int) $first->getRevisionId(), $values), 'other user cannot edit module');
  $reject(fn() => $registry->saveVersion($first, NULL, NULL, $registry->inspect($url1), ''), 'other user cannot add releases');
  $switcher->switchBack(); $switched = FALSE;

  Index::load('nelkano_modules')->indexItems();
  $detail = '/modulos/' . $first->id();
  $html = (string) $call($detail)->getBody();
  $check(str_contains($html, 'Versión 2.0.0') && str_contains($html, 'Versión 1.0.0') && substr_count($html, 'class="nm-button nm-download"') === 2, 'public detail offers both downloads');
  $check(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'), 'titles escaped');
  $check($call('/node/' . $first->id())->getStatusCode() === 200, 'canonical node uses public module page');
  $check($call('/node/' . $v1->id())->getStatusCode() === 403, 'canonical release inaccessible anonymously');
  foreach (['emulacion', 'SINCRONIZACION', $id, 'colecc'] as $term) {
    $response = $call('/modulos', ['query' => ['q' => $term . (str_contains($term, $prefix) ? '' : ' ' . $prefix)]]);
    $check(str_contains((string) $response->getBody(), $detail), 'Search API matches: ' . $term);
  }
  $check(str_contains((string) $call('/modulos', ['query' => ['q' => $prefix, 'plataforma' => 'android']])->getBody(), $detail), 'platform filter uses any available release');
  $ajax = $call('/modulos', ['query' => ['q' => $prefix, '_wrapper_format' => 'drupal_ajax']]);
  $check($ajax->getStatusCode() === 200 && $ajax->getHeaderLine('X-Drupal-Ajax-Token') === '1'
    && str_contains((string) $ajax->getBody(), 'nm-results'), 'public native AJAX response');
  $check($call('/modulos', ['query' => ['q' => ['bad']]])->getStatusCode() === 400, 'malformed search rejected');

  // Provider outages retain the last verified release.
  $fake->responses[$url2] = ['status' => 429, 'body' => ''];
  $registry->checkVersion((int) $v2->id());
  $check(Node::load($v2->id())->get('field_release_state')->value === 'available', '429 retains availability');
  $fake->responses[$url2] = new RuntimeException('timeout');
  $registry->checkVersion((int) $v2->id());
  $check(Node::load($v2->id())->get('field_release_state')->value === 'available', 'timeout retains availability');
  $fake->responses[$url2] = ['status' => 404, 'body' => ''];
  $registry->checkVersion((int) $v2->id());
  $check(Node::load($first->id())->get('field_module_available')->value, 'broken newest version falls back to available older version');
  $html = (string) $call($detail)->getBody();
  $check(substr_count($html, 'class="nm-button nm-download"') === 1 && str_contains($html, 'Descarga no disponible'), 'broken version has no download link');
  $desktop = (string) $call('/modulos', ['query' => ['q' => $prefix, 'plataforma' => 'jvm-desktop']])->getBody();
  $check(!str_contains($desktop, 'class="nm-card"'), 'live platform check excludes broken-only platform from stale index');
  $fake->responses[$url1] = ['status' => 304, 'body' => ''];
  $registry->checkVersion((int) $v1->id());
  $check(Node::load($v1->id())->get('field_release_state')->value === 'available', '304 retains previously verified package');
  $check(end($fake->requests)[1]['etag'] === '"v1"', 'conditional validation uses provider ETag');
  $fake->responses[$url1] = ['status' => 410, 'body' => ''];
  $registry->checkVersion((int) $v1->id());
  $check(!Node::load($first->id())->get('field_module_available')->value, 'no valid downloads makes module unavailable');
  // Deliberately leave the index stale: live-node check still excludes the card.
  $html = (string) $call('/modulos', ['query' => ['q' => $prefix]])->getBody();
  $check(!str_contains($html, 'class="nm-card"'), 'unavailable module excluded even from stale index');
  $check(Node::load($first->id()) && count($registry->releases(Node::load($first->id()))) === 2, 'broken links never delete content');
  $fake->responses[$url1] = ['status' => 200, 'body' => Packages::make($id, '1.0.0', $key, ['android'])];
  $registry->checkVersion((int) $v1->id());
  $check(Node::load($first->id())->get('field_module_available')->value, 'restored unchanged package restores availability');
  $fake->responses[$url1] = ['status' => 200, 'body' => Packages::make($id, '1.0.0', sodium_crypto_sign_keypair(), ['android'])];
  $registry->checkVersion((int) $v1->id());
  $check(!Node::load($v1->id())->get('field_release_state')->value || Node::load($v1->id())->get('field_release_state')->value === 'unavailable', 'changed package bytes retire the release');

  // Schedule only this synthetic module, never inspect preexisting live downloads.
  $db = \Drupal::database();
  foreach ([$v1, $v2] as $release) {
    foreach (['checked', 'queued'] as $field) {
      $db->update('node__field_release_' . $field)->fields(['field_release_' . $field . '_value' => 0])
        ->condition('entity_id', $release->id())->execute();
    }
  }
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  $registry->enqueueDue((int) $first->id());
  $queue = \Drupal::queue('nelkano_module_links');
  $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('nelkano_module_links');
  $processed = 0;
  $retained = [];
  while ($item = $queue->claimItem()) {
    if (in_array((int) $item->data['id'], [(int) $v1->id(), (int) $v2->id()], TRUE)) {
      $worker->processItem($item->data);
      $queue->deleteItem($item); $processed++;
    }
    else { $retained[] = $item; }
  }
  foreach ($retained as $item) { $queue->releaseItem($item); }
  $check($processed === 2, 'hourly queue schedules and processes both due synthetic releases');
  $registry->enqueueDue((int) $first->id());
  $check((int) Node::load($v1->id())->get('field_release_queued')->value === 0, 'freshly checked release not scheduled again within hour');
  $login($stranger);
  foreach ([$detail . '/editar', $detail . '/versiones/nueva', $detail . '/versiones/' . $v1->id() . '/editar'] as $path) {
    $check($call($path)->getStatusCode() === 403, 'HTTP owner boundary: ' . $path);
  }
  $login($owner);
  $check($call($detail . '/editar')->getStatusCode() === 200 && $call($detail . '/versiones/nueva')->getStatusCode() === 200, 'owner forms accessible');
  $check(str_contains((string) $call('/modulos/mis-modulos')->getBody(), $detail), 'owner sees unavailable modules in own listing');
  $check($call('/node/' . $first->id() . '/edit')->getStatusCode() === 403, 'generic node editor cannot bypass verification');
  $check(!str_contains((string) $call('/')->getBody(), 'href="/modulos"'), 'no navigation link added to homepage');
  echo "SUCCESS: $checks catalog/version/access/cron checks.\n";
}
finally {
  if ($switched) { $switcher->switchBack(); }
  foreach ($modules as $nid) { \Drupal::entityTypeManager()->getStorage('node')->resetCache([$nid]); Node::load($nid)?->delete(); }
  $owner?->delete(); $stranger?->delete();
  echo "Only synthetic nodes/accounts removed.\n";
}