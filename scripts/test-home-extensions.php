<?php

/** Render-only checks: no content, configuration or account mutations. */
$module = \Drupal::service('extension.list.module')->getPath('nelkano_home');
$template = \Drupal::service('twig')->createTemplate(file_get_contents(DRUPAL_ROOT . '/' . $module . '/templates/nelkano-home.html.twig'));
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$base = [
  'language' => 'es',
  'content' => [
    'hero_enabled' => FALSE,
    'about_enabled' => FALSE,
    'status_enabled' => TRUE,
    'status_title' => 'SYSTEMS ORDER SENTINEL',
    'status_items' => [],
    'extensions_enabled' => TRUE,
    'extensions_eyebrow' => 'Módulos y SDK',
    'extensions_title' => 'Amplía Nelkano',
    'extensions_description' => 'Personaliza tu experiencia.',
    'extensions_modules_title' => 'Añade nuevas funciones',
    'extensions_modules_description' => 'Descubre módulos para personalizar tu experiencia.',
    'extensions_modules_cta' => 'Explorar módulos',
    'extensions_sdk_title' => 'Crea tu propio módulo',
    'extensions_sdk_description' => 'Desarrolla y prueba tus ideas con SDK Studio.',
    'extensions_sdk_cta' => 'Empezar con el SDK',
    'differentiators_enabled' => TRUE,
    'differentiators_title' => 'DIFFERENTIATORS ORDER SENTINEL',
    'differentiator_items' => [],
  ],
  'labels' => ['all_systems' => 'Todos los sistemas'],
  'systems_url' => '/sistemas',
  'nav_modules_url' => '/modulos',
  'nav_sdk_url' => '/sdk-de-nelkano',
];
$render = static function (array $context) use ($template): array {
  $html = $template->render($context);
  $dom = new DOMDocument();
  @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
  return [$html, new DOMXPath($dom)];
};
$section = '//section[@id="extend-nelkano"]';
[$html, $xpath] = $render($base);
$check($xpath->query($section)->length === 1, 'Enabled extensions section renders once.');
$check(strpos($html, 'SYSTEMS ORDER SENTINEL') < strpos($html, 'id="extend-nelkano"') && strpos($html, 'id="extend-nelkano"') < strpos($html, 'DIFFERENTIATORS ORDER SENTINEL'), 'Extensions follow compatible systems and precede differentiators.');
$check($xpath->query($section . '/div/div/article')->length === 2, 'Published SDK creates two extension cards.');
$check($xpath->query($section . '//a[@href="/modulos"]')->length === 1, 'Catalog CTA opens the public catalog.');
$check($xpath->query($section . '//a[@href="/sdk-de-nelkano"]')->length === 1, 'SDK CTA uses the accessible book URL.');
$check($xpath->query($section . '[@aria-labelledby="extensions-title"]//h2[@id="extensions-title"]')->length === 1, 'Section has an accessible heading.');
$check($xpath->query($section . '//a[not(normalize-space())]')->length === 0, 'Every extension link has an accessible name.');
$unpublished = $base;
$unpublished['nav_sdk_url'] = '';
[$html, $xpath] = $render($unpublished);
$check($xpath->query($section . '/div/div/article')->length === 1, 'Unpublished SDK leaves a single catalog card.');
$check(!str_contains($html, 'Crea tu propio módulo') && !str_contains($html, 'href="/sdk-de-nelkano"'), 'Unpublished SDK has no card or link.');
$check(!str_contains($html, 'r-extensions-pair'), 'Single catalog card does not reserve a second column.');
$disabled = $base;
$disabled['content']['extensions_enabled'] = FALSE;
[, $xpath] = $render($disabled);
$check($xpath->query($section)->length === 0, 'Editorial visibility hides the whole section.');
$without_catalog = $base;
$without_catalog['nav_modules_url'] = '';
[, $xpath] = $render($without_catalog);
$check($xpath->query($section)->length === 0, 'No catalog route avoids empty navigation links.');
$escaped = $base;
$escaped['content']['extensions_title'] = '<script>unsafe-title</script>';
$escaped['content']['extensions_modules_description'] = '<img src=x onerror=alert(1)>';
$escaped['content']['extensions_sdk_cta'] = '<b>unsafe-link</b>';
[$html, $xpath] = $render($escaped);
$check(str_contains($html, '&lt;script&gt;unsafe-title&lt;/script&gt;') && $xpath->query($section . '//script')->length === 0, 'Editable heading is escaped.');
$check($xpath->query($section . '//img')->length === 0 && str_contains($html, '&lt;img src=x onerror=alert(1)&gt;'), 'Editable descriptions are escaped.');
$check($xpath->query($section . '//a/b')->length === 0 && str_contains($html, '&lt;b&gt;unsafe-link&lt;/b&gt;'), 'Editable CTA is escaped.');
$english = $base;
$english['language'] = 'en';
$english['nav_modules_url'] = '/en/modules';
$english['nav_sdk_url'] = '/en/sdk-de-nelkano';
$english['content']['extensions_title'] = 'Extend Nelkano';
$english['content']['extensions_modules_cta'] = 'Explore modules';
$english['content']['extensions_sdk_cta'] = 'Get started with the SDK';
[$html, $xpath] = $render($english);
$check($xpath->query($section . '//a[@href="/en/modules"]')->length === 1 && $xpath->query($section . '//a[@href="/en/sdk-de-nelkano"]')->length === 1, 'English cards preserve their language URLs.');
$check(str_contains($html, 'Extend Nelkano') && str_contains($html, 'Explore modules') && str_contains($html, 'Get started with the SDK'), 'Card copy comes from the selected language configuration.');
echo "PASS: $checks home extensions render checks; no persistent changes.\n";
