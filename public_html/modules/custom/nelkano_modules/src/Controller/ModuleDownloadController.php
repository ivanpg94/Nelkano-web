<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nelkano_modules\Service\ModuleApiQuery;
use Drupal\nelkano_modules\Service\ModuleAudience;
use Drupal\nelkano_modules\Service\ModuleHostedPackages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** No public file URL, query token or redirect is emitted for a hosted package. */
final class ModuleDownloadController extends ControllerBase {
  public function download(Request $request, string $module_id, string $version): BinaryFileResponse {
    ModuleAudience::account();
    \Drupal\nelkano_modules\Service\ModuleApiRateLimit::allow($request, \Drupal::service('flood'));
    $catalog = \Drupal::service('nelkano_modules.api_catalog');
    $module = $catalog->module($module_id);
    $query = ModuleApiQuery::parse($request);
    $metadata = $catalog->version($module, $version, $query);
    if (!$metadata['available']) { throw new GoneHttpException('download_unavailable'); }
    $release = NULL;
    foreach (\Drupal::service('nelkano_modules.registry')->releases($module) as $candidate) {
      if ($candidate->get('field_release_version')->value === $version) { $release = $candidate; break; }
    }
    $fid = $release ? ModuleHostedPackages::fid($release) : 0;
    if (!$fid) { throw new NotFoundHttpException('version_not_found'); }
    $hosted = \Drupal::service('nelkano_modules.hosted_packages');
    try {
      $file = $hosted->file($fid, $module, $release, FALSE);
      $path = $hosted->path($file);
      if (filesize($path) !== $metadata['size_bytes'] || !hash_equals($metadata['sha256'], hash_file('sha256', $path))) {
        throw new \InvalidArgumentException('El paquete no coincide.');
      }
    }
    catch (\InvalidArgumentException) { throw new GoneHttpException('download_unavailable'); }
    $response = new BinaryFileResponse($path, 200, [
      'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
      'Cache-Control' => 'private, no-store', 'Vary' => 'Authorization, Cookie',
    ], FALSE, NULL, FALSE, FALSE);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $module_id . '-' . $version . '.nelkmod');
    return $response;
  }
}
