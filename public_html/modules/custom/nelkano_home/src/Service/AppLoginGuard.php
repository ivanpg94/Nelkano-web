<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\user\Entity\User;
use Drupal\user\UserAuthenticationInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Password checks for the app APIs with Drupal flood control.
 *
 * Unknown, blocked or unverified accounts and wrong passwords all return the
 * same result, and unknown accounts still pay for a password hash, so the API
 * does not reveal which emails are registered. Limits come from user.flood.
 */
final class AppLoginGuard {

  public const OK = 'ok';
  public const INVALID = 'invalid';
  public const LIMITED = 'limited';

  private const IP_EVENT = 'nelkano_home.app_login_ip';
  private const USER_EVENT = 'nelkano_home.app_login_user';
  private const DUMMY_HASH_STATE = 'nelkano_home.app_login_dummy_hash';

  /** @return array{status: string, account?: User} */
  public static function authenticate(Request $request, string $login, string $password): array {
    $flood = \Drupal::flood();
    $config = \Drupal::config('user.flood');
    $ip = (string) $request->getClientIp();
    $ip_limit = (int) ($config->get('ip_limit') ?: 50);
    $ip_window = (int) ($config->get('ip_window') ?: 3600);
    $user_limit = (int) ($config->get('user_limit') ?: 5);
    $user_window = (int) ($config->get('user_window') ?: 21600);

    if (!$flood->isAllowed(self::IP_EVENT, $ip_limit, $ip_window, $ip)) {
      return ['status' => self::LIMITED];
    }

    $account = self::loadAccount($login);
    $identifier = $account instanceof User
      ? ($config->get('uid_only') ? (string) $account->id() : $account->id() . '-' . $ip)
      : '';
    if ($identifier !== '' && !$flood->isAllowed(self::USER_EVENT, $user_limit, $user_window, $identifier)) {
      return ['status' => self::LIMITED];
    }

    $valid = FALSE;
    if ($account instanceof User && $account->isActive()) {
      $auth = \Drupal::service('user.auth');
      $valid = $auth instanceof UserAuthenticationInterface
        ? $auth->authenticateAccount($account, $password)
        : (bool) $auth->authenticate($account->getAccountName(), $password);
    }
    else {
      \Drupal::service('password')->check($password, self::dummyHash());
    }

    if (!$valid) {
      $flood->register(self::IP_EVENT, $ip_window, $ip);
      if ($identifier !== '') {
        $flood->register(self::USER_EVENT, $user_window, $identifier);
      }
      return ['status' => self::INVALID];
    }

    $flood->clear(self::USER_EVENT, $identifier);
    return ['status' => self::OK, 'account' => $account];
  }

  private static function loadAccount(string $login): ?User {
    if ($login === '') {
      return NULL;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    foreach (['mail', 'name'] as $field) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition($field, $login)
        ->range(0, 1)
        ->execute();
      if ($ids) {
        $account = $storage->load((int) reset($ids));
        return $account instanceof User ? $account : NULL;
      }
    }
    return NULL;
  }

  private static function dummyHash(): string {
    $state = \Drupal::state();
    $hash = $state->get(self::DUMMY_HASH_STATE);
    if (!is_string($hash) || $hash === '') {
      $hash = (string) \Drupal::service('password')->hash(bin2hex(random_bytes(16)));
      $state->set(self::DUMMY_HASH_STATE, $hash);
    }
    return $hash;
  }

}
