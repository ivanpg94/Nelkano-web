<?php

/**
 * Merge reviewed Android screenshots and exact editorial corrections into the usage guide.
 *
 * Usage (local Drupal only):
 *   GUIDE_IMAGE_MANIFEST=/tmp/guide-images.json vendor/bin/drush php:script scripts/illustrate-usage-guide.php
 *   GUIDE_IMAGE_APPLY=1 GUIDE_IMAGE_MANIFEST=/tmp/guide-images.json vendor/bin/drush php:script scripts/illustrate-usage-guide.php
 *
 * Manifest: {"images":[{"language":"es","title":"...","image_asset":"/modules/custom/nelkano_home/assets/docs/guide/20261018/example.webp","image_alt":"...","image_caption":"..."}],"text_replacements":[{"language":"es","title":"...","field":"bullets","old":"...","new":"..."}]}.
 * Optional intro_replacements records contain language, old and new.
 * Dry-run is the default. Apply saves only nelkano_home.guide and exports its
 * complete current value to sync and the matching module installation defaults.
 */
use Symfony\Component\Yaml\Yaml;

$manifestPath = getenv('GUIDE_IMAGE_MANIFEST') ?: '';
if ($manifestPath === '' || !is_file($manifestPath)) {
  throw new RuntimeException('Set GUIDE_IMAGE_MANIFEST to the reviewed JSON manifest.');
}
$manifest = json_decode(file_get_contents($manifestPath), TRUE, 512, JSON_THROW_ON_ERROR);
$config = \Drupal::configFactory()->getEditable('nelkano_home.guide');
$before = $config->getRawData();
$exportPaths = [DRUPAL_ROOT . '/../config/sync/nelkano_home.guide.yml', DRUPAL_ROOT . '/modules/custom/nelkano_home/config/install/nelkano_home.guide.yml'];
foreach ($exportPaths as $path) {
  if (!is_file($path) || Yaml::parseFile($path) != $before) {
    throw new RuntimeException("Reconcile the current guide with $path before applying images.");
  }
}
$after = $before;
$findSection = static function (string $language, string $title) use (&$after): int {
  if (!in_array($language, ['es', 'en'], TRUE)) { throw new RuntimeException('Unknown guide language.'); }
  $matches = array_keys(array_filter($after[$language]['sections'] ?? [], static fn($section) => ($section['title'] ?? '') === $title));
  if (count($matches) !== 1) { throw new RuntimeException("Expected exactly one section: $language/$title"); }
  return $matches[0];
};
foreach ($manifest['images'] ?? [] as $image) {
  $language = $image['language'];
  $index = $findSection($language, $image['title']);
  $asset = (string) ($image['image_asset'] ?? '');
  if (!preg_match('@^/modules/custom/nelkano_home/assets/docs/guide/(?:[a-z0-9_-]+/)*[a-z0-9_-]+\.(?:png|webp|jpg|jpeg)$@D', $asset)
    || !is_file(DRUPAL_ROOT . $asset) || !getimagesize(DRUPAL_ROOT . $asset)) {
    throw new RuntimeException("Missing or invalid screenshot: $asset");
  }
  foreach (['image_alt', 'image_caption'] as $required) {
    if (trim((string) ($image[$required] ?? '')) === '') { throw new RuntimeException("Missing $required for $asset"); }
  }
  foreach (['image_asset', 'image_alt', 'image_caption'] as $key) {
    $after[$language]['sections'][$index][$key] = trim($image[$key]);
  }
}
foreach ($manifest['text_replacements'] ?? [] as $replacement) {
  $language = $replacement['language'];
  $index = $findSection($language, $replacement['title']);
  $field = $replacement['field'];
  if (!in_array($field, ['description', 'bullets'], TRUE)) { throw new RuntimeException('Unexpected replacement field.'); }
  $text = $after[$language]['sections'][$index][$field] ?? '';
  if (substr_count($text, $replacement['old']) !== 1) {
    if (str_contains($text, $replacement['new'])) { continue; }
    throw new RuntimeException("Concurrent text change: $language/{$replacement['title']}/$field");
  }
  $after[$language]['sections'][$index][$field] = str_replace($replacement['old'], $replacement['new'], $text);
}
foreach ($manifest['intro_replacements'] ?? [] as $replacement) {
  $language = $replacement['language'];
  if (!in_array($language, ['es', 'en'], TRUE)) { throw new RuntimeException('Unknown guide language.'); }
  $text = $after[$language]['intro'] ?? '';
  if ($text === $replacement['new']) { continue; }
  if ($text !== $replacement['old']) { throw new RuntimeException("Concurrent introduction change: $language"); }
  $after[$language]['intro'] = $replacement['new'];
}
$changed = $after !== $before;
$apply = getenv('GUIDE_IMAGE_APPLY') === '1';
if ($apply) {
  // Re-read immediately before save so an intervening author cannot be overwritten.
  \Drupal::configFactory()->reset('nelkano_home.guide');
  $config = \Drupal::configFactory()->getEditable('nelkano_home.guide');
  $fresh = $config->getRawData();
  if ($fresh !== $before) { throw new RuntimeException('Guide changed during preparation. Run again against the current guide.'); }
  $yaml = Yaml::dump($after, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  foreach ($exportPaths as $path) {
    if (file_put_contents($path . '.guide-tmp', $yaml) === FALSE || !rename($path . '.guide-tmp', $path)) {
      throw new RuntimeException("Could not export guide: $path");
    }
  }
  if ($changed) { $config->setData($after)->save(); }
}
echo json_encode(['mode' => $apply ? 'applied' : 'dry-run', 'changed' => $changed, 'images' => count($manifest['images'] ?? []), 'text_replacements' => count($manifest['text_replacements'] ?? []), 'intro_replacements' => count($manifest['intro_replacements'] ?? []), 'before_sha256' => hash('sha256', json_encode($before)), 'after_sha256' => hash('sha256', json_encode($after))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
