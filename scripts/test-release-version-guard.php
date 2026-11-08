<?php
require_once __DIR__ . '/release-version-guard.php';
$root = dirname(__DIR__);
$docroot = is_dir($root . '/public_html/modules/custom/nelkano_home') ? $root . '/public_html' : $root . '/web';
require_once $docroot . '/modules/custom/nelkano_home/src/Service/ReleaseArtifact.php';
$newest = [\Drupal\nelkano_home\Service\ReleaseArtifact::class, 'newest'];
$rows = array_map(static fn($p) => ['platform'=>$p, 'version'=>'3.0.7', 'version_code'=>17, 'visible'=>true], ['android','windows','steamos']);
$rows[] = ['platform'=>'sdk', 'version'=>'3.0.6', 'version_code'=>16, 'visible'=>false];
nelkanoAssertReleaseVersions($rows, $newest);
echo "PASS: Android/Windows/SteamOS 3.0.7 with hidden SDK 3.0.6\n";
$visibleSdk = $rows; $visibleSdk[3] = array_replace($visibleSdk[3], ['visible'=>true,'version'=>'3.0.7','version_code'=>17]);
nelkanoAssertReleaseVersions($visibleSdk, $newest);
echo "PASS: a visible SDK must join the same release\n";
foreach (['version','version_code','missing-windows','steamos','visible-sdk'] as $case) {
  $bad = $rows;
  if ($case === 'missing-windows') unset($bad[1]);
  elseif ($case === 'visible-sdk') $bad[3]['visible'] = true;
  elseif ($case === 'steamos') $bad[2]['version_code'] = 16;
  else $bad[1][$case] = $case === 'version' ? '3.0.6' : 16;
  $rejected = false;
  try { nelkanoAssertReleaseVersions($bad, $newest); } catch (RuntimeException) { $rejected = true; }
  if (!$rejected) throw new RuntimeException('Mismatch accepted: ' . $case);
  echo 'PASS: rejected ' . $case . " mismatch\n";
}
