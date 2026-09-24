<?php

/** Run with drush php:script, never via an HTTP route. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) {
  http_response_code(404);
  exit;
}

use Drupal\nelkano_home\Service\AppEntitlements;

// show: public key the app must pin.
// export-settings: settings.php line with the CURRENT seed, to move it out of
//   the database without changing the key the app already pins.
// forget-state: delete the database copy once settings.php provides the seed.
$action = getenv('NELKANO_SIGNING_ACTION') ?: 'show';

if ($action === 'show') {
  echo json_encode([
    'source' => AppEntitlements::keySource(),
    'kid' => AppEntitlements::keyId(),
    'public_key_base64' => AppEntitlements::publicKeyBase64(),
  ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
elseif ($action === 'export-settings') {
  if (AppEntitlements::keySource() !== 'state') {
    throw new \RuntimeException('The seed already comes from ' . AppEntitlements::keySource() . '.');
  }
  AppEntitlements::publicKeyBase64();
  $seed = (string) \Drupal::state()->get('nelkano_home.app_signing_seed');
  echo "// Nelkano app signing seed (kid " . AppEntitlements::keyId() . "). Keep secret, never commit.\n";
  echo "\$settings['nelkano_app_signing_seed'] = '" . $seed . "';\n";
}
elseif ($action === 'forget-state') {
  if (AppEntitlements::keySource() === 'state') {
    throw new \RuntimeException('settings.php does not provide the seed yet; refusing to delete the only copy.');
  }
  \Drupal::state()->delete('nelkano_home.app_signing_seed');
  echo "Database copy of the signing seed deleted.\n";
}
else {
  throw new \InvalidArgumentException('NELKANO_SIGNING_ACTION must be show, export-settings or forget-state.');
}
