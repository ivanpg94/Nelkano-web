<?php

/**
 * Export the current anonymous SDK book for Studio's offline documentation.
 *
 * SDK_REFERENCE_OUTPUT=/opt/drupal/content/sdk-reference.json \
 *   vendor/bin/drush php:script scripts/export-sdk-reference.php
 *
 * The destination directory must exist outside docroot. No content is changed.
 */
$output = getenv('SDK_REFERENCE_OUTPUT');
if (!is_string($output) || $output === '') {
  throw new InvalidArgumentException('Set SDK_REFERENCE_OUTPUT to an output JSON path outside docroot.');
}
$data = \Drupal::service('nelkano_home.sdk_reference')->write($output);
echo json_encode([
  'output' => $output,
  'revision' => $data['revision'],
  'chapters' => count($data['chapters']),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
