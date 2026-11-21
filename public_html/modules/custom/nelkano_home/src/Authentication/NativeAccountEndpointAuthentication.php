<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/** Native account mutations validate their own opaque sessions in the controller. */
final class NativeAccountEndpointAuthentication implements AuthenticationProviderInterface {

  public function applies(Request $request): bool {
    return $request->isMethod('POST') && in_array($request->getPathInfo(), [
      '/api/nelkano/friends/request',
      '/api/nelkano/friends/accept',
      '/api/nelkano/friends/reject',
      '/api/nelkano/friends/remove',
      '/api/nelkano/friends/presence',
      '/api/nelkano/profile',
      '/api/nelkano/game/session/connect',
      '/api/nelkano/game/session/end',
      '/api/nelkano/game/signaling/event',
    ], TRUE);
  }

  public function authenticate(Request $request) {
    // Do not create a Drupal login. AppAuthController still checks the native
    // access token, account permissions and ownership for every operation.
    return NULL;
  }

}
