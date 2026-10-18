<?php

/** Local HTTP integration: synthetic entities/credentials only; never production. */
if (PHP_SAPI !== 'cli' || !class_exists('\\Drupal')) {
  http_response_code(404);
  exit;
}
if (getenv('NELKANO_LOCAL_BACKLOG_API_TEST') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') {
  throw new RuntimeException('Explicit local Docker test opt-in required.');
}

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\nelkano_home\Service\BacklogApiCredentials;
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\nelkano_home\Service\ReportApiCredentials;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;

$base = getenv('NELKANO_BACKLOG_TEST_URL') ?: 'http://127.0.0.1';
$url = parse_url($base);
if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], TRUE) || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], TRUE) || isset($url['user']) || isset($url['pass']) || !empty($url['path']) || isset($url['query']) || isset($url['fragment'])) {
  throw new RuntimeException('This test only accepts a loopback server origin.');
}

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++;
  echo 'PASS: ' . $label . PHP_EOL;
};
$prefix = 'hu_api_' . bin2hex(random_bytes(5));
$api = '/api/nelkano/v1/backlog/hu';
$ids = [];
$users = [];
$roles = [];
$clients = [$prefix, $prefix . '_expired', $prefix . '_revoke'];
$report_client = $prefix . '_reports';
$http = \Drupal::httpClient();
$call = static function (string $method, string $path, ?string $credential = NULL, array $options = []) use ($base, $http) {
  $options += ['http_errors' => FALSE, 'allow_redirects' => FALSE, 'timeout' => 30];
  $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
  if ($credential !== NULL) { $options['headers']['Authorization'] = 'Bearer ' . $credential; }
  try { return $http->request($method, $base . $path, $options); }
  catch (\GuzzleHttp\Exception\TransferException $error) {
    // Never let a transport exception print a request or its Bearer credential.
    $context = method_exists($error, 'getHandlerContext') ? $error->getHandlerContext() : [];
    $diagnostic = array_intersect_key($context, array_flip(['errno', 'http_code', 'primary_ip', 'connect_time', 'total_time']));
    throw new RuntimeException('Loopback HTTP request failed: ' . $method . ' ' . parse_url($path, PHP_URL_PATH) . '; ' . get_class($error) . '; ' . json_encode($diagnostic));
  }
};
$decode = static fn ($response): array => json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
$read = static function (int $id) {
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
  return Node::load($id);
};
$node_ids_before = array_map('intval', array_values(\Drupal::entityQuery('node')->accessCheck(FALSE)->execute()));
$baseline_max = $node_ids_before ? max($node_ids_before) : 0;
// Include existing fields and historical revisions, not only current node rows.
$snapshot = static function () use ($node_ids_before): array {
  $hashes = [];
  $db = \Drupal::database();
  foreach ($db->schema()->findTables('node%') as $table) {
    $key = str_starts_with($table, 'node__') || str_starts_with($table, 'node_revision__') ? 'entity_id' : 'nid';
    if (!$db->schema()->fieldExists($table, $key)) { continue; }
    $rows = [];
    if ($node_ids_before) {
      foreach ($db->select($table, 't')->fields('t')->condition($key, $node_ids_before, 'IN')->execute()->fetchAll(PDO::FETCH_ASSOC) as $row) {
        ksort($row);
        $rows[] = json_encode($row, JSON_THROW_ON_ERROR);
      }
    }
    sort($rows);
    $hashes[$table] = hash('sha256', implode("\n", $rows));
  }
  ksort($hashes);
  return $hashes;
};
$before = $snapshot();
$token = '';
$role = $user = NULL;

try {
  foreach ([TRUE, FALSE] as $allowed) {
    $name = $prefix . ($allowed ? '_writer' : '_reader');
    $fixture_role = Role::create(['id' => $name, 'label' => $name, 'permissions' => $allowed ? [HuWorkflow::PERMISSION, 'access content'] : ['access content', 'view own unpublished content']]);
    $fixture_role->save(); $roles[] = $fixture_role;
    $fixture_user = User::create(['name' => $name, 'mail' => $name . '@example.invalid', 'status' => 1, 'roles' => [$name]]);
    $fixture_user->save(); $users[] = $fixture_user;
  }
  [$user, $reader] = $users;
  $role = $roles[0];
  foreach (['missing account' => (int) $user->id() + 1000000, 'account without backlog permission' => (int) $reader->id()] as $label => $invalid_uid) {
    try {
      BacklogApiCredentials::issue($clients[2], $invalid_uid, 1);
      $check(FALSE, 'credential issuance rejects ' . $label);
    }
    catch (InvalidArgumentException) { $check(TRUE, 'credential issuance rejects ' . $label); }
  }
  $token = BacklogApiCredentials::issue($prefix, (int) $user->id(), 1);
  $check(str_starts_with($token, 'nkb_'), 'backlog credential has a dedicated namespace');
  $report_token = ReportApiCredentials::issue($report_client, 1);
  foreach (['GET', 'POST', 'PATCH'] as $method) {
    $target = $method === 'PATCH' ? $api . '/' . ($baseline_max + 1000000) : $api;
    $options = $method === 'GET' ? [] : ['json' => []];
    $check($call($method, $target, NULL, $options)->getStatusCode() === 401, 'anonymous ' . $method . ' rejected before content access');
    $check($call($method, $target, $report_token, $options)->getStatusCode() === 401, 'report credential cannot use backlog ' . $method);
  }
  $check($call('GET', $api, 'player-token')->getStatusCode() === 401, 'player credential cannot read backlog');
  $check($call('GET', '/api/nelkano/v1/error-reports', $token)->getStatusCode() === 401, 'backlog credential cannot read reports');
  $response = $call('GET', $api, $token);
  $check($response->getStatusCode() === 200, 'active account with backlog permission can list');
  $check(str_contains($response->getHeaderLine('Cache-Control'), 'no-store') && str_contains($response->getHeaderLine('Vary'), 'Authorization'), 'private listing cannot be cached across credentials');
  $check($response->getHeaderLine('X-Content-Type-Options') === 'nosniff', 'JSON response disables MIME sniffing');
  $check($call('GET', $api)->getStatusCode() === 401, 'authenticated response never leaks into anonymous page cache');

  $jar = new CookieJar();
  $time = time();
  $login = '/user/reset/' . $user->id() . '/' . $time . '/' . user_pass_rehash($user, $time) . '/login';
  $check($call('GET', $login, NULL, ['cookies' => $jar])->getStatusCode() === 302, 'synthetic administrator session created');
  $check($call('GET', $api, NULL, ['cookies' => $jar])->getStatusCode() === 401, 'Drupal administrator cookie alone cannot read API');
  $check($call('POST', $api, NULL, ['cookies' => $jar, 'json' => ['title' => $prefix, 'description' => 'Cookie alone.']])->getStatusCode() === 401, 'Drupal administrator cookie alone cannot create HU');

  foreach (['?limit=0', '?limit=101', '?limit[]=1', '?after_id=-1', '?after_id[]=0', '?sprint=1.5', '?sprint=2147483648', '?sprint[]=0', '?status=rejected', '?status[]=new'] as $query) {
    $check($call('GET', $api . $query, $token)->getStatusCode() === 400, 'invalid listing query rejected: ' . $query);
  }
  $check($call('GET', $api . '/0', $token)->getStatusCode() === 404, 'missing HU returns 404');

  $create = static function (array $body) use ($call, $decode, $check, $api, &$token, &$ids): array {
    $response = $call('POST', $api, $token, ['json' => $body]);
    $check($response->getStatusCode() === 201, 'HU created through authenticated POST');
    $data = $decode($response);
    $ids[] = $data['id'];
    $check($response->getHeaderLine('ETag') === '"' . $data['revision_id'] . '"', 'creation returns current revision ETag');
    $check(str_ends_with($response->getHeaderLine('Location'), $api . '/' . $data['id']), 'creation Location identifies the new HU');
    return $data;
  };
  $first = $create(['title' => $prefix . ' <script>alert(1)</script>', 'description' => "Descripción de prueba.\nTexto plano."]);
  $path = $api . '/' . $first['id'];
  $check($first['api_version'] === 1 && $first['status'] === 'new' && $first['sprint'] === NULL, 'creation defaults to Nuevo without sprint');
  $check(is_string($first['number']) && preg_match('/^[0-9]{5,}$/D', $first['number']) === 1 && is_int($first['revision_id']) && is_int($first['created']) && is_int($first['changed']), 'identity and timestamps have stable JSON types');
  $check($first['title'] === $prefix . ' <script>alert(1)</script>' && $first['description'] === "Descripción de prueba.\nTexto plano.", 'plain text is returned without HTML interpretation');
  $node = $read($first['id']);
  $check(!$node->isPublished() && (int) $node->getOwnerId() === (int) $user->id(), 'created HU remains private and belongs to credential account');
  $check(!$node->access('view', new AnonymousUserSession()) && !$node->access('view', $reader), 'ordinary users cannot view private HU nodes');
  $check($call('GET', '/node/' . $first['id'])->getStatusCode() === 403, 'anonymous canonical node access is forbidden');
  $check($call('GET', $path)->getStatusCode() === 401, 'anonymous detail access is forbidden');
  $detail = $call('GET', $path, $token);
  $check($detail->getStatusCode() === 200, 'authenticated detail returns 200');
  $detail_data = $decode($detail);
  $difference = [];
  foreach ($first as $key => $value) {
    if (($detail_data[$key] ?? NULL) !== $value) { $difference[$key] = ['created' => $value, 'detail' => $detail_data[$key] ?? NULL]; }
  }
  $check($detail_data === $first, 'detail returns persisted fields; differences: ' . json_encode($difference));
  $check($detail->getHeaderLine('ETag') === '"' . $first['revision_id'] . '"', 'detail returns revision ETag; actual: ' . $detail->getHeaderLine('ETag'));

  $patch = static function (array $body, ?array $headers = NULL) use ($call, $path, &$first, &$token) {
    return $call('PATCH', $path, $token, ['json' => $body, 'headers' => $headers ?? ['If-Match' => '"' . $first['revision_id'] . '"']]);
  };
  $check($patch(['title' => 'No precondition'], [])->getStatusCode() === 428, 'editing requires If-Match');
  foreach (['123', 'W/"123"', '*', '"invalid"'] as $invalid) {
    $check($patch(['title' => 'Invalid precondition'], ['If-Match' => $invalid])->getStatusCode() === 400, 'invalid If-Match syntax rejected');
  }
  $old_revision = $first['revision_id'];
  $response = $patch(['title' => $prefix . ' edited', 'description' => 'Edited description.', 'sprint' => 0]);
  $check($response->getStatusCode() === 200, 'title, description and Sprint 0 updated atomically');
  $first = $decode($response);
  $check($first['title'] === $prefix . ' edited' && $first['description'] === 'Edited description.' && $first['sprint'] === 0 && $first['status'] === 'new' && $first['revision_id'] !== $old_revision, 'partial edit preserves status and creates revision');
  $check($response->getHeaderLine('ETag') === '"' . $first['revision_id'] . '"', 'PATCH returns its new revision ETag');
  $check($patch(['status' => 'resolved'], ['If-Match' => '"' . $old_revision . '"'])->getStatusCode() === 412, 'stale revision cannot overwrite edited HU');
  $check($decode($call('GET', $path, $token)) === $first, 'rejected concurrent edit creates no revision or partial changes');
  $same = $patch(['title' => $first['title'], 'description' => $first['description'], 'status' => $first['status'], 'sprint' => 0]);
  $check($same->getStatusCode() === 200 && $decode($same)['revision_id'] === $first['revision_id'], 'identical PATCH is idempotent');
  foreach (['in_progress', 'blocked', 'resolved', 'new'] as $status) {
    $response = $patch(['status' => $status]);
    $check($response->getStatusCode() === 200, 'move to supported HU state: ' . $status);
    $first = $decode($response);
    $check($first['status'] === $status && $first['sprint'] === 0 && $first['number'] === HuWorkflow::number($node), 'state movement preserves sprint and immutable number');
  }
  foreach ([-2147483648, 2147483647, NULL, 0] as $sprint) {
    $response = $patch(['sprint' => $sprint]);
    $check($response->getStatusCode() === 200 && $decode($response)['sprint'] === $sprint, 'sprint update accepts int32 boundaries, null and zero');
    $first = $decode($response);
  }

  $invalid_bodies = [
    [], ['title' => NULL], ['title' => ''], ['title' => '   '], ['title' => 123], ['title' => []], ['title' => str_repeat('x', 256)],
    ['description' => NULL], ['description' => ''], ['description' => " \n "], ['description' => FALSE], ['description' => []], ['description' => str_repeat('x', 20001)],
    ['status' => NULL], ['status' => []], ['status' => 'rejected'], ['status' => 'term:1'],
    ['sprint' => '2'], ['sprint' => 1.5], ['sprint' => TRUE], ['sprint' => []], ['sprint' => 2147483648], ['sprint' => -2147483649],
    ['number' => '99999'], ['uid' => 1], ['published' => TRUE], ['revision_id' => $first['revision_id']], ['unknown' => 'ignored?'],
    ['title' => 'Must not partially save', 'sprint' => 'bad'],
  ];
  foreach ($invalid_bodies as $body) {
    $check($patch($body)->getStatusCode() === 400, 'invalid PATCH body rejected without mutation');
  }
  $raw_headers = ['Content-Type' => 'application/json', 'If-Match' => '"' . $first['revision_id'] . '"'];
  foreach (['{', 'null', '123', '"text"', '[]', '[{"title":"list"}]'] as $raw) {
    $check($call('PATCH', $path, $token, ['body' => $raw, 'headers' => $raw_headers])->getStatusCode() === 400, 'malformed or non-object JSON PATCH rejected');
  }
  $check($call('PATCH', $path, $token, ['body' => '{"title":"Wrong content type"}', 'headers' => ['Content-Type' => 'text/plain', 'If-Match' => '"' . $first['revision_id'] . '"']])->getStatusCode() === 415, 'PATCH requires application/json');
  $check($decode($call('GET', $path, $token)) === $first, 'all invalid PATCH bodies preserve complete HU and revision');
  foreach ([[], ['title' => $prefix], ['description' => 'No title.'], ['title' => NULL, 'description' => 'Null title.'], ['title' => $prefix, 'description' => 'Test.', 'number' => 1], ['title' => $prefix, 'description' => 'Test.', 'status' => 'rejected'], ['title' => $prefix, 'description' => 'Test.', 'sprint' => '0']] as $body) {
    $check($call('POST', $api, $token, ['json' => $body])->getStatusCode() === 400, 'invalid POST fields rejected');
  }
  foreach (['{', 'null', '[]', '[{"title":"list"}]'] as $raw) {
    $check($call('POST', $api, $token, ['body' => $raw, 'headers' => ['Content-Type' => 'application/json']])->getStatusCode() === 400, 'malformed or non-object JSON POST rejected');
  }
  $check($call('POST', $api, $token, ['body' => '{"title":"Wrong content type","description":"Test."}', 'headers' => ['Content-Type' => 'text/plain']])->getStatusCode() === 415, 'POST requires application/json');
  $check($call('POST', $api, $token, ['body' => str_repeat(' ', 262145), 'headers' => ['Content-Type' => 'application/json']])->getStatusCode() === 413, 'oversized JSON request rejected');

  $unicode_title = str_repeat('🎮', 255);
  $unicode_description = str_repeat('🎮', 20000);
  $response = $patch(['title' => $unicode_title, 'description' => $unicode_description]);
  $check($response->getStatusCode() === 200, 'maximum Unicode title and description fit escaped JSON body limit');
  $first = $decode($response);
  $check($first['title'] === $unicode_title && $first['description'] === $unicode_description, 'Unicode limits count characters and preserve content');
  $check($patch(['description' => $unicode_description . '🎮'])->getStatusCode() === 400, 'Unicode description over limit rejected');
  $first = $decode($patch(['title' => $prefix . ' edited', 'description' => 'Synthetic fixture only.', 'status' => 'blocked']));

  $other = Node::create(['type' => 'nelkano_error_report', 'title' => $prefix . ' other bundle', 'uid' => $user->id(), 'status' => 0, WorkflowStatus::FIELD => WorkflowStatus::termId('new')]);
  $other->save(); $ids[] = (int) $other->id();
  $check($call('GET', $api . '/' . $other->id(), $token)->getStatusCode() === 404, 'GET never exposes another content type');
  $check($call('PATCH', $api . '/' . $other->id(), $token, ['json' => ['title' => 'Cannot edit report'], 'headers' => ['If-Match' => '"' . $other->getRevisionId() . '"']])->getStatusCode() === 404, 'PATCH cannot edit another content type');
  $check($read((int) $other->id())->label() === $prefix . ' other bundle', 'wrong-bundle rejection preserves report');
  $check($call('PATCH', $api . '/0', $token, ['json' => ['title' => 'Missing HU'], 'headers' => ['If-Match' => '"1"']])->getStatusCode() === 404, 'PATCH missing HU returns 404');
  $check($call('DELETE', $path, $token)->getStatusCode() === 405, 'API exposes no HU deletion');
  $check($call('PUT', $path, $token, ['json' => ['title' => 'Wrong method']])->getStatusCode() === 405, 'unsupported update method rejected');

  $check(\Drupal::lock()->acquire('nelkano_hu:' . $first['id'], 60), 'fixture acquires shared edit lock');
  try { $check($patch(['status' => 'resolved'])->getStatusCode() === 409, 'HTTP API respects existing backlog edit/move/delete lock'); }
  finally { \Drupal::lock()->release('nelkano_hu:' . $first['id']); }
  $check($decode($call('GET', $path, $token)) === $first, 'busy lock preserves HU and revision');
  $check(\Drupal::lock()->acquire('nelkano_hu:create', 60), 'fixture acquires shared creation lock');
  try { $check($call('POST', $api, $token, ['json' => ['title' => $prefix . ' locked create', 'description' => 'Must not create.']])->getStatusCode() === 409, 'API creation respects shared creation lock'); }
  finally { \Drupal::lock()->release('nelkano_hu:create'); }

  $second = $create(['title' => $prefix . ' Sprint 2', 'description' => 'Second fixture.', 'status' => 'resolved', 'sprint' => 2]);
  $third = $create(['title' => $prefix . ' no sprint', 'description' => 'Third fixture.']);
  $check((int) $second['number'] > (int) $first['number'] && (int) $third['number'] > (int) $second['number'], 'server allocates monotonically increasing immutable HU numbers');
  $cursor = $baseline_max;
  $seen = [];
  for ($page_number = 0; $page_number < 10; $page_number++) {
    $response = $call('GET', $api . '?after_id=' . $cursor . '&limit=1', $token);
    $page = $decode($response);
    $check($response->getStatusCode() === 200 && count($page['items']) <= 1 && is_bool($page['has_more']), 'bounded list page has pagination metadata');
    foreach ($page['items'] as $item) {
      $check($item['id'] > $cursor && !isset($seen[$item['id']]), 'cursor returns ordered unique HU');
      $seen[$item['id']] = TRUE;
    }
    $cursor = $page['next_after_id'];
    if (!$page['has_more']) { break; }
  }
  $check(array_keys($seen) === [$first['id'], $second['id'], $third['id']], 'pagination includes all HU states and excludes report fixture');
  foreach (['sprint=0' => [$first['id']], 'sprint=none' => [$third['id']], 'sprint=2' => [$second['id']], 'status=blocked' => [$first['id']], 'sprint=0&status=blocked' => [$first['id']], 'sprint=2&status=blocked' => []] as $filter => $expected) {
    $page = $decode($call('GET', $api . '?after_id=' . $baseline_max . '&' . $filter, $token));
    $check(array_column($page['items'], 'id') === $expected, 'listing filter matches stored state and sprint: ' . $filter);
  }

  $role->revokePermission(HuWorkflow::PERMISSION)->save();
  $check($call('GET', $api, $token)->getStatusCode() === 401 && $patch(['title' => 'Revoked permission'])->getStatusCode() === 401, 'permission revocation invalidates reads and writes immediately');
  $role->grantPermission(HuWorkflow::PERMISSION)->save();
  $check($call('GET', $api, $token)->getStatusCode() === 200, 'restored permission reauthorizes bound credential');
  $role->revokePermission('access content')->save();
  // Local authenticated users inherit access content from the shared role.
  // Never change that real role just to remove an inherited fixture permission.
  if ($user->hasPermission('access content')) {
    $check($call('GET', $api, $token)->getStatusCode() === 200, 'content access inherited from shared role remains effective');
  }
  else {
    $check($call('GET', $api, $token)->getStatusCode() === 401 && $patch(['title' => 'Revoked content access'])->getStatusCode() === 401, 'loss of effective Drupal content access invalidates reads and writes');
  }
  $role->grantPermission('access content')->save();
  $check($call('GET', $api, $token)->getStatusCode() === 200, 'restored Drupal content access reauthorizes bound credential');
  $user->block()->save();
  $check($call('GET', $api, $token)->getStatusCode() === 401, 'blocked credential account is denied');
  try {
    BacklogApiCredentials::issue($clients[2], (int) $user->id(), 1);
    $check(FALSE, 'credential issuance rejects inactive account');
  }
  catch (InvalidArgumentException) { $check(TRUE, 'credential issuance rejects inactive account'); }
  $user->activate()->save();
  $check($call('GET', $api, $token)->getStatusCode() === 200, 'reactivated credential account can authenticate');
  $expired = BacklogApiCredentials::issue($clients[1], (int) $user->id(), 1);
  $store = \Drupal::keyValue('nelkano_backlog_api_tokens');
  $record = $store->get(hash('sha256', $expired));
  $record['expires'] = time() - 1;
  $store->set(hash('sha256', $expired), $record);
  $check($call('GET', $api, $expired)->getStatusCode() === 401, 'expired credential is denied');
  $revoked = BacklogApiCredentials::issue($clients[2], (int) $user->id(), 1);
  BacklogApiCredentials::revoke($clients[2]);
  $check($call('GET', $api, $revoked)->getStatusCode() === 401, 'revoked credential is denied');
  $old_token = $token;
  $token = BacklogApiCredentials::issue($prefix, (int) $user->id(), 1);
  $check($call('GET', $api, $old_token)->getStatusCode() === 401 && $call('GET', $api, $token)->getStatusCode() === 200, 'client rotation immediately invalidates previous credential');
  $check($decode($call('GET', $path, $token)) === $first, 'denied authentication attempts never mutate HU');
  $check((int) $read($first['id'])->getRevisionUserId() === (int) $user->id(), 'API revisions are attributed to bound account');
  $check($snapshot() === $before, 'all pre-existing nodes, fields and historical revisions remain unchanged');
  echo 'SUCCESS: ' . $checks . ' backlog API checks.' . PHP_EOL;
}
finally {
  foreach ($clients as $client) { BacklogApiCredentials::revoke($client); }
  ReportApiCredentials::revoke($report_client);
  // Recover creations even if an assertion failed before their response was read.
  if ($user) {
    $ids = array_unique(array_merge($ids, array_map('intval', array_values(\Drupal::entityQuery('node')->accessCheck(FALSE)->condition('uid', $user->id())->execute()))));
  }
  foreach ($ids as $id) { $read((int) $id)?->delete(); }
  foreach ($users as $fixture_user) { $fixture_user->delete(); }
  foreach ($roles as $fixture_role) { $fixture_role->delete(); }
  $check($snapshot() === $before, 'synthetic cleanup preserves every pre-existing node and revision');
  echo 'Only synthetic nodes, accounts, roles and credentials removed; HU sequence intentionally retained.' . PHP_EOL;
}
