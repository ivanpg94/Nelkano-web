<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nelkano_home\Service\SdkReference;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Conditional public documentation download for SDK Studio. */
final class SdkReferenceController extends ControllerBase {

  public function __construct(private readonly SdkReference $reference) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('nelkano_home.sdk_reference'));
  }

  public function reference(Request $request): JsonResponse {
    $data = $this->reference->snapshot();
    // Drupal's CacheableResponse subscriber removes validators when page
    // caching is disabled site-wide. This route deliberately bypasses Drupal
    // response caches and controls HTTP revalidation itself instead.
    $response = new JsonResponse($data, 200, [
      'Content-Type' => 'application/json; charset=UTF-8',
      'X-Content-Type-Options' => 'nosniff',
    ]);
    $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->setPublic();
    $response->setMaxAge(0);
    $response->headers->addCacheControlDirective('must-revalidate');
    $response->setEtag($data['revision']);
    $response->isNotModified($request);
    return $response;
  }
}
