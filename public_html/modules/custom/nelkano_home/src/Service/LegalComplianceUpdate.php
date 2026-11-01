<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\Core\File\FileSystemInterface;

/** One-time editorial update; binary files and release history are retained. */
final class LegalComplianceUpdate {
  public static function apply(): void {
    $module = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    $patch = json_decode(file_get_contents(DRUPAL_ROOT . '/' . $module . '/config/legal-compliance-copy.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $config = \Drupal::configFactory()->getEditable('nelkano_home.docs');
    foreach ($patch as $language => $fields) {
      foreach ($fields as $key => $value) $config->set($language . '.' . $key, $value);

    }
    $config->save();
    // Add only this notice to the existing book entry; preserve every other edit.
    $bookNotices = [
      'es' => '<h2 id="sdk-license-terms">Licencia del SDK y derechos de tus módulos</h2>
<p>El código original de los contratos, tipos, herramientas y ejemplos propios del SDK se ofrece bajo MIT. Los cores, dependencias y recursos de terceros mantienen sus licencias.</p>
<p>Conservas los derechos de tus módulos y puedes elegir su licencia y cobrarlos, respetando sus dependencias y la normativa. Usar el SDK por sí solo no te obliga a publicar su código. Consulta <a href="/creditos-licencias">Créditos, licencias y condiciones completas</a>.</p>',
      'en' => '<h2 id="sdk-license-terms">SDK license and rights to your modules</h2>
<p>The original SDK contract, type, tooling and original example code is available under MIT. Cores, dependencies and third-party resources keep their own licenses.</p>
<p>You retain rights to your modules and may choose their license and charge for them, subject to dependencies and applicable law. Merely using the SDK does not require publishing their source. Read <a href="/en/credits-licenses">Credits, licenses and complete terms</a>.</p>',
    ];
    $books = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => 'de520020-2026-4000-8000-000000000001', 'type' => 'sdk_page']);
    if ($book = reset($books)) {
      $changed = FALSE;
      foreach ($bookNotices as $langcode => $html) {
        if (!$book->hasTranslation($langcode)) continue;
        $translation = $book->getTranslation($langcode);
        $body = $translation->get('body')->getValue();
        if (!$body || str_contains($body[0]['value'] ?? '', 'id="sdk-license-terms"')) continue;
        $body[0]['value'] .= "\n" . $html;
        $translation->set('body', $body);
        $changed = TRUE;
      }
      if ($changed) $book->save();
    }
    // Old public APKs must not remain downloadable at their physical URLs.
    // Keep every artifact in place; controllers still obey the editable catalog.
    $directory = 'public://nelkano-releases';
    $fs = \Drupal::service('file_system');
    if (!$fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('Could not protect historical public releases.');
    }
    $access = "# Release binaries are served only by publication-aware controllers.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    $realDirectory = $fs->realpath($directory);
    if (!is_string($realDirectory) || file_put_contents($realDirectory . DIRECTORY_SEPARATOR . '.htaccess', $access, LOCK_EX) === FALSE) {
      throw new \RuntimeException('Could not write release access policy.');
    }
  }
}
