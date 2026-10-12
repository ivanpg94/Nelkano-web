<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\nelkano_modules\Service\ModuleApiCatalog;
use Drupal\nelkano_modules\Service\ModuleApiQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** Read-only API for the new app. Binary downloads remain at the author's host. */
final class ModuleApiController extends ControllerBase {
  public function __construct(private readonly ModuleApiCatalog $catalog, private readonly FloodInterface $flood) {}
  public static function create(ContainerInterface $container): static {
    return new static($container->get('nelkano_modules.api_catalog'), $container->get('flood'));
  }

  private function allow(Request $request): void {
    $ip = $request->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed('nelkano_modules.public_api', 240, 60, $ip)) { throw new TooManyRequestsHttpException(60); }
    $this->flood->register('nelkano_modules.public_api', 60, $ip);
  }

  public function catalog(Request $request): JsonResponse {
    $this->allow($request);
    return $this->response($request, $this->catalog->search(ModuleApiQuery::parse($request, TRUE, TRUE)));
  }
  public function detail(Request $request, string $module_id): JsonResponse {
    $this->allow($request);
    $query = ModuleApiQuery::parse($request);
    return $this->response($request, ['module' => $this->catalog->summary($this->catalog->module($module_id), $query, TRUE)]);
  }
  public function versions(Request $request, string $module_id): JsonResponse {
    $this->allow($request);
    return $this->response($request, $this->catalog->versions($this->catalog->module($module_id), ModuleApiQuery::parse($request, TRUE)));
  }
  public function version(Request $request, string $module_id, string $version): JsonResponse {
    $this->allow($request);
    $query = ModuleApiQuery::parse($request);
    $module = $this->catalog->module($module_id);
    $release = $this->catalog->version($module, $version, $query);
    $payload = ['module_id' => $module_id,
      'signing' => ['algorithm' => 'Ed25519', 'author_fingerprint_sha256' => (string) $module->get('field_module_author')->value],
      'release' => $release];
    if (!$release['available']) {
      return $this->response($request, $payload + ['code' => 'download_unavailable', 'message' => 'La descarga de esta versión no está disponible.'], 410);
    }
    return $this->response($request, $payload);
  }

  private function response(Request $request, array $payload, int $status = 200): JsonResponse {
    $response = new JsonResponse(NULL, $status, ['Cache-Control' => 'private, no-cache, must-revalidate', 'X-Content-Type-Options' => 'nosniff']);
    $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $response->setData(['api_version' => 1, 'ok' => $status === 200] + $payload);
    if ($status === 200) {
      $response->setEtag(hash('sha256', $response->getContent()));
      $response->isNotModified($request);
    }
    return $response;
  }
}