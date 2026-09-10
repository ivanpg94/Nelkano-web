<?php

/** Local integration: synthetic content only; cleanup never rewinds HU numbers. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) {
  http_response_code(404);
  exit;
}

use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;

$base = getenv('NELKANO_REPORT_TEST_URL') ?: 'http://127.0.0.1';
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], TRUE)) {
  throw new RuntimeException('Loopback only.');
}
$checks = 0;
$check = static function ($ok, $label) use (&$checks) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  echo 'PASS: ' . $label . "\n";
  $checks++;
};
$ids = [];
$role = NULL;
$user = NULL;
$switched = FALSE;
$http = \Drupal::httpClient();
$jar = new CookieJar();
$call = static fn($method, $path, $options = []) => $http->request($method, $base . $path, $options + ['cookies' => $jar, 'http_errors' => FALSE, 'allow_redirects' => FALSE, 'timeout' => 20]);
$read = static function ($id) {
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
  return Node::load($id);
};
try {
  $check($call('GET', '/admin/nelkano/backlog')->getStatusCode() === 403, 'anonymous board denied');
  $role_id = 'hu_test_' . bin2hex(random_bytes(4));
  $role = Role::create(['id' => $role_id, 'label' => $role_id, 'permissions' => [HuWorkflow::PERMISSION, 'access content']]);
  $role->save();
  $user = User::create(['name' => $role_id, 'mail' => $role_id . '@example.invalid', 'status' => 1, 'roles' => [$role_id]]);
  $user->save();
  $switcher = \Drupal::service('account_switcher');
  $switcher->switchTo($user);
  $switched = TRUE;
  $before = (int) \Drupal::database()->select('nelkano_hu_sequence', 's')->countQuery()->execute()->fetchField();
  $first = HuWorkflow::save(NULL, NULL, 'new', 'HU de prueba <script>alert(1)</script>', "Descripción de prueba\nSegunda línea.");
  $ids[] = (int) $first->id();
  $check($first->bundle() === 'hu' && !$first->isPublished(), 'HU is private Drupal content');
  if ($before === 0) { $check(HuWorkflow::number($first) === '00000', 'first HU starts at 00000'); }
  $second = HuWorkflow::save(NULL, NULL, 'blocked', 'Segunda HU', 'Descripción');
  $ids[] = (int) $second->id();
  $check((int) $second->get('field_hu_number')->value === (int) $first->get('field_hu_number')->value + 1, 'HU sequence increments');
  $old_revision = (int) $first->getRevisionId();
  foreach (['in_progress', 'blocked', 'resolved', 'new'] as $status) {
    $first = HuWorkflow::save((int) $first->id(), (int) $first->getRevisionId(), $status);
    $check(WorkflowStatus::get($read($first->id())) === $status, 'persisted column ' . $status);
  }
  $same = HuWorkflow::save((int) $first->id(), (int) $first->getRevisionId(), 'new');
  $check($same->getRevisionId() === $first->getRevisionId(), 'same column is idempotent');
  try {
    HuWorkflow::save((int) $first->id(), $old_revision, 'blocked');
    throw new RuntimeException('Conflict was accepted');
  }
  catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) { $check(TRUE, 'stale revision rejected'); }
  try {
    HuWorkflow::save((int) $first->id(), (int) $first->getRevisionId(), 'rejected');
    throw new RuntimeException('Invalid HU state was accepted');
  }
  catch (InvalidArgumentException) { $check(TRUE, 'HU rejects report-only state'); }
  $number = HuWorkflow::number($first);
  $first->set('field_hu_number', 99999)->save();
  $check(HuWorkflow::number($read($first->id())) === $number, 'number is immutable');
  $second_number = (int) $second->get('field_hu_number')->value;
  $second->delete();
  $third = HuWorkflow::save(NULL, NULL, 'new', 'Tercera HU', 'Descripción');
  $ids[] = (int) $third->id();
  $check((int) $third->get('field_hu_number')->value === $second_number + 1, 'deleted HU number is not reused');
  $check(!$first->access('view', new \Drupal\Core\Session\AnonymousUserSession()), 'anonymous canonical node denied');
  $switcher->switchBack();
  $switched = FALSE;

  // Authenticate a restricted temporary account through the real HTTP kernel.
  $timestamp = time();
  $login = '/user/reset/' . $user->id() . '/' . $timestamp . '/' . user_pass_rehash($user, $timestamp) . '/login';
  $check($call('GET', $login)->getStatusCode() === 302, 'temporary account login');
  $response = $call('GET', '/admin/nelkano/backlog');
  $html = (string) $response->getBody();
  $check($response->getStatusCode() === 200, 'restricted backlog permission renders board');
  $check(substr_count($html, 'class="nk-hu-column"') === 4, 'four kanban columns');
  $check(strpos($html, 'Reportes de errores') < strpos($html, '<strong>Backlog'), 'backlog link below reports');
  $check(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>alert(1)</script>'), 'card title is escaped');
  $check($call('GET', '/admin/nelkano/backlog/add')->getStatusCode() === 200, 'HU creation form renders');
  $check($call('GET', '/admin/nelkano/backlog/' . $first->id() . '/edit')->getStatusCode() === 200, 'HU edit form renders');
  $path = '/admin/nelkano/backlog/' . $first->id() . '/status';
  $revision = (int) $read($first->id())->getRevisionId();
  $check($call('POST', $path, ['json' => ['status' => 'blocked', 'revision' => $revision]])->getStatusCode() === 403, 'missing CSRF denied');
  $token = (string) $call('GET', '/session/token')->getBody();
  $options = ['headers' => ['X-CSRF-Token' => $token], 'json' => ['status' => 'blocked', 'revision' => $revision]];
  $response = $call('POST', $path, $options);
  $check($response->getStatusCode() === 200 && WorkflowStatus::get($read($first->id())) === 'blocked', 'authenticated move persists');
  $check($call('POST', $path, $options)->getStatusCode() === 409, 'concurrent HTTP move rejected');
  $check($call('GET', $path)->getStatusCode() === 405, 'move is POST-only');
  $role->revokePermission(HuWorkflow::PERMISSION)->save();
  $check($call('GET', '/admin/nelkano/backlog')->getStatusCode() === 403, 'authenticated account without backlog permission denied');
  $check($call('POST', $path, $options)->getStatusCode() === 403, 'revoked permission denies moves');
  echo "SUCCESS: $checks backlog checks.\n";
}
finally {
  if ($switched) { \Drupal::service('account_switcher')->switchBack(); }
  foreach ($ids as $id) { $read($id)?->delete(); }
  $user?->delete();
  $role?->delete();
  echo "Synthetic HU and account removed; sequence intentionally preserved.\n";
}
