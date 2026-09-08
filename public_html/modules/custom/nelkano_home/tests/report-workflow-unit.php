<?php

/** Pure offline workflow checks: php tests/report-workflow-unit.php. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once __DIR__ . '/../src/Service/ReportWorkflow.php';

use Drupal\nelkano_home\Service\ReportWorkflow;

$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException('FAIL: ' . $name);
  }
  $checks++;
};

foreach (['new', 'in_progress', 'resolved', 'rejected'] as $status) {
  $check(ReportWorkflow::validateUpdate(['status' => $status]) === [$status, NULL], 'legacy key remains supported');
  $check(ReportWorkflow::validateUpdate(['status' => $status, 'observations' => '']) === [$status, ''], 'explicit clear supported');
}
$check(ReportWorkflow::STATUSES['resolved'] === 'Terminado', 'resolved display label');
$check(ReportWorkflow::STATUSES['blocked'] === 'Bloqueado', 'blocked display label');
$reason = "No reproducido.\nFalta el dispositivo original.";
$check(ReportWorkflow::validateUpdate(['status' => 'blocked', 'observations' => $reason]) === ['blocked', $reason], 'multiline reason preserved');
$unicode = str_repeat('🎮', 4000);
$check(ReportWorkflow::validateUpdate(['status' => 'blocked', 'observations' => $unicode]) === ['blocked', $unicode], 'character limit is not a byte limit');

$invalid = [[], ['status' => 'finished'], ['status' => 'reviewing'], ['status' => []],
  ['status' => 'resolved', 'title' => 'overwrite'], ['observations' => 'missing status'],
  ['status' => 'blocked']];
foreach (['', '   ', "\n\t", "\u{00a0}", NULL, FALSE, 123, [], str_repeat('x', 4001)] as $notes) {
  $invalid[] = ['status' => 'blocked', 'observations' => $notes];
}
foreach ($invalid as $body) {
  try {
    ReportWorkflow::validateUpdate($body);
    throw new RuntimeException('Invalid update accepted');
  }
  catch (InvalidArgumentException $e) {
    $check(TRUE, 'invalid update rejected');
  }
}
echo "SUCCESS: {$checks} offline workflow checks.\n";
