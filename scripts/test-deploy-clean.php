<?php
/** Isolated control-flow tests: every deployment command is mocked. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = dirname(__DIR__) . '/scripts/';
$check = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$executable = static function (string $path, string $bytes): void { if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, TRUE); file_put_contents($path, $bytes); chmod($path, 0700); };
foreach (['clean', 'dirty', 'tracked-runtime', 'composer-mutation', 'composer-failure', 'late-mutation'] as $mode) {
  $base = sys_get_temp_dir() . '/nelkano-deploy-control-' . bin2hex(random_bytes(8));
  mkdir($base, 0700); $repo = $base . '/repo'; mkdir($repo . '/scripts', 0700, TRUE);
  try {
    foreach (['deploy-hostinger.sh', 'check-deploy-worktree.php', 'repair-known-htaccess.php'] as $name) copy($source . $name, $repo . '/scripts/' . $name);
    foreach (['public_html/sites/default/settings.php', 'public_html/modules/custom/nelkano_home/src/Controller/ErrorReportApiController.php'] as $relative) { mkdir(dirname($repo . '/' . $relative), 0700, TRUE); file_put_contents($repo . '/' . $relative, 'fixture'); }
    file_put_contents($repo . '/source.txt', $mode === 'dirty' ? "dirty\n" : "canonical\n");
    $executable($base . '/bin/git', <<<'GIT'
#!/usr/local/bin/php
<?php
$args = array_slice($argv, 1); $repo = getenv('FAKE_REPO');
file_put_contents(getenv('TEST_TRACE'), 'git ' . implode(' ', $args) . "\n", FILE_APPEND);
if (in_array('rev-parse', $args, TRUE)) echo $repo, "\n";
elseif (in_array('ls-files', $args, TRUE)) { if (getenv('TEST_MODE') === 'tracked-runtime') echo "release-artifacts/old.zip\n"; }
elseif (in_array('status', $args, TRUE)) { if (file_get_contents($repo . '/source.txt') !== "canonical\n") echo " M source.txt\n"; }
else { fwrite(STDERR, 'Unexpected Git mutation attempted'); exit(1); }
GIT);
    $executable($base . '/bin/php', <<<'PHP_MOCK'
#!/usr/bin/env bash
set -eu
if [[ "$1" = -d ]]; then shift 2; fi
if [[ "$1" = */repair-known-htaccess.php ]]; then exit 0; fi
if [[ "$1" = */check-deploy-worktree.php ]]; then exec /usr/local/bin/php "$@"; fi
if [[ "$1" = scripts/materialize-release-artifacts.php ]]; then
  target="${2:-release-artifacts}"
  mkdir -p "$target"
  printf generated > "$target/runtime.zip"
  if [[ "$target" = public_html/third-party-sources ]]; then printf fixture > "$target/nelkano-runtimes-2026-10-31.zip"; fi
fi
PHP_MOCK);
    $executable($base . '/bin/composer', <<<'COMPOSER'
#!/usr/bin/env bash
set -eu
echo composer >> "$TEST_TRACE"
if [[ "$TEST_MODE" = composer-mutation ]]; then printf changed > "$FAKE_REPO/source.txt"; fi
if [[ "$TEST_MODE" = composer-failure ]]; then exit 23; fi
COMPOSER);
    $executable($repo . '/vendor/bin/drush', <<<'DRUSH'
#!/usr/bin/env bash
set -eu
echo "drush $*" >> "$TEST_TRACE"
if [[ " $* " = *" state:set system.maintenance_mode "* ]]; then
  printf '%s' "${@: -1}" > "$TEST_STATE"
  if [[ "${@: -1}" = 0 && "$TEST_MODE" = late-mutation ]]; then printf changed > "$FAKE_REPO/source.txt"; fi
fi
DRUSH);
    $env = array_merge(getenv(), ['PATH' => $base . '/bin:' . getenv('PATH'), 'REPO_DIR' => $repo, 'APP_DIR' => $repo,
      'FAKE_REPO' => $repo, 'TEST_MODE' => $mode, 'TEST_TRACE' => $base . '/trace', 'TEST_STATE' => $base . '/maintenance']);
    $process = proc_open(['bash', $repo . '/scripts/deploy-hostinger.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, NULL, $env);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
    $trace = is_file($base . '/trace') ? file_get_contents($base . '/trace') : '';
    if ($mode === 'clean') {
      $check($code === 0, $error);
      $check(file_get_contents($base . '/maintenance') === '0', 'successful site not reopened');
      $check(!is_file($repo . '/release-artifacts/runtime.zip'), 'application installers unexpectedly materialized');
      $check(str_contains($trace, 'preserve-manual-releases.php'), 'manual catalogue preservation was not invoked');
      $check(file_get_contents($repo . '/source.txt') === "canonical\n", 'clean deployment rewrote source');
    }
    else {
      $check($code !== 0, 'unexpectedly accepted ' . $mode);
      if (in_array($mode, ['dirty', 'tracked-runtime'], TRUE)) $check(!str_contains($trace, 'composer') && !str_contains($trace, 'drush'), 'work started before preflight rejection');
      else {
        $check(file_get_contents($base . '/maintenance') === '1', 'failed site reopened');
        if (str_ends_with($mode, 'mutation')) $check(file_get_contents($repo . '/source.txt') === 'changed', 'user source changes discarded');
      }
    }
    foreach (['git reset', 'git clean', 'git restore', 'git commit', 'git add'] as $forbidden) $check(!str_contains($trace, $forbidden), 'Git mutation attempted');
    echo 'PASS: ', $mode, "\n";
  }
  finally {
    if (!str_starts_with($base, sys_get_temp_dir() . '/nelkano-deploy-control-') || is_link($base)) throw new RuntimeException('Unsafe test cleanup path');
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($base);
  }
}
echo "SUCCESS: 6 isolated deployment scenarios; no live deployment or commits.\n";

