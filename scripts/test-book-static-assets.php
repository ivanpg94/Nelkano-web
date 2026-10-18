<?php
/** Focused transport checks. No Drupal bootstrap, database or content writes.
 * docker exec nelkano-drupal php scripts/test-book-static-assets.php
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/web/modules/custom/book_import_export/src/BookTransfer.php';
$reflection = new ReflectionClass(\Drupal\book_import_export\BookTransfer::class);
$transfer = $reflection->newInstanceWithoutConstructor();
$exportLinks = $reflection->getMethod('links');
$importLinks = $reflection->getMethod('resolveLinks');
$checks = 0;
$assert = function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL ' . $message); }
  $checks++; echo "PASS $message\n";
};
$base = '/modules/custom/nelkano_home/assets/docs/sdk/';
foreach (['module-lifecycle.svg', '20261018/studio-home.png', '20261018/android-modules.webp'] as $file) {
  $path = $base . $file;
  $html = '<a href="' . $path . '"><img src="' . $path . '" alt="Interfaz de ejemplo" width="800" height="600" data-caption="Studio. Pulsa para ampliar."></a>';
  $assert($exportLinks->invoke($transfer, $html, fn($url) => $url) === $html, 'export preserves static ' . $file);
  $assert($importLinks->invoke($transfer, $html, [], []) === $html, 'import preserves static ' . $file);
}
$bad = [
  '/sites/default/files/upload.png', 'private://package.png', 'public://upload.png',
  'https://example.org/image.png', '//example.org/image.png', 'data:image/svg+xml,foo',
  $base . '../secret.png', $base . 'a/../secret.png', $base . 'a//image.png',
  $base . '%2e%2e/secret.png', $base . 'image%2epng', $base . 'image.png?token=private',
  $base . 'image.png#fragment', $base . 'image.jpeg', $base . 'image.html',
  $base . 'image.png/extra', $base . 'image\\x.png', $base . '.hidden.png',
];
foreach ($bad as $path) {
  $html = '<img src="' . htmlspecialchars($path, ENT_QUOTES) . '" alt="Bad">';
  foreach (['export', 'import'] as $mode) {
    $failed = FALSE;
    try {
      if ($mode === 'export') { $exportLinks->invoke($transfer, $html, fn($url) => $url); }
      else { $importLinks->invoke($transfer, $html, [], []); }
    } catch (InvalidArgumentException $e) { $failed = TRUE; }
    $assert($failed, $mode . ' rejects ' . $path);
  }
}
foreach ([
  '<img src="' . $base . 'ok.png" data-entity-type="file" data-entity-uuid="secret">',
  '<drupal-media data-entity-type="media" data-entity-uuid="secret"></drupal-media>',
  '<img src="' . $base . 'ok.png" srcset="https://example.org/other.png 2x">',
  '<img src="' . $base . 'ok.png" onerror="alert(1)">',
  '<a href="https://example.org/image.png"><img src="' . $base . 'ok.png"></a>',
  '<a href="' . $base . 'ok.png?token=private"><img src="' . $base . 'ok.png"></a>',
  '<a href="' . $base . 'other.png"><img src="' . $base . 'ok.png"></a>',
  '<img alt="Missing source">', '<iframe src="' . $base . 'ok.svg"></iframe>',
  '<video src="' . $base . 'ok.png"></video>',
] as $html) {
  foreach (['export', 'import'] as $mode) {
    $failed = FALSE;
    try {
      if ($mode === 'export') { $exportLinks->invoke($transfer, $html, fn($url) => $url); }
      else { $importLinks->invoke($transfer, $html, [], []); }
    } catch (InvalidArgumentException $e) { $failed = TRUE; }
    $assert($failed, $mode . ' rejects embedded media/alternate image attributes');
  }
}
$plain = '<p><a href="https://example.org/docs?q=one&amp;x=two#part">External text link</a></p>';
$assert($exportLinks->invoke($transfer, $plain, fn($url) => $url) === $plain, 'ordinary text links retain existing behavior');
echo "$checks static documentation asset checks passed. No database connection.\n";
