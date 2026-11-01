<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

/** Presents the reproducibly copied notices from the application legal catalog. */
final class CreditsController extends ControllerBase {
  use NelkanoPageContextTrait;

  public function page(string $language = 'es'): Response {
    $language = $language === 'en' ? 'en' : 'es';
    $es = $language === 'es';
    $module = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    $legal = DRUPAL_ROOT . '/' . $module . '/legal';
    $catalog = json_decode(file_get_contents($legal . '/catalog.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $notice = static function (string $path) use ($legal, $module): array {
      if (!preg_match('@^legal/(notices|documents)/[A-Za-z0-9._-]+\.txt$@D', $path)) {
        throw new \RuntimeException('Invalid legal document path.');
      }
      $relative = substr($path, strlen('legal/'));
      $file = $legal . '/' . $relative;
      if (!is_file($file) || filesize($file) > 2000000) {
        throw new \RuntimeException('Missing or oversized legal document.');
      }
      return ['url' => '/' . $module . '/legal/' . $relative];
    };
    $entries = [];
    foreach ($catalog['entries'] ?? [] as $entry) {
      $entries[] = ['id'=>$entry['id'], 'name'=>$entry['name'],
        'notice'=>$notice((string)$entry['noticePath'])];
    }

    $path = $es ? '/creditos-licencias' : '/en/credits-licenses';
    $alternate = $es ? '/en/credits-licenses' : '/creditos-licencias';
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $page = ['type' => 'credits', 'title' => $es ? 'Licencias de terceros — Nelkano' : 'Third-party licenses — Nelkano',
      'description' => $es ? 'Licencias de terceros de Nelkano.' : 'Nelkano third-party licenses.',
      'heading' => $es ? 'Licencias de terceros' : 'Third-party licenses',
      'eyebrow' => '',
      'intro' => '',
      'canonical' => $host . $path, 'alternate' => $host . $alternate,
      'alternate_lang' => $es ? 'en' : 'es', 'default_url' => $host . '/creditos-licencias',
      'entries' => $entries, 'source_archive'=>'/third-party-sources/nelkano-runtimes-2026-10-31.zip'];
    $css = '';
    foreach (['base', 'header', 'footer', 'landing'] as $file) {
      $css .= file_get_contents(DRUPAL_ROOT . '/' . $module . '/css/' . $file . '.css') . "\n";
    }
    $template = file_get_contents(DRUPAL_ROOT . '/' . $module . '/templates/nelkano-legal-standalone.html.twig');
    $html = \Drupal::service('twig')->createTemplate($template)->render([
      'page' => $page, 'css_inline' => $css,
      'social_image_url' => $host . '/' . $module . '/assets/logo-social-v2.png',
    ] + $this->chromeContext($module, $language, $alternate, $es ? 'English' : 'Español'));
    return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
  }
}
