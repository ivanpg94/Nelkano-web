<?php
/** HTTP form/batch regression using only a newly created report and account. */
if (PHP_SAPI !== 'cli' || getenv('NELKANO_LOCAL_REPORT_ACTIONS_TEST') !== '1') { exit; }

use Drupal\nelkano_home\Service\ReportDeletion;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;

$check = static function ($ok, $label) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  echo "PASS: $label\n";
};
$jar = new CookieJar();
$http = \Drupal::httpClient();
$call = static fn($method, $path, $options = []) => $http->request($method, 'http://127.0.0.1' . $path,
  $options + ['cookies' => $jar, 'http_errors' => FALSE, 'allow_redirects' => FALSE, 'timeout' => 25]);
$role = $user = $node = $term = NULL;
$dir = '';
try {
  $name = 'report_http_' . bin2hex(random_bytes(5));
  $role = Role::create(['id' => $name, 'label' => $name, 'permissions' => [
    'access content', 'administer nelkano error reports', 'change nelkano error report status', ReportDeletion::PERMISSION]]);
  $role->save();
  $user = User::create(['name' => $name, 'mail' => $name . '@example.invalid', 'status' => 1, 'roles' => [$name]]);
  $user->save();
  $term = Term::create(['vid' => WorkflowStatus::VOCABULARY, 'name' => $name]); $term->save();
  $dir = 'private://nelkano-error-reports/' . \Drupal::service('uuid')->generate();
  \Drupal::service('file_system')->prepareDirectory($dir, 1);
  file_put_contents($dir . '/state.bin', 'temporary HTTP fixture');
  chown(\Drupal::service('file_system')->realpath($dir), 'www-data');
  chown(\Drupal::service('file_system')->realpath($dir . '/state.bin'), 'www-data');
  $node = Node::create(['type' => 'nelkano_error_report', 'title' => $name, 'uid' => $user->id(), 'status' => 0,
    WorkflowStatus::FIELD => WorkflowStatus::termId('new'), 'field_report_state_uri' => $dir . '/state.bin']);
  $node->save(); $id = (int) $node->id();
  $timestamp = time();
  $login = '/user/reset/' . $user->id() . '/' . $timestamp . '/' . user_pass_rehash($user, $timestamp) . '/login';
  $check($call('GET', $login)->getStatusCode() === 302, 'temporary restricted account signs in');
  $path = '/admin/nelkano/error-reports';
  $read_form = static function () use ($call, $path, $id) {
    $response = $call('GET', $path);
    $document = new DOMDocument(); @$document->loadHTML((string) $response->getBody());
    $xpath = new DOMXPath($document);
    $form = $xpath->query('//form[starts-with(@id, "views-form-nelkano-error-reports")]')->item(0);
    if (!$form) { throw new RuntimeException('Views form missing.'); }
    $values = [];
    foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
      $values[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    $checkbox = $xpath->query('.//input[@type="checkbox"][@value="' . $id . '"]', $form)->item(0);
    if (!$checkbox) { throw new RuntimeException('Synthetic report checkbox missing.'); }
    $values[$checkbox->getAttribute('name')] = (string) $id;
    return $values;
  };
  $values = $read_form();
  $values['target_status'] = 'term:' . $term->id();
  $values['op'] = 'Aplicar a los seleccionados';
  $response = $call('POST', $path, ['form_params' => $values]);
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
  $check((int) Node::load($id)->get(WorkflowStatus::FIELD)->target_id === (int) $term->id(), 'native HTTP submit applies custom term');
  $values = $read_form();
  $values['target_status'] = '';
  $values['delete_selected'] = 'Eliminar seleccionados';
  $bad = $values; $bad['form_token'] = 'invalid';
  $call('POST', $path, ['form_params' => $bad]);
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
  $check(Node::load($id) !== NULL && is_file($dir . '/state.bin'), 'invalid CSRF cannot delete node or file');
  $values = $read_form();
  $values['target_status'] = '';
  $values['delete_selected'] = 'Eliminar seleccionados';
  $response = $call('POST', $path, ['form_params' => $values]);
  $location = $response->getHeaderLine('Location');
  $check(in_array($response->getStatusCode(), [302, 303], TRUE) && str_contains($location, '/batch'), 'delete button starts native batch without a target state');
  $url = parse_url($location);
  parse_str($url['query'] ?? '', $query);
  $query['op'] = 'do';
  $progress = json_decode((string) $call('POST', $url['path'] . '?' . http_build_query($query))->getBody(), TRUE);
  $check(($progress['percentage'] ?? 0) == 100, 'native batch completes selected deletion');
  $query['op'] = 'finished';
  $call('GET', $url['path'] . '?' . http_build_query($query));
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
  clearstatcache();
  $check(Node::load($id) === NULL && !is_dir($dir), 'HTTP action removes selected node and physical directory');
  echo "SUCCESS: HTTP state-change, CSRF and deletion batch checks.\n";
}
finally {
  if ($node) { \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]); Node::load($node->id())?->delete(); }
  if ($dir && is_dir($dir)) { \Drupal::service('file_system')->deleteRecursive($dir); }
  $term?->delete(); $user?->delete(); $role?->delete();
}
