<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nelkano_home\Service\AppEntitlements;
use Drupal\nelkano_home\Service\AppLoginGuard;
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Session API v2 for the apps: login, refresh, logout and me.
 *
 * Errors carry a stable `code` so clients never branch on translated text.
 */
final class AppSessionController extends ControllerBase {

  public function login(Request $request): JsonResponse {
    $payload = $this->payload($request);
    if ($payload === NULL) {
      return $this->error('Peticion no valida.', 400, 'bad_request');
    }
    $login = trim((string) ($payload['email'] ?? $payload['username'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    if ($login === '' || $password === '') {
      return $this->error('Indica email y contrasena.', 400, 'bad_request');
    }

    $result = AppLoginGuard::authenticate($request, $login, $password);
    if ($result['status'] === AppLoginGuard::LIMITED) {
      return $this->error('Demasiados intentos. Prueba de nuevo mas tarde.', 429, 'rate_limited');
    }
    if ($result['status'] !== AppLoginGuard::OK) {
      return $this->error('Credenciales incorrectas o cuenta sin verificar.', 401, 'invalid_credentials');
    }

    $account = $result['account'];
    $device = is_array($payload['device'] ?? NULL) ? $payload['device'] : [];
    $tokens = AppSessions::issue($account, $device);
    return $this->sessionResponse($account, $tokens, (string) ($device['id'] ?? ''));
  }

  public function refresh(Request $request): JsonResponse {
    $payload = $this->payload($request);
    $refresh_token = trim((string) ($payload['refresh_token'] ?? ''));
    $device_id = (string) ($payload['device_id'] ?? '');
    if ($refresh_token === '') {
      return $this->error('Falta el refresh token.', 400, 'bad_request');
    }

    $result = AppSessions::refresh($refresh_token, $device_id);
    return match ($result['status']) {
      'ok' => $this->sessionResponse($result['account'], $result['tokens'], $result['device_id']),
      'retry' => $this->error('La sesion se esta renovando. Reintenta con el token mas reciente.', 409, 'refresh_in_progress'),
      default => $this->error('Sesion caducada. Inicia sesion otra vez.', 401, 'session_expired'),
    };
  }

  public function logout(Request $request): JsonResponse {
    $payload = $this->payload($request) ?? [];
    AppSessions::revokeByToken($this->bearer($request));
    AppSessions::revokeByToken(trim((string) ($payload['refresh_token'] ?? '')));
    return new JsonResponse(['ok' => TRUE]);
  }

  public function me(Request $request): JsonResponse {
    $token = $this->bearer($request);
    $account = AppSessions::isAccessToken($token) ? AppSessions::accountFromAccessToken($token) : NULL;
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401, 'invalid_token');
    }
    return new JsonResponse([
      'ok' => TRUE,
      'user' => $this->userPayload($account),
    ] + AppEntitlements::forAccount($account, AppSessions::deviceForAccessToken($token)));
  }

  private function sessionResponse(User $account, array $tokens, string $device_id): JsonResponse {
    $device_id = substr(preg_replace('/[^a-zA-Z0-9._:-]/', '', $device_id) ?? '', 0, 128);
    return new JsonResponse([
      'ok' => TRUE,
      'token_type' => 'Bearer',
      'user' => $this->userPayload($account),
    ] + $tokens + AppEntitlements::forAccount($account, $device_id));
  }

  private function userPayload(User $account): array {
    return [
      'uid' => (int) $account->id(),
      'name' => $account->getDisplayName(),
      'email' => (string) $account->getEmail(),
    ];
  }

  private function bearer(Request $request): string {
    $header = trim((string) $request->headers->get('Authorization', ''));
    return preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) ? $matches[1] : '';
  }

  private function payload(Request $request): ?array {
    $payload = json_decode((string) $request->getContent(), TRUE);
    return is_array($payload) ? $payload : NULL;
  }

  private function error(string $message, int $status, string $code): JsonResponse {
    return new JsonResponse(['ok' => FALSE, 'code' => $code, 'message' => $message], $status);
  }

}
