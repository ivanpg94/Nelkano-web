<?php
/** Reassemble release installers transported through ordinary Git, before deploy. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$directory = realpath($argv[1] ?? dirname(__DIR__) . '/release-artifacts');
if ($directory === FALSE) throw new RuntimeException('Release directory missing.');
$indexPath = $directory . '/parts.json';
if (!is_file($indexPath)) return;
$index = json_decode(file_get_contents($indexPath), TRUE, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents($directory . '/manifest.json'), TRUE, 512, JSON_THROW_ON_ERROR);
if (($index['schemaVersion'] ?? NULL) !== 1 || !is_array($index['artifacts'] ?? NULL)) {
  throw new RuntimeException('Invalid release parts index.');
}
$names = [];
foreach ($index['artifacts'] as $artifact) {
  $name = $artifact['file'] ?? '';
  $expected = $artifact['sha256'] ?? '';
  $bytes = $artifact['bytes'] ?? 0;
  if (!is_string($name) || $name === '' || basename($name) !== $name || str_contains($name, '\\')
      || !preg_match('/\.(exe|msi|zip|apk)$/D', $name) || isset($names[$name])
      || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)
      || ($manifest[$name] ?? '') !== $expected || !is_int($bytes) || $bytes <= 0) {
    throw new RuntimeException('Invalid release parts metadata.');
  }
  $names[$name] = TRUE;
  $target = $directory . '/' . $name;
  if (is_link($target)) throw new RuntimeException('Release target cannot be a link: ' . $name);
  if (is_file($target) && filesize($target) === $bytes && hash_equals($expected, hash_file('sha256', $target))) {
    echo 'Release ready: ' . $name . PHP_EOL;
    continue;
  }
  $parts = $artifact['parts'] ?? [];
  if (!is_array($parts) || !$parts) throw new RuntimeException('Missing parts: ' . $name);
  $temporary = tempnam($directory, '.assemble-');
  if ($temporary === FALSE) throw new RuntimeException('Cannot create release temporary.');
  $output = NULL;
  try {
    $output = fopen($temporary, 'wb');
    if ($output === FALSE) throw new RuntimeException('Cannot open release temporary.');
    $wholeHash = hash_init('sha256');
    $total = 0;
    foreach ($parts as $number => $part) {
      $relative = $part['file'] ?? '';
      $partBytes = $part['bytes'] ?? 0;
      $partHash = $part['sha256'] ?? '';
      $requiredPath = 'parts/' . $expected . '/' . sprintf('%04d', $number + 1) . '.part';
      if ($relative !== $requiredPath || !is_int($partBytes) || $partBytes < 1 || $partBytes > 41943040
          || !is_string($partHash) || !preg_match('/^[a-f0-9]{64}$/D', $partHash)) {
        throw new RuntimeException('Invalid part metadata: ' . $name);
      }
      $path = realpath($directory . '/' . $relative);
      if ($path === FALSE || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)
          || !is_file($path) || filesize($path) !== $partBytes) {
        throw new RuntimeException('Missing release part: ' . $relative . '. Complete git pull before deploying.');
      }
      $input = fopen($path, 'rb');
      if ($input === FALSE) throw new RuntimeException('Cannot read release part: ' . $relative);
      $partDigest = hash_init('sha256');
      $copied = 0;
      try {
        while (!feof($input)) {
          $block = fread($input, 1048576);
          if ($block === FALSE || ($block === '' && !feof($input))) throw new RuntimeException('Cannot read part.');
          if ($block === '') break;
          hash_update($partDigest, $block);
          hash_update($wholeHash, $block);
          $length = strlen($block);
          for ($offset = 0; $offset < $length;) {
            $written = fwrite($output, substr($block, $offset));
            if ($written === FALSE || $written === 0) throw new RuntimeException('Cannot write release installer.');
            $offset += $written;
          }
          $copied += $length;
        }
      } finally { fclose($input); }
      if ($copied !== $partBytes || !hash_equals($partHash, hash_final($partDigest))) {
        throw new RuntimeException('Corrupt release part: ' . $relative);
      }
      $total += $copied;
      if ($total > $bytes) throw new RuntimeException('Release parts exceed expected size.');
    }
    if ($total !== $bytes || !hash_equals($expected, hash_final($wholeHash))) {
      throw new RuntimeException('Reassembled installer does not match manifest: ' . $name);
    }
    fclose($output); $output = NULL;
    if (!chmod($temporary, 0644) || !rename($temporary, $target)) throw new RuntimeException('Cannot publish installer: ' . $name);
    $temporary = NULL;
    echo 'Reassembled release: ' . $name . PHP_EOL;
  } finally {
    if (is_resource($output)) fclose($output);
    if ($temporary !== NULL && is_file($temporary)) unlink($temporary);
  }
}
