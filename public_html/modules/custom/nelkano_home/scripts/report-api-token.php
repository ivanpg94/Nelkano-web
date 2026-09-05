<?php

/** Run with drush php:script, never via an HTTP route. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) {
  http_response_code(404);
  exit;
}

use Drupal\nelkano_home\Service\ReportApiCredentials;

$client = getenv('NELKANO_REPORT_CLIENT') ?: '';
$action = getenv('NELKANO_REPORT_ACTION') ?: 'issue';
if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $client)) {
  throw new \InvalidArgumentException('Set NELKANO_REPORT_CLIENT (1-64 letters/digits/_/-).');
}
if ($action === 'revoke') {
  ReportApiCredentials::revoke($client);
  echo "Credential revoked for {$client}.\n";
}
elseif ($action === 'issue') {
  $days = filter_var(getenv('NELKANO_REPORT_DAYS') ?: '90', FILTER_VALIDATE_INT);
  if ($days === FALSE) {
    throw new \InvalidArgumentException('Invalid NELKANO_REPORT_DAYS.');
  }
  $token = ReportApiCredentials::issue($client, $days);
  echo json_encode(['client' => $client, 'token' => $token, 'expires_in_days' => $days], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
}
else {
  throw new \InvalidArgumentException('NELKANO_REPORT_ACTION must be issue or revoke.');
}
