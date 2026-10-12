<?php

/**
 * Local integration: native Search API, AJAX, private access and additive update.
 * Run with NELKANO_LOCAL_BACKLOG_SEARCH_TEST=1; synthetic content only.
 */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_BACKLOG_SEARCH_TEST') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') {
  throw new RuntimeException('Explicit local Docker test opt-in required.');
}

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\nelkano_home\Controller\HuAdminController;
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\HttpFoundation\Request;

$checks = 0;
$check = static function ($ok, $label) use (&$checks) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++;
  echo "PASS: $label\n";
};
// Snapshot all content tables, including revisions, accounts, reports and sequence.
$snapshot = static function (): array {
  $db = \Drupal::database();
  $hashes = [];
  foreach ($db->schema()->findTables('%') as $table) {
    if (preg_match('/^(node|taxonomy_term|users|user__|nelkano_)/', $table)) {
      $rows = [];
      foreach ($db->select($table, 't')->fields('t')->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        ksort($row);
        $rows[] = json_encode($row);
      }
      sort($rows);
      $hashes[$table] = hash('sha256', implode("\n", $rows));
    }
  }
  ksort($hashes);
  return $hashes;
};
\Drupal::moduleHandler()->loadInclude('nelkano_home', 'install');
$before = $snapshot();
// Only search metadata is disposable. Recreate it to test the initial migration.
Index::load('nelkano_backlog')->delete();
Server::load('nelkano_backlog')->delete();
for ($run = 0; $run < 2; $run++) {
  $sandbox = [];
  for ($step = 0; $step < 100; $step++) {
    nelkano_home_update_11035($sandbox);
    if ($sandbox['#finished'] === 1) { break; }
  }
  $check($sandbox['#finished'] === 1, 'update finishes and indexes existing HU, run ' . $run);
  $check($before === $snapshot(), 'all persistent content tables preserved, run ' . $run);
}
$check(Index::load('nelkano_backlog')->getTrackerInstance()->getRemainingItemsCount() === 0, 'no initial indexing pending');

$prefix = 'hu_search_' . bin2hex(random_bytes(4));
$ids = [];
$users = [];
$roles = [];
$switcher = \Drupal::service('account_switcher');
$switched = FALSE;
$http = \Drupal::httpClient();
$jar = new CookieJar();
$call = static fn(string $path, array $query = []) => $http->get('http://127.0.0.1' . $path, ['query' => $query, 'cookies' => $jar, 'http_errors' => FALSE, 'allow_redirects' => FALSE]);
$login = static function ($user) use ($call, $jar) {
  $jar->clear();
  $time = time();
  $response = $call('/user/reset/' . $user->id() . '/' . $time . '/' . user_pass_rehash($user, $time) . '/login');
  if ($response->getStatusCode() !== 302) { throw new RuntimeException('Temporary login failed'); }
};
$cards = static function ($response): array {
  $html = (string) $response->getBody();
  if (str_starts_with($response->getHeaderLine('Content-Type'), 'application/json')) {
    $commands = json_decode($html, TRUE, 512, JSON_THROW_ON_ERROR);
    $html = '';
    foreach ($commands as $command) {
      if (($command['command'] ?? '') === 'insert' && ($command['selector'] ?? '') === '#nk-backlog-results') {
        $html = $command['data'];
      }
    }
  }
  preg_match_all('/class="nk-hu-card"[^>]*data-id="(\d+)"/', $html, $matches);
  return array_map('intval', $matches[1]);
};
try {
  foreach ([TRUE, FALSE] as $admin) {
    $id = $prefix . ($admin ? '_admin' : '_reader');
    $role = Role::create(['id' => $id, 'label' => $id, 'permissions' => $admin ? [HuWorkflow::PERMISSION, 'access content'] : ['access content', 'view own unpublished content']]);
    $role->save(); $roles[] = $role;
    $user = User::create(['name' => $id, 'mail' => $id . '@example.invalid', 'status' => 1, 'roles' => [$id]]);
    $user->save(); $users[] = $user;
  }
  $switcher->switchTo($users[0]); $switched = TRUE;
  for ($i = 0; $i < 13; $i++) {
    $node = HuWorkflow::save(NULL, NULL, 'new', $prefix . ' Emulación <script> prueba ' . $i, $prefix . ' sincronización descriptiva exclusiva', ['sprint' => $i === 0 ? NULL : ($i % 2 ? 0 : 2)]);
    $ids[] = (int) $node->id();
  }
  Index::load('nelkano_backlog')->indexItems();
  $first = Node::load($ids[0]);
  $number = HuWorkflow::number($first);
  $first->setOwnerId($users[1]->id())->setPublished()->save();
  $check(!$first->isPublished(), 'publication is deliberately prevented on every save');
  $check(!$first->access('view', new AnonymousUserSession()) && !$first->access('view', $users[1]), 'anonymous and owner with own-unpublished permission denied');
  $switcher->switchBack(); $switched = FALSE;
  Index::load('nelkano_backlog')->indexItems();

  foreach ([FALSE, TRUE] as $ajax) {
    $query = $ajax ? ['_wrapper_format' => 'drupal_ajax'] : [];
    $check($call('/admin/nelkano/backlog', $query)->getStatusCode() === 403, 'anonymous ' . ($ajax ? 'AJAX' : 'HTML') . ' denied');
  }
  $login($users[0]);
  $base = '/admin/nelkano/backlog';
  foreach ([FALSE, TRUE] as $ajax) {
    $extra = $ajax ? ['_wrapper_format' => 'drupal_ajax'] : [];
    $response = $call($base, ['q' => $prefix] + $extra);
    $check($response->getStatusCode() === 200 && count($cards($response)) === 13, 'all matches shown beyond default 10-item limit, AJAX=' . (int) $ajax);
    foreach (['emulacion', 'EMULACIÓN', 'mulaci', 'sincronizacion', 'descriptiva exclusiva'] as $term) {
      $response = $call($base, ['q' => $prefix . ' ' . $term] + $extra);
      $check(count($cards($response)) === 13, 'case/accent/partial/description search: ' . $term . ', AJAX=' . (int) $ajax);
    }
    $check($cards($call($base, ['q' => $prefix, 'sprint' => 'none'] + $extra)) === [$ids[0]], 'unassigned sprint combined with search');
    $check(count($cards($call($base, ['q' => $prefix, 'sprint' => '0'] + $extra))) === 6, 'Sprint 0 combined with search');
    $check(count($cards($call($base, ['q' => $prefix, 'sprint' => '2'] + $extra))) === 6, 'Sprint 2 combined with search');
    foreach ([$number, 'HU ' . $number, (string) (int) $number] as $term) {
      $check($cards($call($base, ['q' => $term] + $extra)) === [$ids[0]], 'immutable HU number search: ' . $term);
    }
    $check($cards($call($base, ['q' => $prefix . ' nonexistent'] + $extra)) === [], 'no-match response');
    $response = $call($base, ['q' => $prefix . ' <script>', 'sprint' => 'none'] + $extra);
    $html = (string) $response->getBody();
    $check(!str_contains($html, '<script> prueba'), 'title never emits executable markup');
    $check($call($base, ['q' => ['invalid']] + $extra)->getStatusCode() === 400, 'array query rejected');
    $check($call($base, ['q' => str_repeat('a', 201)] + $extra)->getStatusCode() === 400, 'oversized query rejected');
  }
  $response = $call($base, ['q' => $prefix, 'sprint' => 'none', '_wrapper_format' => 'drupal_ajax']);
  $check($response->getHeaderLine('X-Drupal-Ajax-Token') === '1', 'core AJAX response verification header');
  $check(str_contains($response->getHeaderLine('Cache-Control'), 'no-store'), 'private AJAX results not cached');
  $check(str_contains((string) $response->getBody(), 'q%3D') || str_contains((string) $response->getBody(), 'q='), 'edit links carry the search');
  $login($users[1]);
  foreach ([$base, $base . '/add', $base . '/' . $first->id() . '/edit', '/node/' . $first->id(), '/node/' . $first->id() . '/edit', '/node/' . $first->id() . '/revisions'] as $path) {
    $check(in_array($call($path)->getStatusCode(), [403, 404], TRUE), 'ordinary user denied ' . $path);
  }
  $check($call($base, ['_wrapper_format' => 'drupal_ajax', 'q' => $prefix])->getStatusCode() === 403, 'ordinary user denied AJAX');
  echo "SUCCESS: $checks search/update/access checks.\n";
}
finally {
  if ($switched) { $switcher->switchBack(); }
  foreach ($ids as $id) { Node::load($id)?->delete(); }
  foreach ($users as $user) { $user->delete(); }
  foreach ($roles as $role) { $role->delete(); }
  echo "Synthetic HU/accounts removed; existing content preserved.\n";
}