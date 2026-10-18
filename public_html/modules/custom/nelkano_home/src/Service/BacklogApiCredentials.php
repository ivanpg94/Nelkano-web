<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/** Backlog-only credentials bound to a currently authorized Drupal account. */
final class BacklogApiCredentials {
  private const STORE = 'nelkano_backlog_api_tokens';

  public static function issue(string $client, int $uid, int $days = 90): string {
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $client) || $days < 1 || $days > 365) {
      throw new \InvalidArgumentException('Client: 1-64 letters/digits/_/-. Lifetime: 1-365 days.');
    }
    $account = User::load($uid);
    if (!$account || !$account->isActive() || !$account->hasPermission(HuWorkflow::PERMISSION) || !$account->hasPermission('access content')) {
      throw new \InvalidArgumentException('The account must be active and have access content and administer nelkano backlog permissions.');
    }
    $token = 'nkb_' . bin2hex(random_bytes(32));
    self::revoke($client);
    \Drupal::keyValue(self::STORE)->set(hash('sha256', $token), [
      'client' => $client, 'uid' => $uid, 'expires' => time() + $days * 86400,
    ]);
    return $token;
  }

  public static function revoke(string $client): void {
    $store = \Drupal::keyValue(self::STORE);
    foreach ($store->getAll() as $hash => $record) {
      if (($record['client'] ?? NULL) === $client) {
        $store->delete($hash);
      }
    }
  }

  public static function authenticate(string $authorization): ?UserInterface {
    if (!preg_match('/^Bearer (nkb_[a-f0-9]{64})$/D', $authorization, $matches)) {
      return NULL;
    }
    $record = \Drupal::keyValue(self::STORE)->get(hash('sha256', $matches[1]));
    if (!is_array($record) || ($record['expires'] ?? 0) <= time() || !is_int($record['uid'] ?? NULL)) {
      return NULL;
    }
    $account = User::load($record['uid']);
    return $account && $account->isActive() && $account->hasPermission(HuWorkflow::PERMISSION) && $account->hasPermission('access content') ? $account : NULL;
  }
}
