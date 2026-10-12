<?php

/** Loopback Docker fixture used only by scripts/test-backlog-browser.cjs. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_BACKLOG_BROWSER_TEST') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') {
  throw new RuntimeException('Explicit local test opt-in required.');
}
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

$key = 'nelkano_backlog_browser_fixture';
$state = \Drupal::state();
$fixture = $state->get($key);
if (getenv('NELKANO_BACKLOG_BROWSER_ACTION') === 'cleanup') {
  if ($fixture) {
    foreach ($fixture['ids'] as $id) { Node::load($id)?->delete(); }
    User::load($fixture['uid'])?->delete();
    Role::load($fixture['role'])?->delete();
    $state->delete($key);
  }
  echo "Synthetic browser fixture removed.\n";
  return;
}
if (getenv('NELKANO_BACKLOG_BROWSER_ACTION') === 'admin-theme' && $fixture) {
  Role::load($fixture['role'])->grantPermission('view the administration theme')->save();
  echo "Synthetic account can now use the administration theme.\n";
  return;
}
if ($fixture) { throw new RuntimeException('Clean up previous test fixture before rerunning.'); }
$prefix = 'browserhu_' . bin2hex(random_bytes(4));
$role = Role::create(['id' => $prefix, 'label' => $prefix, 'permissions' => [HuWorkflow::PERMISSION, 'access content']]);
$role->save();
$user = User::create(['name' => $prefix, 'mail' => $prefix . '@example.invalid', 'status' => 1, 'roles' => [$prefix]]);
$user->save();
$fixture = ['prefix' => $prefix, 'uid' => (int) $user->id(), 'role' => $prefix, 'ids' => []];
$state->set($key, $fixture);
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($user);
try {
  foreach ([NULL, 0, 2] as $i => $sprint) {
    $node = HuWorkflow::save(NULL, NULL, 'new', $prefix . ' Emulación ' . $i, 'Sincronización descriptiva para AJAX.', ['sprint' => $sprint]);
    $fixture['ids'][] = (int) $node->id();
    $state->set($key, $fixture);
  }
  Index::load('nelkano_backlog')->indexItems();
}
finally { $switcher->switchBack(); }
$time = time();
$fixture['login'] = '/user/reset/' . $user->id() . '/' . $time . '/' . user_pass_rehash($user, $time) . '/login';
echo 'FIXTURE:' . json_encode($fixture, JSON_UNESCAPED_SLASHES) . "\n";
