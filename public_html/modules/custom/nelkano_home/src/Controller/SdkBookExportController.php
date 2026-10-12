<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nelkano_home\Form\SdkBookAdminFormBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

final class SdkBookExportController extends ControllerBase {
  public function download(): Response {
    $data = \Drupal::service('book_import_export.transfer')->export(SdkBookAdminFormBase::BOOK_UUID);
    SdkBookAdminFormBase::assertScope($data);
    return new Response(Yaml::dump($data, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK), 200, [
      'Content-Type' => 'application/yaml; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="nelkano-sdk.yml"',
      'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
    ]);
  }
}
