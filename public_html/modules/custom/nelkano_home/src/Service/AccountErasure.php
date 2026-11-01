<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\user\UserInterface;

/** Deletes server-side account data; local games and external Drive are separate. */
final class AccountErasure {
  private const OWNED_TABLES = ['nelkano_profile', 'nelkano_device', 'nelkano_library_item', 'nelkano_play_session', 'nelkano_stream_session', 'nelkano_stream_signal_event', 'nelkano_app_session'];

  public static function selfServiceAllowed(UserInterface $account): bool {
    return (int) $account->id() > 1 && !$account->hasPermission('administer users') && !$account->hasRole('administrator');
  }

  public static function erase(UserInterface $account): void {
    if (!self::selfServiceAllowed($account)) {
      throw new \DomainException('Las cuentas administradoras requieren revisión manual. Contacta con contacto@nelkano.com.');
    }
    $lock = \Drupal::lock(); $key = self::lockKey((int) $account->id());
    if (!$lock->acquire($key, 300)) { throw new \RuntimeException('La cuenta está ocupada. Vuelve a intentarlo.'); }
    try {
      self::preflight($account);
      AccountErasureLedger::record($account->uuid());
      // Reject new logins and bearer requests before files and relationships go away.
      $account->block()->save();
      AppSessions::revokeAllForUser((int) $account->id());
      $account->delete(); // The delete hook also covers administrative deletions.
    }
    finally { $lock->release($key); }
  }

  public static function lockKey(int $uid): string { return 'nelkano_account_mutation:' . $uid; }

  /** Non-blocking sorted locks for retention work; NULL leaves every account alone. */
  public static function tryLockAccounts(array $uids, float $seconds = 300): ?array {
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn(int $uid): bool => $uid > 0)));
    sort($uids, SORT_NUMERIC); $held = [];
    foreach ($uids as $uid) {
      $key = self::lockKey($uid);
      if (!\Drupal::lock()->acquire($key, $seconds)) { self::releaseAccountLocks($held); return NULL; }
      $held[] = $key;
    }
    return $held;
  }
  public static function releaseAccountLocks(array $keys): void {
    foreach (array_reverse($keys) as $key) { \Drupal::lock()->release($key); }
  }


  public static function preflight(UserInterface $account): void {
    if ((int) $account->id() < 1) { throw new \InvalidArgumentException('Cuenta no válida.'); }
    foreach (self::reportIds((int) $account->id()) as $id) {
      ReportDeletion::preflightForAccountErasure($id, (int) $account->id());
    }
    self::avatarFiles((int) $account->id(), $account);
    ConnectionPrivacyRetention::preflightAccountFiles((int) $account->id());
    AccountErasureLedger::path();
  }

  /** Called from hook_user_delete; never removes another account or editorial node. */
  public static function purge(UserInterface $account): void {
    $uid = (int) $account->id();
    if ($uid < 1) { throw new \InvalidArgumentException('No se borra la cuenta anónima.'); }
    self::preflight($account);
    AccountErasureLedger::record($account->uuid());
    $avatars = self::avatarFiles($uid, $account);
    ConnectionPrivacyRetention::purgeAccountFiles($uid);
    AppSessions::revokeAllForUser($uid);
    foreach (self::reportIds($uid) as $id) { ReportDeletion::deleteForAccountErasure($id, $uid); }
    $db = \Drupal::database(); $schema = $db->schema();
    if ($schema->tableExists('nelkano_game_session')) {
      $condition = $db->condition('OR')->condition('host_uid', $uid)->condition('guest_uid', $uid);
      $sessions = $db->select('nelkano_game_session', 's')->fields('s', ['session_id'])->condition($condition)->execute()->fetchCol();
      if ($schema->tableExists('nelkano_game_signal_event')) {
        $events = $db->condition('OR')->condition('sender_uid', $uid);
        if ($sessions) { $events->condition('session_id', $sessions, 'IN'); }
        $db->delete('nelkano_game_signal_event')->condition($events)->execute();
      }
      $db->delete('nelkano_game_session')->condition($condition)->execute();
    }
    if ($schema->tableExists('nelkano_friendship')) {
      $db->delete('nelkano_friendship')->condition($db->condition('OR')->condition('requester_uid', $uid)->condition('recipient_uid', $uid))->execute();
    }
    foreach (self::OWNED_TABLES as $table) {
      if ($schema->tableExists($table)) { $db->delete($table)->condition('uid', $uid)->execute(); }
    }
    foreach ($avatars as $uri => $file) {
      foreach (\Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple() as $style) { $style->flush($uri); }
      if ($file) { $file->delete(); }
      elseif (file_exists($uri) && !\Drupal::service('file_system')->delete($uri)) {
        throw new \RuntimeException('No se puede eliminar la imagen del perfil.');
      }
    }
    // Ordinary accounts cannot author editorial content. Preserve any such work,
    // while removing identifiers from current and historic ownership metadata.
    foreach (['node_field_data' => 'uid', 'node_field_revision' => 'uid', 'node_revision' => 'revision_uid'] as $table => $field) {
      if ($schema->tableExists($table) && $schema->fieldExists($table, $field)) { $db->update($table)->fields([$field => 0])->condition($field, $uid)->execute(); }
    }
    if (\Drupal::entityTypeManager()->hasDefinition('comment')) {
      $storage = \Drupal::entityTypeManager()->getStorage('comment');
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uid', $uid)->execute();
      foreach ($storage->loadMultiple($ids) as $comment) { $comment->delete(); }
    }
    \Drupal::service('user.data')->delete(NULL, $uid);
    \Drupal::service('session_manager')->delete($uid);
    if ($schema->tableExists('users_data')) { $db->delete('users_data')->condition('uid', $uid)->execute(); }
    if ($schema->tableExists('watchdog') && $schema->fieldExists('watchdog', 'uid')) {
      // Free text/context can contain an old account identifier; remove its rows.
      $db->delete('watchdog')->condition('uid', $uid)->execute();
    }
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['user:' . $uid, 'user_list', 'node_list']);
  }

  public static function reportIds(int $uid): array {
    $db = \Drupal::database();
    $ids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'nelkano_error_report')->condition('uid', $uid)->execute();
    if ($db->schema()->tableExists('node_field_revision') && $db->schema()->fieldExists('node_field_revision', 'uid')) {
      $historic = $db->select('node_field_revision', 'r')->fields('r', ['nid'])->condition('uid', $uid)->execute()->fetchCol();
      foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($historic) as $node) {
        if ($node->bundle() === 'nelkano_error_report') { $ids[$node->id()] = $node->id(); }
      }
    }
    return array_values(array_unique(array_map('intval', $ids)));
  }

  /** Only dedicated avatar files belonging to this account, with no foreign use. */
  private static function avatarFiles(int $uid, ?UserInterface $account = NULL): array {
    $db = \Drupal::database(); $fs = \Drupal::service('file_system'); $files = []; $picture_uri = '';
    $storage = \Drupal::entityTypeManager()->getStorage('file');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uid', $uid)->condition('uri', 'public://nelkano-avatars/', 'STARTS_WITH')->execute();
    foreach ($storage->loadMultiple($ids) as $file) { $files[$file->getFileUri()] = $file; }
    if ($account && $account->hasField('user_picture') && !$account->get('user_picture')->isEmpty()) {
      $picture = $account->get('user_picture')->entity;
      if ($picture && (int) $picture->getOwnerId() === $uid) {
        $picture_uri = $picture->getFileUri(); $files[$picture_uri] = $picture;
      }
    }
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
      $uri = 'public://nelkano-avatars/app_' . $uid . '.' . $extension;
      if (file_exists($uri)) { $files[$uri] = $files[$uri] ?? NULL; }
    }
    foreach ($files as $uri => $file) {
      $path = $fs->realpath($uri); $base = $fs->realpath('public://nelkano-avatars');
      $picture_root = $uri === $picture_uri ? $fs->realpath(str_starts_with($uri, 'private://') ? 'private://' : 'public://') : NULL;
      $real = $path && file_exists($path) ? realpath($path) : $path;
      $allowed = $uri === $picture_uri
        ? (bool) ($picture_root && $real && str_starts_with($real, rtrim(realpath($picture_root) ?: '', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))
        : (bool) ($path && $base && (!file_exists($path) || dirname($real ?: '') === realpath($base)));
      if (!$allowed || is_link($path)) { throw new \RuntimeException('La ruta de imagen requiere revisión manual.'); }
      $shared = $db->schema()->tableExists('nelkano_profile') ? (int) $db->select('nelkano_profile', 'p')->condition('uid', $uid, '<>')->condition('avatar_file_uri', $uri)->countQuery()->execute()->fetchField() : 0;
      $foreign_usage = FALSE;
      if ($file) {
        foreach (\Drupal::service('file.usage')->listUsage($file) as $module => $types) {
          foreach ($types as $type => $items) {
            foreach ($items as $id => $count) {
              if ($type !== 'user' || (int) $id !== $uid) { $foreign_usage = TRUE; }
            }
          }
        }
      }
      if ($shared || $foreign_usage) { throw new \RuntimeException('Una imagen está compartida; contacta con contacto@nelkano.com para revisar su supresión.'); }
    }
    return $files;
  }
}
