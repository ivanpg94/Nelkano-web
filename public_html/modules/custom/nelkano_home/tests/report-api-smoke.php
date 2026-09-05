<?php

/** Integration test against the local Drupal HTTP server, run via Drush. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) {
  http_response_code(404);
  exit;
}

use Drupal\Core\File\FileSystemInterface;
use Drupal\nelkano_home\Service\ReportApiCredentials;
use Drupal\nelkano_home\Service\ReportAttachment;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

$base = getenv('NELKANO_REPORT_TEST_URL') ?: 'http://127.0.0.1';
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], TRUE)) {
  throw new RuntimeException('This smoke test only runs against a loopback server.');
}
$client = 'smoke-' . bin2hex(random_bytes(8));
$token = ReportApiCredentials::issue($client, 1);
$second_client = $client . '-other';
$node = NULL;
$directory = '';
$saved_paths = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException('FAIL: ' . $label);
  }
  $checks++;
  echo 'PASS: ' . $label . "\n";
};
$http = \Drupal::httpClient();
$call = static function (string $method, string $path, ?string $credential = NULL, ?array $json = NULL) use ($base, $http) {
  $options = ['http_errors' => FALSE, 'allow_redirects' => FALSE, 'timeout' => 30];
  if ($credential !== NULL) {
    $options['headers']['Authorization'] = 'Bearer ' . $credential;
  }
  if ($json !== NULL) {
    $options['json'] = $json;
  }
  return $http->request($method, $base . $path, $options);
};
$decode = static fn ($response): array => json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
$api = '/api/nelkano/v1/error-reports';

try {
  $repository = \Drupal::service('entity_display.repository');
  $form = $repository->getFormDisplay('node', 'nelkano_error_report');
  $view = $repository->getViewDisplay('node', 'nelkano_error_report');
  $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'nelkano_error_report');
  $count = 0;
  foreach ($definitions as $name => $definition) {
    if (str_starts_with($name, 'field_report_')) {
      $check($form->getComponent($name) !== NULL && $view->getComponent($name) !== NULL, 'visible field ' . $name);
      $count++;
    }
  }
  $check($count === 25, 'all 25 report fields configured');
  $check($definitions['field_report_status']->getSetting('allowed_values') === ['new' => 'Nuevo', 'in_progress' => 'En proceso', 'resolved' => 'Resuelto', 'rejected' => 'Descartado'], 'exactly the four requested states');

  $check($call('GET', $api)->getStatusCode() === 401, 'anonymous list denied');
  $check($call('GET', $api, 'player-token')->getStatusCode() === 401, 'non-PC token denied');
  $check($call('GET', $api . '?token=' . $token)->getStatusCode() === 401, 'query-string token ignored');
  $check($call('GET', $api . '?limit=101', $token)->getStatusCode() === 400, 'page bound enforced');
  $check($call('GET', $api . '?after_id[]=1', $token)->getStatusCode() === 400, 'array cursor rejected');
  $check($call('GET', $api . '?after_id=-1', $token)->getStatusCode() === 400, 'negative cursor rejected');
  $check($call('GET', $api . '/0', $token)->getStatusCode() === 404, 'missing report returns 404');

  $fs = \Drupal::service('file_system');
  $directory = 'private://nelkano-error-reports/' . \Drupal::service('uuid')->generate();
  $check($fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY), 'fixture directory created');
  $state = 'Nelkano API test: synthetic state, no ROM.';
  $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
  $saved_paths[] = $directory . '/slot-1.teststate';
  $saved_paths[] = $directory . '/screenshot.png';
  $fs->saveData($state, $saved_paths[0]);
  $fs->saveData($png, $saved_paths[1]);
  $node = Node::create([
    'type' => 'nelkano_error_report', 'title' => $client, 'uid' => 1, 'status' => 0,
    'body' => ['value' => 'Pasos de prueba', 'format' => 'plain_text'],
    'field_report_status' => 'new', 'field_report_category' => 'other',
    'field_report_system' => 'TEST', 'field_report_rom_hash' => 'synthetic-no-rom',
    'field_report_slot' => 1, 'field_report_settings' => '{"test":true}',
    'field_report_state_uri' => $saved_paths[0], 'field_report_state_name' => 'slot-1.teststate',
    'field_report_state_sha256' => hash('sha256', $state), 'field_report_state_size' => strlen($state),
    'field_report_screenshot_uri' => $saved_paths[1],
  ]);
  $node->save();
  $path = $api . '/' . $node->id();

  foreach ([$path, $path . '/files/state', $path . '/files/screenshot'] as $protected) {
    $check($call('GET', $protected)->getStatusCode() === 401, 'anonymous denied ' . $protected);
  }
  $check($call('POST', $path . '/receipt', NULL, [])->getStatusCode() === 401, 'anonymous receipt denied');
  $check($call('PATCH', $path . '/status', NULL, ['status' => 'resolved'])->getStatusCode() === 401, 'anonymous status change denied');
  $check($call('PATCH', $path . '/status', 'player-token', ['status' => 'resolved'])->getStatusCode() === 401, 'player status change denied');
  $check($call('PATCH', $path . '/status', $token, ['status' => 'reviewing'])->getStatusCode() === 400, 'legacy status rejected');
  $check($call('PATCH', $path . '/status', $token, ['status' => 'resolved', 'title' => 'overwrite'])->getStatusCode() === 400, 'status endpoint cannot edit other fields');
  $check($call('PATCH', $api . '/0/status', $token, ['status' => 'resolved'])->getStatusCode() === 404, 'status of missing report returns 404');
  $check($call('DELETE', $path, $token)->getStatusCode() === 405, 'report mutation not exposed');
  $response = $call('GET', $path, $token);
  $check($response->getStatusCode() === 200, 'unpublished report accessible with PC credential');
  $manifest = $decode($response);
  $check(str_contains($response->getHeaderLine('Cache-Control'), 'no-store'), 'private manifest not cached');
  $check(count($manifest['metadata']) === 23 && $manifest['steps'] === 'Pasos de prueba', 'full metadata and reproduction steps returned');
  $check(!str_contains(json_encode($manifest), 'private://'), 'filesystem URIs not exposed');
  $check($manifest['receipt'] === NULL, 'not received initially');
  $check($call('GET', $path)->getStatusCode() === 401, 'authenticated response not leaked through cache');

  $receipt = ['manifest_sha256' => $manifest['manifest_sha256']];
  foreach (['state' => $state, 'screenshot' => $png] as $kind => $bytes) {
    $response = $call('GET', $manifest['attachments'][$kind]['download_url'], $token);
    $actual = (string) $response->getBody();
    $check($response->getStatusCode() === 200 && $actual === $bytes, 'exact downloaded bytes: ' . $kind);
    $hash = hash('sha256', $actual);
    $check($hash === $manifest['attachments'][$kind]['sha256'] && strlen($actual) === $manifest['attachments'][$kind]['size'], 'manifest integrity: ' . $kind);
    $check($response->getHeaderLine('X-Checksum-SHA256') === $hash, 'checksum header: ' . $kind);
    $check(str_contains($response->getHeaderLine('Cache-Control'), 'no-store'), 'private file not cached: ' . $kind);
    $receipt[$kind . '_sha256'] = $hash;
  }
  $check($call('POST', $path . '/receipt', $token, [])->getStatusCode() === 400, 'incomplete receipt rejected');
  $bad = $receipt;
  $bad['state_sha256'] = str_repeat('0', 64);
  $check($call('POST', $path . '/receipt', $token, $bad)->getStatusCode() === 409, 'wrong hash rejected');
  $check($decode($call('GET', $path, $token))['metadata']['status'] === 'new', 'downloads and failed receipt leave report new');
  $first = $call('POST', $path . '/receipt', $token, $receipt);
  $check($first->getStatusCode() === 200, 'receipt accepted');
  $check($decode($first) === $decode($call('POST', $path . '/receipt', $token, $receipt)), 'receipt idempotent');
  $check($decode($call('GET', $path, $token))['receipt'] !== NULL, 'receipt visible to its PC');
  $check($decode($call('GET', $path, $token))['metadata']['status'] === 'in_progress', 'complete receipt moves report to in progress');
  $check($decode($call('GET', $api . '?after_id=' . ($node->id() - 1), $token))['items'] === [], 'processed report excluded from new queue');
  $other = ReportApiCredentials::issue($second_client, 1);
  $check($decode($call('GET', $path, $other))['receipt'] === NULL, 'receipt isolated by PC');
  $check($call('POST', $path . '/receipt', $other, $receipt)->getStatusCode() === 409, 'second PC cannot claim processed report');
  $check($call('PATCH', $path . '/status', $other, ['status' => 'resolved'])->getStatusCode() === 200, 'separate automation credential can resolve');
  $check($decode($call('GET', $path, $token))['metadata']['status'] === 'resolved', 'resolved persisted');
  $revision = $decode($call('GET', $path, $token))['revision_id'];
  $check($call('PATCH', $path . '/status', $other, ['status' => 'resolved'])->getStatusCode() === 200 && $decode($call('GET', $path, $token))['revision_id'] === $revision, 'status retry does not create duplicate revision');
  $check($decode($call('POST', $path . '/receipt', $token, $receipt))['status'] === 'resolved', 'late receipt never reopens resolved report');
  $check($call('PATCH', $path . '/status', $other, ['status' => 'rejected'])->getStatusCode() === 200, 'automation can discard');
  $check($decode($call('GET', $api . '?after_id=' . ($node->id() - 1), $token))['items'] === [], 'discarded report excluded');
  $check($decode($call('POST', $path . '/receipt', $token, $receipt))['status'] === 'rejected', 'late receipt never reopens discarded report');
  $check($call('PATCH', $path . '/status', $token, ['status' => 'in_progress'])->getStatusCode() === 200, 'in progress accepted by status endpoint');
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  $node = Node::load($node->id());
  $node->setTitle($client . '-edited')->save();
  $check($decode($call('GET', $path, $token))['receipt'] === NULL, 'edit invalidates old receipt');
  $check($call('POST', $path . '/receipt', $token, $receipt)->getStatusCode() === 409, 'stale manifest rejected');
  $check($decode($call('GET', $path, $token))['metadata']['status'] === 'in_progress', 'invalid receipt preserves in progress');
  $check($call('PATCH', $path . '/status', $token, ['status' => 'new'])->getStatusCode() === 200, 'report can be returned to new');

  $cursor = 0;
  $seen = [];
  do {
    $response = $call('GET', $api . '?limit=1&after_id=' . $cursor, $token);
    $check($response->getStatusCode() === 200, 'paginated request accepted');
    $page = $decode($response);
    foreach ($page['items'] as $item) {
      $check($item['status'] === 'new', 'queue contains only new reports');
      $check($item['id'] > $cursor && !isset($seen[$item['id']]), 'ordered unique page item');
      $seen[$item['id']] = TRUE;
    }
    $cursor = $page['next_after_id'];
  } while ($page['has_more']);
  $check(isset($seen[$node->id()]), 'pagination includes fixture');
  $check(isset($seen[$node->id()]), 'reopened report appears on next poll from zero');
  $check($decode($call('GET', $api . '?after_id=' . $cursor, $token))['items'] === [], 'empty final page');

  $current = $decode($call('GET', $path, $token));
  $current_receipt = ['manifest_sha256' => $current['manifest_sha256']];
  foreach ($current['attachments'] as $kind => $file) {
    if ($file !== NULL) {
      $current_receipt[$kind . '_sha256'] = $file['sha256'];
    }
  }
  $pending = [];
  foreach ([$token, $other] as $credential) {
    $pending[] = $http->requestAsync('POST', $base . $path . '/receipt', [
      'http_errors' => FALSE, 'timeout' => 30,
      'headers' => ['Authorization' => 'Bearer ' . $credential], 'json' => $current_receipt,
    ]);
  }
  $responses = \GuzzleHttp\Promise\Utils::unwrap($pending);
  $codes = array_map(static fn ($response) => $response->getStatusCode(), $responses);
  sort($codes);
  $check($codes === [200, 409], 'concurrent PCs: only one receipt accepted');
  $check($decode($call('GET', $path, $token))['metadata']['status'] === 'in_progress', 'concurrent receipt persists in progress');
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  $node = Node::load($node->id());

  // Read-only check of one pre-existing report. Never acknowledge or edit it.
  foreach (array_keys($seen) as $existing_id) {
    if ((int) $existing_id === (int) $node->id()) {
      continue;
    }
    $response = $call('GET', $api . '/' . $existing_id, $token);
    $check($response->getStatusCode() === 200, 'existing report detail ' . $existing_id);
    $existing = $decode($response);
    $file = $existing['attachments']['state'];
    if ($file !== NULL && $file['size'] <= 8 * 1024 * 1024) {
      $response = $call('GET', $file['download_url'], $token);
      $bytes = (string) $response->getBody();
      $check($response->getStatusCode() === 200 && strlen($bytes) === $file['size'] && hash('sha256', $bytes) === $file['sha256'], 'existing real slot downloaded and verified ' . $existing_id);
    }
    break;
  }

  // Render real widgets and formatters, not only configuration records.
  $switcher = \Drupal::service('account_switcher');
  $switcher->switchTo(User::load(1));
  try {
    $build = \Drupal::service('entity.form_builder')->getForm($node);
    $html = (string) \Drupal::service('renderer')->renderRoot($build);
    $check(str_contains($html, 'field_report_system') && str_contains($html, 'field_report_logs'), 'node edit renders technical inputs');
    $build = \Drupal::entityTypeManager()->getViewBuilder('node')->view($node);
    $html = (string) \Drupal::service('renderer')->renderRoot($build);
    $check(str_contains($html, '/admin/nelkano/error-reports/' . $node->id() . '/state'), 'node display renders slot download link');
  }
  finally {
    $switcher->switchBack();
  }

  foreach (['/etc/passwd', 'public://test', 'private://../settings.php', $directory . '/../secrets'] as $uri) {
    try {
      ReportAttachment::path($uri);
      throw new RuntimeException('Unsafe path accepted');
    }
    catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
      $check(TRUE, 'unsafe attachment URI denied');
    }
  }
  $node->set('field_report_screenshot_uri', '')->save();
  $check($decode($call('GET', $path, $token))['attachments']['screenshot'] === NULL, 'optional screenshot absent');
  $check($call('GET', $path . '/files/screenshot', $token)->getStatusCode() === 404, 'absent screenshot returns 404');

  $replacement = ReportApiCredentials::issue($client, 1);
  $check($call('GET', $api, $token)->getStatusCode() === 401, 'rotation invalidates previous credential');
  $check($call('GET', $api, $replacement)->getStatusCode() === 200, 'rotated credential works');
  $store = \Drupal::keyValue('nelkano_report_api_tokens');
  $record = $store->get(hash('sha256', $replacement));
  $record['expires'] = time() - 1;
  $store->set(hash('sha256', $replacement), $record);
  $check($call('GET', $api, $replacement)->getStatusCode() === 401, 'expired credential denied');
  $replacement = ReportApiCredentials::issue($client, 1);
  ReportApiCredentials::revoke($client);
  $check($call('GET', $api, $replacement)->getStatusCode() === 401, 'revoked credential denied');
  echo "SUCCESS: {$checks} checks.\n";
}
finally {
  ReportApiCredentials::revoke($client);
  ReportApiCredentials::revoke($second_client);
  if ($node && !$node->isNew()) {
    foreach ([$client, $second_client] as $name) {
      \Drupal::keyValue('nelkano_report_api_receipts')->delete($name . ':' . $node->uuid());
    }
    $node->delete();
  }
  foreach ($saved_paths as $uri) {
    if (is_file($uri)) {
      \Drupal::service('file_system')->delete($uri);
    }
  }
  if ($directory !== '' && is_dir($directory)) {
    \Drupal::service('file_system')->rmdir($directory);
  }
  echo "Temporary fixture and credentials cleaned up.\n";
}
