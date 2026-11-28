<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/** Native account mutations validate their own opaque sessions in the controller. */
final class NativeAccountEndpointAuthentication implements AuthenticationProviderInterface {

  private const STREAM_SIGNALING_MUTATIONS = [
    '/api/nelkano/stream/signaling/session',
    '/api/nelkano/stream/signaling/event',
    '/api/nelkano/stream/signaling/session/end',
  ];

  public function applies(Request $request): bool {
    if (!$request->isMethod('POST')) {
      return FALSE;
    }
    $path = $request->getPathInfo();
    if (in_array($path, self::STREAM_SIGNALING_MUTATIONS, TRUE)) {
      // Web receivers can also use their Drupal login on the signaling routes.
      $authorization = trim((string) $request->headers->get('Authorization', ''));
      return preg_match('/^Bearer\s+nkat_[a-f0-9]{64}$/D', $authorization) === 1
        || preg_match('/^Bearer\s+\d+\.[a-f0-9]{64}$/iD', $authorization) === 1;
    }
    return in_array($path, [
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
