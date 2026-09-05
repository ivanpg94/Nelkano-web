<?php

declare(strict_types=1);

// Migration from a Composer-managed path package to a Git-managed module.
// Composer may delete the OLD installed path when removing that package.
// Preserve the freshly deployed Git code before Composer performs that removal.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$root = dirname(__DIR__);
$composer = json_decode(file_get_contents($root . '/composer.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$webRoot = trim($composer['extra']['drupal-scaffold']['locations']['web-root'], '/');
if (!in_array($webRoot, ['public_html', 'web'], TRUE)) {
  throw new RuntimeException('Unexpected Drupal docroot; no files were changed.');
}
$module = $root . '/' . $webRoot . '/modules/custom/nelkano_home';
$marker = $root . '/.nelkano-module-guard.json';

function nelkanoCopyModule(string $source, string $destination): void {
  if (!is_dir($destination) && !mkdir($destination, 0755, TRUE) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create module destination.');
  }
  foreach (new DirectoryIterator($source) as $entry) {
    if ($entry->isDot()) {
      continue;
    }
    if ($entry->isLink()) {
      throw new RuntimeException('Refusing to follow a symlink inside the module.');
    }
    $target = $destination . '/' . $entry->getFilename();
    if (is_link($target)) {
      throw new RuntimeException('Refusing to overwrite a symlink.');
    }
    if ($entry->isDir()) {
      nelkanoCopyModule($entry->getPathname(), $target);
    }
    elseif (!copy($entry->getPathname(), $target) || hash_file('sha256', $entry->getPathname()) !== hash_file('sha256', $target)) {
      throw new RuntimeException('Module copy failed integrity verification.');
    }
  }
}

$operation = $argv[1] ?? '';
if ($operation === 'backup') {
  if (is_file($marker)) {
    throw new RuntimeException('A previous Composer migration is pending. Run php scripts/legacy-module-guard.php restore before retrying.');
  }
  $installedFile = $root . '/vendor/composer/installed.json';
  if (!is_file($installedFile)) {
    exit(0);
  }
  $installed = json_decode(file_get_contents($installedFile), TRUE, 512, JSON_THROW_ON_ERROR);
  foreach ($installed['packages'] ?? $installed as $package) {
    if ($package['name'] !== 'nelkano/nelkano_home') {
      continue;
    }
    $oldPath = realpath(dirname($installedFile) . '/' . ($package['install-path'] ?? ''));
    if (!$oldPath || $oldPath !== realpath($module)) {
      // Docker formerly installed an inactive copy in local-packages/.
      exit(0);
    }
    $backup = sys_get_temp_dir() . '/nelkano-module-' . bin2hex(random_bytes(12));
    if (!mkdir($backup, 0700)) {
      throw new RuntimeException('Cannot create recovery directory.');
    }
    nelkanoCopyModule($module, $backup . '/module');
    if (file_put_contents($marker, json_encode(['module' => $module, 'backup' => $backup], JSON_THROW_ON_ERROR), LOCK_EX) === FALSE) {
      throw new RuntimeException('Cannot record recovery directory; Composer must not proceed.');
    }
    echo 'Nelkano: deployed module protected before removing the legacy Composer package.' . PHP_EOL;
    break;
  }
}
elseif ($operation === 'restore') {
  if (!is_file($marker)) {
    exit(0);
  }
  $record = json_decode(file_get_contents($marker), TRUE, 512, JSON_THROW_ON_ERROR);
  $tempRoot = rtrim(sys_get_temp_dir(), '/\\');
  if ($record['module'] !== $module || !preg_match('@^' . preg_quote($tempRoot, '@') . '/nelkano-module-[a-f0-9]{24}$@D', $record['backup'])) {
    throw new RuntimeException('Invalid recovery record; no files were changed.');
  }
  nelkanoCopyModule($record['backup'] . '/module', $module);
  unlink($marker);
  echo 'Nelkano: module restored from deployed code. Recovery copy: ' . $record['backup'] . PHP_EOL;
}
else {
  throw new InvalidArgumentException('Expected backup or restore.');
}
