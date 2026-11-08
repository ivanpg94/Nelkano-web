<?php
/** Visible application releases share one identity; hidden SDK is not required. */
function nelkanoAssertReleaseVersions(array $rows, callable $newest): void {
  $identity = null;
  foreach (['android', 'windows', 'steamos', 'sdk'] as $platform) {
    $row = $newest($rows, $platform);
    if (!$row) {
      if (in_array($platform, ['android', 'windows'], true)) throw new RuntimeException('Missing published product release: ' . $platform);
      continue;
    }
    $current = [(string) $row['version'], (int) ($row['version_code'] ?? 0)];
    if ($current[1] < 1 || ($identity !== null && $current !== $identity)) throw new RuntimeException('Visible applications must publish the same version and version code; mismatch at ' . $platform);
    $identity = $current;
  }
}
