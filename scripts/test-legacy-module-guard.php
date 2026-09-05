<?php

declare(strict_types=1);

// Standalone filesystem-only test. Does not connect to a database or server.
if (PHP_SAPI !== 'cli') {
  exit(1);
}
$fixture = sys_get_temp_dir() . '/nelkano-guard-test-' . bin2hex(random_bytes(8));
$module = $fixture . '/public_html/modules/custom/nelkano_home';
mkdir($module . '/src/Controller', 0755, TRUE);
mkdir($fixture . '/scripts', 0755, TRUE);
mkdir($fixture . '/vendor/composer', 0755, TRUE);
copy(__DIR__ . '/legacy-module-guard.php', $fixture . '/scripts/legacy-module-guard.php');
file_put_contents($fixture . '/composer.json', json_encode(['extra' => ['drupal-scaffold' => ['locations' => ['web-root' => 'public_html/']]]], JSON_THROW_ON_ERROR));
file_put_contents($fixture . '/vendor/composer/installed.json', json_encode(['packages' => [[
  'name' => 'nelkano/nelkano_home', 'install-path' => '../../public_html/modules/custom/nelkano_home',
]]], JSON_THROW_ON_ERROR));
$content = '<?php // Newly deployed report controller: must survive Composer uninstall.';
file_put_contents($module . '/src/Controller/ErrorReportApiController.php', $content);
file_put_contents($module . '/asset.bin', "\x00\x01\xffunique-asset");
$run = static function (string $action) use ($fixture): void {
  $process = proc_open([PHP_BINARY, $fixture . '/scripts/legacy-module-guard.php', $action], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixture);
  $out = stream_get_contents($pipes[1]);
  $err = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  if (proc_close($process) !== 0) {
    throw new RuntimeException($err . $out);
  }
  echo $out;
};
$run('backup');
if (!is_file($fixture . '/.nelkano-module-guard.json')) {
  throw new RuntimeException('Legacy installation was not protected.');
}
// Simulate removal without deleting even the synthetic old directory.
rename($module, $fixture . '/removed-by-composer');
file_put_contents($fixture . '/vendor/composer/installed.json', '{"packages":[]}');
$run('restore');
if (file_get_contents($module . '/src/Controller/ErrorReportApiController.php') !== $content || file_get_contents($module . '/asset.bin') !== "\x00\x01\xffunique-asset") {
  throw new RuntimeException('The deployed code or asset was not restored exactly.');
}
if (is_file($fixture . '/.nelkano-module-guard.json')) {
  throw new RuntimeException('Migration marker was not cleared.');
}
$run('backup');
$run('restore');
if (is_file($fixture . '/.nelkano-module-guard.json')) {
  throw new RuntimeException('An unmanaged module should not trigger migration.');
}
echo 'PASS: deployed controller and binary asset survive legacy uninstall; subsequent runs are no-ops.' . PHP_EOL;
echo 'Recoverable test fixture: ' . $fixture . PHP_EOL;
