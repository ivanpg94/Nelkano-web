<?php

/** Read-only checks for grouped usage instructions and their versioned screenshots. */
use Drupal\nelkano_home\Controller\HomeController;
use Drupal\nelkano_home\Form\GuideSettingsForm;
use Symfony\Component\Yaml\Yaml;

$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
  if (!$ok) { throw new RuntimeException($message); }
  echo 'PASS ' . ++$count . ': ' . $message . PHP_EOL;
};
$controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(HomeController::class);
$resolve = new ReflectionMethod($controller, 'guideImage');
foreach (['https://example.com/a.png', '//example.com/a.png', '/sites/default/files/a.png', '/modules/custom/nelkano_home/assets/docs/sdk/a.png', '/modules/custom/nelkano_home/assets/docs/guide/../secret.png', '/modules/custom/nelkano_home/assets/docs/guide/%2e%2e/a.png', '/modules/custom/nelkano_home/assets/docs/guide/a.svg', '/modules/custom/nelkano_home/assets/docs/guide/a.webp?token=1', '/modules/custom/nelkano_home/assets/docs/guide/missing-file.webp'] as $invalid) {
  $check($resolve->invoke($controller, ['image_asset' => $invalid])['image_url'] === '', 'Unsupported, missing or non-guide image rejected: ' . $invalid);
}
$schema = Yaml::parseFile(DRUPAL_ROOT . '/modules/custom/nelkano_home/config/schema/nelkano_home.guide.schema.yml');
$columns = $schema['nelkano_home.guide_language']['mapping']['sections']['sequence']['mapping'];
foreach (['group', 'steps', 'notes', 'image_uri', 'image_asset', 'image_alt', 'image_caption', 'image_focus'] as $key) {
  $check(isset($columns[$key]), "Config schema preserves the $key field");
}
$config = \Drupal::config('nelkano_home.guide')->getRawData();
$minimum = max(1, (int) (getenv('GUIDE_IMAGE_MINIMUM') ?: 1));
$totalImages = 0;
$first = NULL;
foreach (['es', 'en'] as $language) {
  $sections = array_values(array_filter($config[$language]['sections'] ?? [], static fn($section) => ($section['visible'] ?? '1') !== '0' && !empty($section['title'])));
  $groups = array_values(array_unique(array_column($sections, 'group')));
  $check(count($groups) === 6 && !in_array('', $groups, TRUE), "$language tasks belong to six named groups");
  $check(count(array_filter($sections, static fn($section) => $section['group'] === $groups[0])) === 3, "$language first group contains the three starting tasks");
  $expectedImages = 0;
  foreach ($sections as $section) {
    $steps = array_values(array_filter(array_map('trim', explode("\n", $section['steps'] ?? ''))));
    $check(count($steps) >= 1, "$language task has actionable steps: {$section['title']}");
    if (empty($section['image_asset']) && empty($section['image_uri'])) { continue; }
    $resolved = $resolve->invoke($controller, $section);
    $check(!empty($resolved['image_url']), "$language screenshot resolves: {$section['title']}");
    $check(!empty($section['image_alt']) && !empty($section['image_caption']), "$language screenshot has an accessible description and caption: {$section['title']}");
    if (!empty($section['image_asset']) && empty($section['image_uri'])) {
      $check(($resolved['image_width'] ?? 0) > 0 && ($resolved['image_height'] ?? 0) > 0, "$language screenshot has its real dimensions: {$section['title']}");
    }
    $first ??= $section;
    ++$expectedImages;
  }
  $html = $controller->guidePage($language)->getContent();
  $document = new DOMDocument();
  @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
  $xpath = new DOMXPath($document);
  $hasClass = static fn(string $class): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")';
  $check($xpath->query('//*[' . $hasClass('r-usage-guide') . ']')->length === 1, "$language usage guide has its isolated layout wrapper");
  $renderedGroups = $xpath->query('//*[' . $hasClass('r-guide-group') . ']');
  $check($renderedGroups->length === 6, "$language renders six task groups");
  $toc = $xpath->query('//*[' . $hasClass('r-guide-toc') . ']//a');
  $check($toc->length === 6, "$language index lists six groups instead of every task");
  $ids = [];
  foreach ($renderedGroups as $group) { $ids[] = $group->getAttribute('id'); }
  foreach ($toc as $link) {
    $check(str_starts_with($link->getAttribute('href'), '#') && in_array(substr($link->getAttribute('href'), 1), $ids, TRUE), "$language index link points to a rendered group");
  }
  $tasks = $xpath->query('//*[' . $hasClass('r-guide-task') . ']');
  $check($tasks->length === count($sections), "$language renders each visible task once");
  foreach ($tasks as $task) {
    $check($xpath->query('.//ol[' . $hasClass('r-guide-task-steps') . ']/li', $task)->length >= 1, "$language task uses a numbered list");
  }
  $figures = $xpath->query('//figure[' . $hasClass('r-guide-figure') . ']');
  $check($figures->length === $expectedImages, "$language renders every configured visible screenshot ($expectedImages)");
  foreach ($figures as $figure) {
    $image = $xpath->query('.//img', $figure)->item(0);
    $check($image && trim($image->getAttribute('alt')) !== '' && $xpath->query('./figcaption', $figure)->length === 1, "$language rendered screenshot has alt text and visible caption");
    $check($xpath->query('./a[@data-r-lightbox]', $figure)->length === 1, "$language rendered screenshot opens its full-size original");
    $check($xpath->query('ancestor::*[' . $hasClass('r-guide-task') . ']', $figure)->length === 1, "$language screenshot is placed within its task");
  }
  $check(!preg_match('/Pok[eé]mon Online|Reportes de errores|Reportar un error|Send an error report/ui', $html), "$language public guide contains no private module references");
  $totalImages += $expectedImages;
}
$check($totalImages >= $minimum, "Guide includes at least $minimum reviewed image placements");
if ($first) {
  $form = GuideSettingsForm::create(\Drupal::getContainer());
  $definition = (new ReflectionClass($form))->getConstant('ROWS');
  $normalize = new ReflectionMethod($form, 'normalizeConfigRowsValue');
  $sample = $first + ['image_uri' => ''];
  $sample['notes'] = $sample['notes'] ?? 'A useful optional note.';
  $sample['image_focus'] = $sample['image_focus'] ?? 'top';
  $normalized = $normalize->invoke($form, ['rows' => [['top' => ['visible' => '1'], 'fields' => $sample]]], $definition, FALSE);
  foreach (['group', 'steps', 'notes', 'image_asset', 'image_alt', 'image_caption', 'image_focus'] as $key) {
    $check(($normalized[0][$key] ?? '') === $sample[$key], "Saving administration rows preserves $key");
  }
}

echo "Guide checks complete: $count; configuration was not modified." . PHP_EOL;
