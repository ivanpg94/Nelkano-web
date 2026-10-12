<?php
/** Validate published releases before Composer modifies an existing installation. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
$directory = $argv[1] ?? $root . '/release-artifacts';
$manifest = json_decode(file_get_contents($directory . '/manifest.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$docs = \Symfony\Component\Yaml\Yaml::parseFile($root . '/config/sync/nelkano_home.docs.yml');
$required = [];
foreach (['es', 'en'] as $lang) {
  foreach ($docs[$lang]['releases_items'] ?? [] as $row) {
    $visible = $row['visible'] ?? TRUE;
    if ($visible === FALSE || in_array(strtolower(trim((string) $visible)), ['0', 'false', 'no', 'off'], TRUE)) continue;
    $uri = $row['apk_file'] ?? '';
    if ($uri !== '') $required[basename($uri)] = TRUE;
  }
}
foreach ($required as $name => $_) {
  $expected = $manifest[$name] ?? '';
  if (!preg_match('/\.(apk|msi|zip)$/D', $name) || !preg_match('/^[a-f0-9]{64}$/D', $expected)) throw new RuntimeException('Invalid published release manifest entry: ' . $name);
  $source = $directory . '/' . $name;
  if (!is_file($source) || !hash_equals($expected, hash_file('sha256', $source))) {
    throw new RuntimeException('Missing or corrupt published release artifact: ' . $name . '. Upload this file to release-artifacts/ or obtain its real Git LFS binary before deploying.');
  }
}
echo 'Published release bundle verified: ' . count($required) . PHP_EOL;