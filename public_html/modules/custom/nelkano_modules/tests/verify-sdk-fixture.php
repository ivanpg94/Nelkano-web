<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/Service/PackageVerifier.php';
$path = $argv[1] ?? '';
if (!str_starts_with($path, '/tmp/nelkano-sdk-fixture-') || !is_file($path) || filesize($path) > \Drupal\nelkano_modules\Service\PackageVerifier::MAX_ARCHIVE) {
  throw new RuntimeException('Only bounded temporary test fixture paths accepted.');
}
echo json_encode((new \Drupal\nelkano_modules\Service\PackageVerifier())->verify(file_get_contents($path)), JSON_UNESCAPED_SLASHES) . "\n";