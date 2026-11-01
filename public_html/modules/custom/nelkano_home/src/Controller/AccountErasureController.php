<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nelkano_home\Service\AccountErasure;
use Drupal\nelkano_home\Service\AppLoginGuard;
use Drupal\nelkano_home\Service\AppSessions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Account erasure never takes an account id from the client. */
final class AccountErasureController extends ControllerBase {
  public function api(Request $request): JsonResponse {
    $headers = ['Cache-Control' => 'no-store, private'];
    if (!preg_match('/^Bearer\s+(nkat_[a-f0-9]{64})$/D', trim((string) $request->headers->get('Authorization', '')), $match)) {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Inicia sesión de nuevo.'], 401, $headers);
    }
    // Rights requests require valid credentials, without an age declaration.
    $account = AppSessions::accountFromAccessToken($match[1]);
    if (!$account) { return new JsonResponse(['ok' => FALSE, 'message' => 'Inicia sesión de nuevo.'], 401, $headers); }
    if (strlen($request->getContent()) > 8192 || !str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Petición no válida.'], 400, $headers);
    }
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body) || array_diff(array_keys($body), ['password', 'confirmation']) || !is_string($body['password'] ?? NULL) || ($body['confirmation'] ?? '') !== 'DELETE') {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Confirma el borrado y escribe tu contraseña actual.'], 400, $headers);
    }
    if (!AccountErasure::selfServiceAllowed($account)) {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Las cuentas administradoras requieren revisión manual. Contacta con contacto@nelkano.com.'], 403, $headers);
    }
    $auth = AppLoginGuard::authenticate($request, $account->getAccountName(), $body['password']);
    if ($auth['status'] !== AppLoginGuard::OK || (int) (($auth['account'] ?? NULL)?->id() ?? 0) !== (int) $account->id()) {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Contraseña incorrecta o demasiados intentos.'], $auth['status'] === AppLoginGuard::LIMITED ? 429 : 403, $headers);
    }
    try { AccountErasure::erase($account); }
    catch (\Throwable $error) {
      \Drupal::logger('nelkano_home')->error('Account erasure could not finish; review the private suppression register.');
      return new JsonResponse(['ok' => FALSE, 'message' => 'No se pudo completar el borrado. Contacta con contacto@nelkano.com para terminarlo.'], 503, $headers);
    }
    return new JsonResponse(['ok' => TRUE, 'message' => 'Cuenta y datos del servidor eliminados. Los juegos locales y las copias de Drive se gestionan aparte.'], 200, $headers);
  }
}
