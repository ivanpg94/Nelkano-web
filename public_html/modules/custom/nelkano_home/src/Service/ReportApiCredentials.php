<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

/** Dedicated PC credentials, unrelated to player or admin sessions. */
final class ReportApiCredentials {

  public static function issue(string $client, int $days = 90): string {
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $client) || $days < 1 || $days > 365) {
      throw new \InvalidArgumentException('Client: 1-64 letters/digits/_/-. Lifetime: 1-365 days.');
    }
    $token = 'nkr_' . bin2hex(random_bytes(32));
    self::revoke($client);
    \Drupal::keyValue('nelkano_report_api_tokens')->set(hash('sha256', $token), [
      'client' => $client, 'expires' => time() + $days * 86400,
    ]);
    return $token;
  }

  public static function revoke(string $client): void {
    $store = \Drupal::keyValue('nelkano_report_api_tokens');
    foreach ($store->getAll() as $hash => $record) {
      if ($record['client'] === $client) {
        $store->delete($hash);
      }
    }
  }

  public static function authenticate(string $authorization): ?string {
    if (!preg_match('/^Bearer (nkr_[a-f0-9]{64})$/D', $authorization, $matches)) {
      return NULL;
    }
    $record = \Drupal::keyValue('nelkano_report_api_tokens')->get(hash('sha256', $matches[1]));
    return is_array($record) && $record['expires'] > time() ? $record['client'] : NULL;
  }

}
