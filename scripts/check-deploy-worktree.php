<?php
/** Read-only source checks. Never stage, reset, restore or remove user changes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? dirname(__DIR__));
if (!$root) { throw new RuntimeException('Deployment repository not found.'); }
function deployGit(string $root, array $args): string {
  $process = proc_open(array_merge(['git', '--no-optional-locks', '-C', $root], $args),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) { throw new RuntimeException('Cannot inspect Git checkout.'); }
  fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  if (proc_close($process) !== 0) { throw new RuntimeException('Git inspection failed: ' . $error); }
  return $out;
}
$top = realpath(trim(deployGit($root, ['rev-parse', '--show-toplevel'])));
if ($top !== $root) { throw new RuntimeException('Deployment must name the repository root.'); }
$generated = deployGit($root, ['ls-files', '--', 'public_html/error_log', 'release-artifacts/*.exe', 'release-artifacts/*.msi', 'release-artifacts/*.zip', 'private/nelkano-releases/*.zip']);
if (trim($generated) !== '') { throw new RuntimeException("Generated runtime artifacts are still tracked by Git. Apply the prepared tracking migration before deployment:\n" . $generated); }
$changes = deployGit($root, ['status', '--porcelain', '--untracked-files=normal']);
if (trim($changes) !== '') { throw new RuntimeException("Deployment source checkout is not clean; no changes were discarded:\n" . $changes); }
echo "Deployment source checkout is clean.\n";
