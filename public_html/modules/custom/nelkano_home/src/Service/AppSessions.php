<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\user\Entity\User;

/**
 * App sessions: short access tokens plus rotating refresh tokens.
 *
 * Every refresh creates a new row in the same family and marks the previous
 * one as rotated. Presenting a rotated refresh token outside the grace window
 * means it was copied, so the whole family is revoked. Only SHA-256 hashes of
 * the tokens are stored.
 */
final class AppSessions {

  public const TABLE = 'nelkano_app_session';
  public const ACCESS_TTL = 3600;
  public const REFRESH_TTL = 2592000;

  /** Seconds in which a rotated refresh token means "retry", not theft. */
  private const REUSE_GRACE = 30;

  /** Minimum seconds between last_used writes for one session. */
  private const TOUCH_INTERVAL = 300;

  private const ACCESS_PREFIX = 'nkat_';
  private const REFRESH_PREFIX = 'nkrt_';

  /**
   * Starts a new session family for a freshly authenticated account.
   *
   * @return array{access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int}
   */
  public static function issue(User $account, array $device): array {
    return self::insertGeneration((int) $account->id(), bin2hex(random_bytes(16)), self::cleanDevice($device));
  }

  /** Resolves a valid, non-rotated access token to its active account. */
  public static function accountFromAccessToken(string $token): ?User {
    $row = self::rowForAccessToken($token);
    if (!$row) {
      return NULL;
    }
    $account = User::load((int) $row['uid']);
    if (!$account instanceof User || !$account->isActive()) {
      return NULL;
    }
    $now = \Drupal::time()->getRequestTime();
    if ($now - (int) $row['last_used'] >= self::TOUCH_INTERVAL) {
      \Drupal::database()->update(self::TABLE)
        ->fields(['last_used' => $now])
        ->condition('id', (int) $row['id'])
        ->execute();
    }
    return $account;
  }

  /** Device id bound to a valid access token, or '' when it is not valid. */
  public static function deviceForAccessToken(string $token): string {
    $row = self::rowForAccessToken($token);
    return $row ? (string) $row['device_id'] : '';
  }

  /**
   * Rotates a refresh token.
   *
   * @return array{status: string, tokens?: array, account?: User, device_id?: string}
   *   status is one of ok, invalid (401), reused (401, family revoked) or
   *   retry (409, a concurrent refresh already rotated this token).
   */
  public static function refresh(string $refresh_token, string $device_id): array {
    if (!self::validToken($refresh_token, self::REFRESH_PREFIX)) {
      return ['status' => 'invalid'];
    }
    $database = \Drupal::database();
    $row = $database->select(self::TABLE, 's')
      ->fields('s')
      ->condition('refresh_hash', hash('sha256', $refresh_token))
      ->execute()
      ->fetchAssoc();
    if (!$row || (int) $row['revoked'] !== 0) {
      return ['status' => 'invalid'];
    }

    $now = \Drupal::time()->getRequestTime();
    if ((int) $row['rotated'] !== 0) {
      if ($now - (int) $row['rotated'] <= self::REUSE_GRACE) {
        return ['status' => 'retry'];
      }
      self::revokeFamily((string) $row['family_id']);
      \Drupal::logger('nelkano_home')->warning('Refresh token reutilizado para la cuenta @uid; sesion @family revocada.', [
        '@uid' => (int) $row['uid'],
        '@family' => (string) $row['family_id'],
      ]);
      return ['status' => 'reused'];
    }
    if ((int) $row['refresh_expires'] < $now) {
      return ['status' => 'invalid'];
    }
    $device_id = self::cleanId($device_id);
    if ($device_id !== '' && (string) $row['device_id'] !== '' && !hash_equals((string) $row['device_id'], $device_id)) {
      self::revokeFamily((string) $row['family_id']);
      return ['status' => 'reused'];
    }
    $account = User::load((int) $row['uid']);
    if (!$account instanceof User || !$account->isActive()) {
      self::revokeFamily((string) $row['family_id']);
      return ['status' => 'invalid'];
    }

    // Only one concurrent caller can win the rotation.
    $claimed = $database->update(self::TABLE)
      ->fields(['rotated' => $now])
      ->condition('id', (int) $row['id'])
      ->condition('rotated', 0)
      ->condition('revoked', 0)
      ->execute();
    if (!$claimed) {
      return ['status' => 'retry'];
    }

    $tokens = self::insertGeneration((int) $row['uid'], (string) $row['family_id'], [
      'id' => (string) $row['device_id'],
      'platform' => (string) $row['platform'],
      'name' => (string) $row['device_name'],
    ]);
    return ['status' => 'ok', 'tokens' => $tokens, 'account' => $account, 'device_id' => (string) $row['device_id']];
  }

  /** Revokes the family owning an access or refresh token. Idempotent. */
  public static function revokeByToken(string $token): void {
    if (self::validToken($token, self::ACCESS_PREFIX)) {
      $column = 'access_hash';
    }
    elseif (self::validToken($token, self::REFRESH_PREFIX)) {
      $column = 'refresh_hash';
    }
    else {
      return;
    }
    $family = \Drupal::database()->select(self::TABLE, 's')
      ->fields('s', ['family_id'])
      ->condition($column, hash('sha256', $token))
      ->execute()
      ->fetchField();
    if (is_string($family) && $family !== '') {
      self::revokeFamily($family);
    }
  }

  /** Signs the account out of every app session, including legacy tokens. */
  public static function revokeAllForUser(int $uid): void {
    if (\Drupal::database()->schema()->tableExists(self::TABLE)) {
      \Drupal::database()->update(self::TABLE)
        ->fields(['revoked' => \Drupal::time()->getRequestTime()])
        ->condition('uid', $uid)
        ->condition('revoked', 0)
        ->execute();
    }
    $user_data = \Drupal::service('user.data');
    foreach (['app_tokens', 'app_token_hash', 'app_token_expires'] as $name) {
      $user_data->delete('nelkano_home', $uid, $name);
    }
  }

  /** Drops generations whose refresh token can no longer be used. */
  public static function cleanup(): void {
    if (!\Drupal::database()->schema()->tableExists(self::TABLE)) {
      return;
    }
    $cutoff = \Drupal::time()->getRequestTime() - 86400;
    \Drupal::database()->delete(self::TABLE)
      ->condition('refresh_expires', $cutoff, '<')
      ->execute();
  }

  public static function isAccessToken(string $token): bool {
    return str_starts_with($token, self::ACCESS_PREFIX);
  }

  private static function rowForAccessToken(string $token): ?array {
    if (!self::validToken($token, self::ACCESS_PREFIX)) {
      return NULL;
    }
    $row = \Drupal::database()->select(self::TABLE, 's')
      ->fields('s', ['id', 'uid', 'device_id', 'access_expires', 'rotated', 'revoked', 'last_used'])
      ->condition('access_hash', hash('sha256', $token))
      ->execute()
      ->fetchAssoc();
    $now = \Drupal::time()->getRequestTime();
    if (!$row || (int) $row['revoked'] !== 0 || (int) $row['rotated'] !== 0 || (int) $row['access_expires'] < $now) {
      return NULL;
    }
    return $row;
  }

  private static function insertGeneration(int $uid, string $family_id, array $device): array {
    $now = \Drupal::time()->getRequestTime();
    $access = self::ACCESS_PREFIX . bin2hex(random_bytes(32));
    $refresh = self::REFRESH_PREFIX . bin2hex(random_bytes(32));
    $access_expires = $now + self::ACCESS_TTL;
    $refresh_expires = $now + self::REFRESH_TTL;
    \Drupal::database()->insert(self::TABLE)->fields([
      'family_id' => $family_id,
      'uid' => $uid,
      'device_id' => $device['id'],
      'platform' => $device['platform'],
      'device_name' => $device['name'],
      'access_hash' => hash('sha256', $access),
      'access_expires' => $access_expires,
      'refresh_hash' => hash('sha256', $refresh),
      'refresh_expires' => $refresh_expires,
      'created' => $now,
      'last_used' => $now,
      'rotated' => 0,
      'revoked' => 0,
    ])->execute();
    return [
      'access_token' => $access,
      'access_expires_at' => $access_expires,
      'refresh_token' => $refresh,
      'refresh_expires_at' => $refresh_expires,
    ];
  }

  private static function revokeFamily(string $family_id): void {
    \Drupal::database()->update(self::TABLE)
      ->fields(['revoked' => \Drupal::time()->getRequestTime()])
      ->condition('family_id', $family_id)
      ->condition('revoked', 0)
      ->execute();
  }

  private static function cleanDevice(array $device): array {
    return [
      'id' => self::cleanId((string) ($device['id'] ?? '')),
      'platform' => mb_substr(trim(strip_tags((string) ($device['platform'] ?? 'android'))), 0, 32),
      'name' => mb_substr(trim(strip_tags((string) ($device['name'] ?? ''))), 0, 120),
    ];
  }

  private static function cleanId(string $value): string {
    return substr(preg_replace('/[^a-zA-Z0-9._:-]/', '', $value) ?? '', 0, 128);
  }

  private static function validToken(string $token, string $prefix): bool {
    return (bool) preg_match('/^' . preg_quote($prefix, '/') . '[a-f0-9]{64}$/D', $token);
  }

}
