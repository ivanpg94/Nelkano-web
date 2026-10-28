<?php
/** Local integration: real upload controller, private storage, PC API and receipts. */
if (PHP_SAPI !== 'cli' || !class_exists('Drupal')) { exit(1); }
$db = \Drupal::database();
$options = $db->getConnectionOptions();
if (getenv('NELKANO_LOCAL_NATIVE_REPORT_TEST') !== '1' || $options['database'] !== 'nelkano_drupal' || $options['host'] !== 'database') {
  throw new RuntimeException('Local Docker verification only.');
}
function check_report($value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$transaction = $db->startTransaction();
$directories = [];
$temporary = [];
try {
  $account = \Drupal\user\Entity\User::load(1);
  $tokens = \Drupal\nelkano_home\Service\AppSessions::issue($account, ['device_id' => 'qa-native-save-report', 'device_model' => 'Local integration test']);
  $app_auth = 'Bearer ' . $tokens['access_token'];
  $pc_auth = 'Bearer ' . \Drupal\nelkano_home\Service\ReportApiCredentials::issue('qa_native_save_' . bin2hex(random_bytes(6)), 1);
  $app = new \Drupal\nelkano_home\Controller\AppAuthController();
  $api = new \Drupal\nelkano_home\Controller\ErrorReportApiController();
  $bytes = "\x04\x08original native save\x00\xff\r\n";
  $metadata = ['summary' => 'Local original save integration', 'steps' => 'Load the attached original game save.', 'system' => 'RPG_MAKER', 'rom_hash' => 'rpg_maker-0123456789abcdef', 'attachment_type' => 'native_save', 'state_format' => 'rpgmaker-native-save', 'native_save_filename' => 'Slot 1/Game ñ.rxdata', 'native_save_sha256' => hash('sha256', $bytes)];
  $request = \Symfony\Component\HttpFoundation\Request::create('/api/nelkano/error-reports', 'POST', ['metadata' => json_encode($metadata)]);
  $request->headers->set('Authorization', $app_auth);
  check_report($app->createErrorReport($request)->getStatusCode() === 400, 'Missing original save accepted');
  foreach ([TRUE, FALSE] as $native) {
    $data = $native ? $metadata : array_diff_key($metadata, array_flip(['attachment_type', 'native_save_filename', 'native_save_sha256']));
    if (!$native) { $data['slot'] = 3; $data['state_format'] = 'state'; $data['system'] = 'GBA'; $data['state_sha256'] = hash('sha256', $bytes); }
    $file = tempnam(sys_get_temp_dir(), 'report-native-'); $temporary[] = $file; file_put_contents($file, $bytes);
    $upload = new \Symfony\Component\HttpFoundation\File\UploadedFile($file, $native ? 'Game ñ.rxdata' : 'state.bin', 'application/octet-stream', NULL, TRUE);
    $request = \Symfony\Component\HttpFoundation\Request::create('/api/nelkano/error-reports', 'POST', ['metadata' => json_encode($data)], [], [$native ? 'native_save' : 'state' => $upload]);
    $request->headers->set('Authorization', $app_auth);
    $response = $app->createErrorReport($request);
    check_report($response->getStatusCode() === 201, 'Upload rejected: ' . $response->getContent());
    $created = json_decode($response->getContent(), TRUE)['report'];
    $node = \Drupal\node\Entity\Node::load($created['id']);
    $directories[] = dirname(\Drupal::service('file_system')->realpath($node->get('field_report_state_uri')->value));
    $get = \Symfony\Component\HttpFoundation\Request::create('/'); $get->headers->set('Authorization', $pc_auth);
    $detail = json_decode($api->detail($get, $created['id'])->getContent(), TRUE);
    $kind = $native ? 'native_save' : 'state';
    check_report(array_keys($detail['attachments']) === [$kind, 'screenshot'], 'Wrong attachment kind');
    check_report($detail['attachments'][$kind]['sha256'] === hash('sha256', $bytes), 'Checksum changed');
    check_report($detail['attachments'][$kind]['filename'] === ($native ? 'Game ñ.rxdata' : 'slot-3.bin'), 'Original filename lost');
    check_report($native ? !isset($detail['metadata']['slot']) : $detail['metadata']['slot'] === 3, 'Native save exposed as a slot');
    $download = $api->download($get, $created['id'], $kind);
    check_report(file_get_contents($download->getFile()->getPathname()) === $bytes, 'Original bytes changed');
    check_report($download->headers->get('X-Checksum-SHA256') === hash('sha256', $bytes), 'Download checksum missing');
    if ($native) { check_report($api->download($get, $created['id'], 'state')->getStatusCode() === 404, 'Original save exposed as a savestate'); }
    $ack = ['manifest_sha256' => $detail['manifest_sha256'], $kind . '_sha256' => hash('sha256', $bytes)];
    $post = \Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], [], [], json_encode($ack)); $post->headers->set('Authorization', $pc_auth);
    $receipt = json_decode($api->receipt($post, $created['id'])->getContent(), TRUE);
    check_report(($receipt['status'] ?? '') === 'in_progress' && $receipt['receipt'][$kind . '_sha256'] === hash('sha256', $bytes), 'Receipt not verified');
  }
  foreach (['../Game.rxdata', '/Game.rxdata', "x\r\n.rxdata", 'log.txt', 'C:\\Game.rxdata'] as $name) {
    check_report(!\Drupal\nelkano_home\Service\ReportAttachment::validSaveName($name), 'Unsafe filename accepted');
  }
  echo "PASS: native and legacy uploads, original Unicode filename/bytes, private download kind, SHA-256 and receipts. Local transaction rolled back.\n";
} finally {
  foreach ($directories as $directory) { foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); } rmdir($directory); }
  foreach ($temporary as $path) { if (is_file($path)) { unlink($path); } }
  $transaction->rollBack();
}
