<?php

declare(strict_types=1);

namespace Drupal\nelkano_ai\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/** Keeps the AI API's opaque Bearer credentials out of OAuth's JWT parser. */
final class AiEndpointAuthentication implements AuthenticationProviderInterface {

  public function applies(Request $request): bool {
    return str_starts_with($request->getPathInfo(), '/api/nelkano/v1/ai/');
  }

  public function authenticate(Request $request) {
    // These endpoints deliberately have no Drupal login identity. AiController
    // authenticates every operation with AiAccess: native Tester session or
    // dedicated worker key. Never turn a worker key into a user account.
    return NULL;
  }

}
