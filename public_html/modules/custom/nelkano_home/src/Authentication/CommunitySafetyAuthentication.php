<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\nelkano_home\Service\AppSessions;
use Symfony\Component\HttpFoundation\Request;

/** Authenticates Nelkano tokens only on the native safety endpoint. */
final class CommunitySafetyAuthentication implements AuthenticationProviderInterface {

  public function applies(Request $request): bool {
    return $request->isMethod('POST') && rawurldecode($request->getPathInfo()) === '/api/nelkano/community/safety'
      && $this->token($request) !== NULL;
  }

  public function authenticate(Request $request): ?AccountInterface {
    $token = $this->token($request);
    return $token === NULL ? NULL : AppSessions::accountFromAccessToken($token);
  }

  private function token(Request $request): ?string {
    return preg_match('/^Bearer (nkat_[a-f0-9]{64})$/D', (string) $request->headers->get('Authorization'), $matches)
      ? $matches[1] : NULL;
  }

}
