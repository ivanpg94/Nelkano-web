<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\Core\Site\Settings;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Roles and capabilities the app may show, signed with Ed25519.
 *
 * The app only adapts its UI from this document; every server feature still
 * checks the permission on each request. The app pins the public key, so a
 * document edited on the device or served by a fake server fails verification.
 */
final class AppEntitlements {

  /** Seconds the signed document stays valid while the app is offline. */
  public const TTL = 604800;

  /** Drupal permission => app capabilities it unlocks. */
  public const CAPABILITIES = [
    'use nelkano app features' => [
      'settings_full',
      'collections',
      'friends',
      'drive',
      'controls_config',
      'streaming',
      'multiplayer',
      'activity_sync',
    ],
    'send nelkano error reports' => ['error_reports'],
    'use nelkano debug tools' => ['fps_overlay_default'],
    'nelkano premium' => ['premium'],
  ];

  /** Highest first; decides which role the profile shows. */
  private const ROLE_PRIORITY = ['administrator', 'tester', 'premium', 'nelkano_editor', 'content_editor', 'authenticated'];

  private const STATE_SEED = 'nelkano_home.app_signing_seed';

  /** @return list<string> */
  public static function capabilities(User $account): array {
    $capabilities = [];
    foreach (self::CAPABILITIES as $permission => $granted) {
      if ($account->hasPermission($permission)) {
        array_push($capabilities, ...$granted);
      }
    }
    return array_values(array_unique($capabilities));
  }

  /** @return list<array{id: string, label: string}> Highest priority first. */
  public static function roles(User $account): array {
    $ids = array_values(array_diff($account->getRoles(), ['anonymous']));
    // Unknown roles rank right above plain 'authenticated'.
    $rank = static function (string $id): int {
      $index = array_search($id, self::ROLE_PRIORITY, TRUE);
      if ($id === 'authenticated') {
        return count(self::ROLE_PRIORITY);
      }
      return $index === FALSE ? count(self::ROLE_PRIORITY) - 1 : (int) $index;
    };
    usort($ids, static fn(string $a, string $b): int => $rank($a) <=> $rank($b) ?: strcmp($a, $b));
    $entities = Role::loadMultiple($ids);
    return array_map(static fn(string $id): array => [
      'id' => $id,
      'label' => isset($entities[$id]) ? (string) $entities[$id]->label() : $id,
    ], $ids);
  }

  /**
   * Public payload plus its signed form.
   *
   * @return array{roles: array, primary_role: array, capabilities: array, entitlements: string, entitlements_expires_at: int}
   */
  public static function forAccount(User $account, string $device_id): array {
    $roles = self::roles($account);
    $capabilities = self::capabilities($account);
    $now = \Drupal::time()->getRequestTime();
    $payload = [
      'v' => 1,
      'kid' => self::keyId(),
      'sub' => (int) $account->id(),
      'device_id' => $device_id,
      'roles' => array_column($roles, 'id'),
      'capabilities' => $capabilities,
      'iat' => $now,
      'exp' => $now + self::TTL,
    ];
    return [
      'roles' => $roles,
      'primary_role' => $roles[0] ?? ['id' => 'authenticated', 'label' => 'Authenticated user'],
      'capabilities' => $capabilities,
      'entitlements' => self::sign($payload),
      'entitlements_expires_at' => $payload['exp'],
    ];
  }

  /** base64url(JSON) . '.' . base64url(Ed25519 signature over the JSON). */
  public static function sign(array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $signature = sodium_crypto_sign_detached($json, self::keyPair()['secret']);
    return self::base64Url($json) . '.' . self::base64Url($signature);
  }

  public static function publicKeyBase64(): string {
    return base64_encode(self::keyPair()['public']);
  }

  public static function keyId(): string {
    return substr(hash('sha256', self::keyPair()['public']), 0, 16);
  }

  /** settings | env | state (auto-generated): where the signing seed comes from. */
  public static function keySource(): string {
    if (self::configuredSeed(Settings::get('nelkano_app_signing_seed')) !== NULL) {
      return 'settings';
    }
    if (self::configuredSeed(getenv('NELKANO_APP_SIGNING_SEED') ?: NULL) !== NULL) {
      return 'env';
    }
    return 'state';
  }

  /** @return array{public: string, secret: string} */
  private static function keyPair(): array {
    static $pair = NULL;
    if ($pair !== NULL) {
      return $pair;
    }
    $seed = self::configuredSeed(Settings::get('nelkano_app_signing_seed'))
      ?? self::configuredSeed(getenv('NELKANO_APP_SIGNING_SEED') ?: NULL);
    if ($seed === NULL) {
      $state = \Drupal::state();
      $seed = self::configuredSeed($state->get(self::STATE_SEED));
      if ($seed === NULL) {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $state->set(self::STATE_SEED, base64_encode($seed));
      }
    }
    $keys = sodium_crypto_sign_seed_keypair($seed);
    $pair = [
      'public' => sodium_crypto_sign_publickey($keys),
      'secret' => sodium_crypto_sign_secretkey($keys),
    ];
    return $pair;
  }

  private static function configuredSeed(mixed $value): ?string {
    if (!is_string($value) || trim($value) === '') {
      return NULL;
    }
    $seed = base64_decode(trim($value), TRUE);
    return is_string($seed) && strlen($seed) === SODIUM_CRYPTO_SIGN_SEEDBYTES ? $seed : NULL;
  }

  private static function base64Url(string $binary): string {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
  }

}
