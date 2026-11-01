<?php

declare(strict_types=1);

use Drupal\Component\Serialization\Yaml;

// Invoke only through the local Docker Drupal bootstrap. No release service,
// full configuration export/import, entity update or cache operation is used.
if (realpath(DRUPAL_ROOT) !== '/opt/drupal/web') {
  throw new RuntimeException('This content-only helper is limited to the local Docker Drupal root.');
}

$fields = ['privacy_cookies_title', 'privacy_cookies_intro', 'privacy_cookies_sections', 'legal_notice_sections'];
$languages = ['es', 'en'];
$source = DRUPAL_ROOT . '/modules/custom/nelkano_home/config/legal-compliance-copy.json';
$yamlPath = dirname(DRUPAL_ROOT) . '/config/sync/nelkano_home.docs.yml';
$patchRaw = file_get_contents($source);
$yamlBeforeRaw = file_get_contents($yamlPath);
if ($patchRaw === FALSE || $yamlBeforeRaw === FALSE) {
  throw new RuntimeException('Required local content/configuration source is unavailable.');
}
$patch = json_decode($patchRaw, TRUE, 512, JSON_THROW_ON_ERROR);
$yamlBefore = Yaml::decode($yamlBeforeRaw);
$config = \Drupal::configFactory()->getEditable('nelkano_home.docs');
$activeBefore = $config->getRawData();
if (!is_array($yamlBefore) || !is_array($activeBefore)) {
  throw new RuntimeException('Expected existing mapping configuration.');
}

$canonical = static function (mixed $value) use (&$canonical): mixed {
  if (!is_array($value)) return $value;
  if (!array_is_list($value)) ksort($value, SORT_STRING);
  foreach ($value as &$item) $item = $canonical($item);
  unset($item);
  return $value;
};
$hash = static fn(mixed $value): string => hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$foreign = static function (array $data) use ($fields, $languages): array {
  foreach ($languages as $language) {
    if (!isset($data[$language]) || !is_array($data[$language])) continue;
    foreach ($fields as $field) unset($data[$language][$field]);
  }
  return $data;
};
$applyFields = static function (array $data) use ($patch, $fields, $languages): array {
  foreach ($languages as $language) {
    if (!isset($data[$language]) || !is_array($data[$language])) {
      throw new RuntimeException('Expected existing language data: ' . $language);
    }
    foreach ($fields as $field) {
      if (!array_key_exists($field, $patch[$language] ?? [])) {
        throw new RuntimeException('Missing allowed content field: ' . $language . '.' . $field);
      }
      $data[$language][$field] = $patch[$language][$field];
    }
    $sections = $data[$language]['privacy_cookies_sections'];
    $cookieTitle = $language === 'es' ? 'Cookies y almacenamiento del navegador' : 'Cookies and browser storage';
    if (($sections[4]['title'] ?? NULL) !== $cookieTitle) {
      throw new RuntimeException('Cookie information must remain at section-5.');
    }
  }
  return $data;
};

// Build each target from its OWN baseline. Active values never overwrite
// different source YAML values outside the four explicitly allowed fields.
$activeExpected = $applyFields($activeBefore);
$yamlExpected = $applyFields($yamlBefore);
$foreignActiveHash = $hash($foreign($activeBefore));
$foreignYamlHash = $hash($foreign($yamlBefore));
if ($hash($foreign($activeExpected)) !== $foreignActiveHash || $hash($foreign($yamlExpected)) !== $foreignYamlHash) {
  throw new RuntimeException('An unowned configuration value would change.');
}
$yamlNextRaw = Yaml::encode($yamlExpected);
if ($hash($foreign(Yaml::decode($yamlNextRaw))) !== $foreignYamlHash) {
  throw new RuntimeException('YAML serialization changed an unowned value.');
}

$result = [
  'mode' => getenv('NELKANO_APPLY_PRIVACY_COPY') === '1' ? 'apply' : 'preview',
  'config' => 'nelkano_home.docs',
  'allowedFieldsPerLanguage' => $fields,
  'languages' => $languages,
  'cookieSection' => 5,
  'foreignActiveBeforeSha256' => $foreignActiveHash,
  'foreignYamlBeforeSha256' => $foreignYamlHash,
  'activeBeforeSha256' => $hash($activeBefore),
  'yamlBeforeSha256' => hash('sha256', $yamlBeforeRaw),
  'contentSourceSha256' => hash('sha256', $patchRaw),
  'releaseVisibilityTouched' => FALSE,
  'bookEntitiesTouched' => FALSE,
  'htaccessTouched' => FALSE,
  'fullConfigExportOrImport' => FALSE,
];

if ($result['mode'] === 'preview') {
  $result['activeExpectedSha256'] = $hash($activeExpected);
  $result['yamlExpectedSha256'] = hash('sha256', $yamlNextRaw);
  echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
  return;
}

// A local temporary snapshot supports recovery; it contains only this docs
// configuration, never account records, tokens or a database dump.
$snapshotPath = tempnam(sys_get_temp_dir(), 'nelkano-privacy-copy-');
if ($snapshotPath === FALSE || file_put_contents($snapshotPath, json_encode([
  'activeBefore' => $activeBefore,
  'sourceYamlBefore' => $yamlBeforeRaw,
  'sourceYamlPath' => $yamlPath,
  'receipt' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === FALSE) {
  throw new RuntimeException('Could not persist the local recovery snapshot.');
}
chmod($snapshotPath, 0600);

// Refuse to replace a concurrently edited baseline.
\Drupal::configFactory()->reset('nelkano_home.docs');
$config = \Drupal::configFactory()->getEditable('nelkano_home.docs');
if ($hash($config->getRawData()) !== $hash($activeBefore) || file_get_contents($yamlPath) !== $yamlBeforeRaw || file_get_contents($source) !== $patchRaw) {
  throw new RuntimeException('Configuration changed while preparing the patch; reread before applying. Snapshot: ' . $snapshotPath);
}

$temporaryYaml = tempnam(dirname($yamlPath), '.privacy-copy-');
if ($temporaryYaml === FALSE) throw new RuntimeException('Could not prepare a source YAML replacement.');
try {
  if (file_put_contents($temporaryYaml, $yamlNextRaw, LOCK_EX) === FALSE) throw new RuntimeException('Could not write source YAML.');
  chmod($temporaryYaml, fileperms($yamlPath) & 0777);
  if (!rename($temporaryYaml, $yamlPath)) throw new RuntimeException('Could not replace the source YAML.');
  // Trusted content types were validated from the versioned JSON and the
  // unchanged active baseline; avoid coercing unrelated legacy field values.
  $config->setData($activeExpected)->save(TRUE);
}
finally {
  if (is_file($temporaryYaml)) unlink($temporaryYaml);
}

\Drupal::configFactory()->reset('nelkano_home.docs');
$activeAfter = \Drupal::configFactory()->getEditable('nelkano_home.docs')->getRawData();
$yamlAfter = Yaml::decode(file_get_contents($yamlPath));
$result['snapshotPath'] = $snapshotPath;
$result['foreignActiveAfterSha256'] = $hash($foreign($activeAfter));
$result['foreignYamlAfterSha256'] = $hash($foreign($yamlAfter));
$result['activeOwnedFieldsMatch'] = $hash($activeAfter) === $hash($activeExpected);
$result['yamlOwnedFieldsMatch'] = $hash($yamlAfter) === $hash($yamlExpected);
$result['foreignValuesPreserved'] = $result['foreignActiveAfterSha256'] === $foreignActiveHash && $result['foreignYamlAfterSha256'] === $foreignYamlHash;
if (!$result['foreignValuesPreserved'] || !$result['activeOwnedFieldsMatch'] || !$result['yamlOwnedFieldsMatch']) {
  throw new RuntimeException('Post-write value comparison failed; inspect the local snapshot before further changes: ' . $snapshotPath);
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
