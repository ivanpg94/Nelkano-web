<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/** Leaves native report credentials to the upload controller, ahead of OAuth. */
final class ReportEndpointAuthentication implements AuthenticationProviderInterface {

  public function applies(Request $request): bool {
    return $request->isMethod('POST') && $request->getPathInfo() === '/api/nelkano/error-reports';
  }

  public function authenticate(Request $request) {
    // AppAuthController checks the session and Tester permission on every upload.
    // An opaque app token must never create a Drupal login identity or enter
    // Simple OAuth's JWT parser before the controller can validate it.
    return NULL;
  }

}
