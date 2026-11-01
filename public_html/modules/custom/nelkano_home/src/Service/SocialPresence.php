<?php

declare(strict_types=1);
namespace Drupal\nelkano_home\Service;

/** Short-lived activity. Only accepted-friend responses may expose describe(). */
final class SocialPresence {
  public const TTL = 90;
  public function __construct(private $store, private $userData, private int $now) {}
  public function sharing(int $uid): bool {
    return $this->userData->get('nelkano_home', $uid, 'share_activity') === TRUE;
  }
  public function setSharing(int $uid, bool $enabled): void {
    $this->userData->set('nelkano_home', $uid, 'share_activity', $enabled);
    if (!$enabled) $this->store->delete((string) $uid);
  }
  public function update(int $uid, array $payload): void {
    if (!is_bool($payload['online'] ?? NULL) || !is_string($payload['clientId'] ?? NULL) ||
        !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $payload['clientId'])) throw new \InvalidArgumentException('invalid-presence');
    foreach (['gameTitle' => 128, 'systemId' => 64] as $field => $maximum) {
      if (isset($payload[$field]) && (!is_string($payload[$field]) || mb_strlen($payload[$field]) > $maximum || preg_match('/[\x00-\x1f\x7f]/u', $payload[$field]))) throw new \InvalidArgumentException('invalid-presence');
    }
    if (!$this->sharing($uid)) { $this->store->delete((string) $uid); return; }
    $clients = $this->live($uid);
    unset($clients[$payload['clientId']]);
    if ($payload['online']) {
      $clients[$payload['clientId']] = ['updatedAt' => $this->now, 'expiresAt' => $this->now + self::TTL,
        'gameTitle' => trim($payload['gameTitle'] ?? ''), 'systemId' => trim($payload['systemId'] ?? '')];
    }
    uasort($clients, static fn(array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);
    $clients = array_slice($clients, 0, 8, TRUE);
    if (!$clients) { $this->store->delete((string) $uid); return; }
    $this->store->setWithExpire((string) $uid, $clients, self::TTL);
  }
  public function describe(int $uid): array {
    if (!$this->sharing($uid)) return ['online' => FALSE];
    $clients = $this->live($uid);
    if (!$clients) return ['online' => FALSE];
    uasort($clients, static fn(array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);
    $latest = reset($clients);
    return ['online' => TRUE, 'gameTitle' => $latest['gameTitle'], 'systemId' => $latest['systemId']];
  }
  private function live(int $uid): array {
    $clients = $this->store->get((string) $uid, []);
    return is_array($clients) ? array_filter($clients, fn($value): bool => is_array($value) && ($value['expiresAt'] ?? 0) > $this->now) : [];
  }
}
