<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\Core\Database\Connection;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CommunityBlocks {

  private const COLLECTION = 'nelkano_community_groups.direct_preferences';

  public static function preferences(int $owner, int $peer): array {
    $value = \Drupal::keyValue(self::COLLECTION)->get("$owner:$peer", []);
    return ['muted' => !empty($value['muted']), 'blocked' => !empty($value['blocked'])];
  }

  public static function between(int $first, int $second): bool {
    return $first > 0 && $second > 0 && $first !== $second &&
      (self::preferences($first, $second)['blocked'] || self::preferences($second, $first)['blocked']);
  }

  /** Gets the active accounts that this user can unblock. */
  public static function blockedUsers(int $owner): array {
    $db = \Drupal::database();
    $names = $db->select('key_value', 'kv')->fields('kv', ['name'])->condition('collection', self::COLLECTION)
      ->condition('name', "$owner:%", 'LIKE')->execute()->fetchCol();
    $ids = [];
    foreach (\Drupal::keyValue(self::COLLECTION)->getMultiple($names) as $name => $value) {
      if (!empty($value['blocked'])) {
        $ids[] = (int) substr($name, strpos($name, ':') + 1);
      }
    }
    if (!$ids) {
      return [];
    }
    $profiles = $db->select('nelkano_profile', 'p')->fields('p', ['uid', 'display_name'])
      ->condition('uid', $ids, 'IN')->execute()->fetchAllKeyed();
    $result = [];
    foreach (\Drupal::entityTypeManager()->getStorage('user')->loadMultiple($ids) as $account) {
      if (!$account instanceof UserInterface || !$account->isActive()) {
        continue;
      }
      $uid = (int) $account->id();
      $name = trim((string) ($profiles[$uid] ?? '')) ?: $account->getDisplayName();
      if (strcasecmp($name, (string) $account->getEmail()) === 0) {
        $name = "Usuario $uid";
      }
      $result[] = ['uid' => $uid, 'name' => $name];
    }
    usort($result, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $result;
  }

  public static function sendInvitation(Connection $db, int $owner, int $peer, int $now): bool {
    $lock = \Drupal::lock();
    $name = 'nelkano_community_groups.membership';
    if (!$lock->acquire($name, 10)) {
      return FALSE;
    }
    try {
      if (self::between($owner, $peer)) {
        return FALSE;
      }
      $existing = $db->select('nelkano_friendship', 'f')->fields('f', ['id']);
      $existing->condition($existing->orConditionGroup()
        ->condition($existing->andConditionGroup()->condition('requester_uid', $owner)->condition('recipient_uid', $peer))
        ->condition($existing->andConditionGroup()->condition('requester_uid', $peer)->condition('recipient_uid', $owner)));
      if ($existing->range(0, 1)->execute()->fetchField()) {
        return TRUE;
      }
      $db->insert('nelkano_friendship')->fields(['requester_uid' => $owner, 'recipient_uid' => $peer,
        'status' => 'pending', 'created' => $now, 'updated' => $now])->execute();
      return TRUE;
    }
    finally {
      $lock->release($name);
    }
  }

  public static function change(Connection $db, int $owner, int $peer, array $changes): array {
    // Share the mailbox lock so setting a block cannot race a queued delivery.
    $lock = \Drupal::lock();
    $name = 'nelkano_community_groups.membership';
    if (!$lock->acquire($name, 10)) {
      throw new HttpException(409, 'Inténtalo de nuevo.');
    }
    try {
      $transaction = $db->startTransaction();
      $settings = $changes + self::preferences($owner, $peer);
      \Drupal::keyValue(self::COLLECTION)->set("$owner:$peer", $settings);
      if ($changes['blocked'] ?? FALSE) {
        if ($db->schema()->tableExists('nelkano_chat_envelope') && $db->schema()->tableExists('nelkano_chat_delivery')) {
          $thread = 'd:' . substr(hash('sha256', min($owner, $peer) . ':' . max($owner, $peer)), 0, 30);
          $envelopes = $db->select('nelkano_chat_envelope', 'e')->fields('e', ['id'])->condition('group_id', $thread);
          $db->delete('nelkano_chat_delivery')->condition('message_id', $envelopes, 'IN')->execute();
          $db->update('nelkano_chat_envelope')->fields(['body' => ''])->condition('group_id', $thread)->execute();
        }
        if ($db->schema()->tableExists('nelkano_friendship')) {
          $pending = $db->delete('nelkano_friendship');
          $pending->condition('status', 'pending')->condition($pending->orConditionGroup()
            ->condition($pending->andConditionGroup()->condition('requester_uid', $owner)->condition('recipient_uid', $peer))
            ->condition($pending->andConditionGroup()->condition('requester_uid', $peer)->condition('recipient_uid', $owner)));
          $pending->execute();
        }
      }
      unset($transaction);
      return $settings;
    }
    catch (\Throwable $error) {
      if (isset($transaction)) $transaction->rollBack();
      unset($transaction);
      throw $error;
    }
    finally {
      $lock->release($name);
    }
  }

  public static function purge(int $uid): void {
    $db = \Drupal::database();
    $remove = $db->delete('key_value');
    $remove->condition('collection', self::COLLECTION)
      ->condition($remove->orConditionGroup()->condition('name', "$uid:%", 'LIKE')->condition('name', "%:$uid", 'LIKE'));
    $remove->execute();
  }

}
