<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

/** Bounded removal of transient connection data; never library/activity/reports. */
final class ConnectionPrivacyRetention {
  private const SUFFIXES = ['frame', 'frame.seq', 'frame.mime', 'audio', 'audio.seq', 'audio.rate', 'audio.channels', 'audio.duration'];

  /** Opt-in only: enabling the scheduler is an explicit operations step. */
  public static function runScheduled(): bool {
    if (!self::enabled()) { return FALSE; }
    $now = \Drupal::time()->getCurrentTime();
    $state = \Drupal::state();
    if ((int) $state->get('nelkano_home.connection_retention_next', 0) > $now) { return FALSE; }
    $lock = \Drupal::lock();
    if (!$lock->acquire('nelkano_connection_retention', 300)) { return FALSE; }
    try {
      if ((int) $state->get('nelkano_home.connection_retention_next', 0) > $now) { return FALSE; }
      $result = self::cleanup();
      $appSessions = AppSessions::cleanup();
      $technical = self::cleanupTechnicalRows();
      $state->set('nelkano_home.connection_retention_last', ['at' => $now, 'connections' => $result, 'technicalRows' => $technical, 'appSessions' => $appSessions]);
      $state->set('nelkano_home.connection_retention_next', $now + (($result['more'] || $appSessions === 500 || in_array(500,$technical,TRUE)) ? 60 : 3600));
      return TRUE;
    }
    catch (\Throwable $error) {
      $state->set('nelkano_home.connection_retention_next', $now + 300);
      // Do not put payloads, paths, identifiers or credentials in this log.
      \Drupal::logger('nelkano_home')->warning('Connection retention could not finish; retry scheduled.');
      return FALSE;
    }
    finally { $lock->release('nelkano_connection_retention'); }
  }

  public static function enabled(): bool {
    $override = getenv('NELKANO_CONNECTION_RETENTION_ENABLED');
    if ($override === '0') { return FALSE; }
    return $override === '1' || (bool) \Drupal::state()->get('nelkano_home.connection_retention_enabled', FALSE);
  }

  /** Thirty-day operational cap for own technical logs/stale web-session rows. */
  public static function cleanupTechnicalRows(?array $onlyUids = NULL): array {
    $db = \Drupal::database(); $cutoff = \Drupal::time()->getCurrentTime() - 30 * 86400;
    $counts = [];
    foreach (['watchdog' => ['wid', 'timestamp'], 'sessions' => ['sid', 'timestamp']] as $table => [$id, $date]) {
      if (!$db->schema()->tableExists($table)) { continue; }
      $q = $db->select($table, 't')->fields('t', [$id])->condition($date, $cutoff, '<');
      if ($onlyUids !== NULL) {
        $uids = array_values(array_filter(array_map('intval', $onlyUids), static fn(int $uid): bool => $uid > 0));
        if (!$uids) { $counts[$table] = 0; continue; }
        $q->condition('uid', $uids, 'IN');
      }
      $ids = $q->orderBy($date)->range(0, 500)->execute()->fetchCol();
      $counts[$table] = $ids ? (int) $db->delete($table)->condition($id, $ids, 'IN')->condition($date, $cutoff, '<')->execute() : 0;
    }
    return $counts;
  }

  /** Explicit UID scope is required by fixtures; NULL means a bounded global batch. */
  public static function cleanup(?array $onlyUids = NULL): array {
    $scope = $onlyUids === NULL ? NULL : array_values(array_unique(array_filter(array_map('intval', $onlyUids), static fn(int $uid): bool => $uid > 0)));
    $result = ['sessions' => 0, 'events' => 0, 'more' => FALSE];
    if ($scope === []) { return $result; }
    $now = \Drupal::time()->getCurrentTime();
    $db = \Drupal::database();
    $remaining = 100;
    $eventBudget = 500;
    foreach (['stream', 'game'] as $kind) {
      $table = 'nelkano_' . $kind . '_session';
      $events = 'nelkano_' . $kind . '_signal_event';
      if (!$db->schema()->tableExists($table)) { continue; }
      $query = $db->select($table, 's')->fields('s');
      $query->condition($query->orConditionGroup()->condition('expires', $now, '<=')->condition('status', 'closed'));
      if ($scope !== NULL) {
        if ($kind === 'stream') { $query->condition('uid', $scope, 'IN'); }
        else { $query->condition($query->orConditionGroup()->condition('host_uid', $scope, 'IN')->condition('guest_uid', $scope, 'IN')); }
      }
      $rows = $query->orderBy('expires')->orderBy('id')->range(0, $remaining + 1)->execute()->fetchAll(\PDO::FETCH_ASSOC);
      if (count($rows) > $remaining) { $result['more'] = TRUE; array_pop($rows); }
      foreach ($rows as $candidate) {
        $uids = $kind === 'stream' ? [(int) $candidate['uid']] : [(int) $candidate['host_uid'], (int) $candidate['guest_uid']];
        $keys = AccountErasure::tryLockAccounts($uids);
        if ($keys === NULL) { $result['more'] = TRUE; continue; }
        try {
          $row = $db->select($table, 's')->fields('s')->condition('id', (int) $candidate['id'])->execute()->fetchAssoc();
          if (!$row || !self::expired($row, $now)) { continue; }
          // Recheck ownership as well as expiry after acquiring participant locks.
          $freshUids = $kind === 'stream' ? [(int) $row['uid']] : [(int) $row['host_uid'], (int) $row['guest_uid']];
          if ($freshUids !== $uids) { $result['more'] = TRUE; continue; }
          $activeDuplicate = FALSE;
          if ($kind === 'stream') {
            $activeDuplicate = $db->select($table, 's')->fields('s', ['id'])->condition('uid', (int) $row['uid'])->condition('session_id', $row['session_id'])->condition('status', 'closed', '<>')->condition('expires', $now, '>')->range(0, 1)->execute()->fetchField() !== FALSE;
          }
          // Old rows can share a session ID reused by an active connection.
          if ($activeDuplicate) {
            $db->delete($table)->condition('id', (int) $row['id'])->execute();
            $result['sessions']++; $remaining--; continue;
          }
          if ($db->schema()->tableExists($events)) {
            $select = $db->select($events, 'e')->fields('e', ['id'])->condition('session_id', $row['session_id']);
            if ($kind === 'stream') { $select->condition('uid', (int) $row['uid']); }
            if ($eventBudget > 0) {
              $ids = $select->orderBy('id')->range(0, $eventBudget)->execute()->fetchCol();
              if ($ids) { $db->delete($events)->condition('id', $ids, 'IN')->execute(); $eventBudget -= count($ids); $result['events'] += count($ids); }
            }
            $left = $db->select($events, 'e')->fields('e', ['id'])->condition('session_id', $row['session_id']);
            if ($kind === 'stream') { $left->condition('uid', (int) $row['uid']); }
            if ($left->range(0, 1)->execute()->fetchField() !== FALSE) { $result['more'] = TRUE; continue; }
          }
          if ($kind === 'stream') {
            self::purgeSessionFiles((int) $row['uid'], (string) $row['session_id']);
          }
          $db->delete($table)->condition('id', (int) $row['id'])->execute();
          $result['sessions']++;
          $remaining--;
        }
        finally { AccountErasure::releaseAccountLocks($keys); }
      }
      if ($remaining <= 0) { $result['more'] = TRUE; break; }
    }
    // Signals have no continuing purpose after two hours, even if a row survived.
    foreach (['stream', 'game'] as $kind) {
      if ($eventBudget <= 0) { $result['more'] = TRUE; break; }
      $table = 'nelkano_' . $kind . '_signal_event';
      if (!$db->schema()->tableExists($table)) { continue; }
      $owner = $kind === 'stream' ? 'uid' : 'sender_uid';
      $query = $db->select($table, 'e')->fields('e')->condition('created', $now - 7200, '<');
      if ($scope !== NULL) { $query->condition($owner, $scope, 'IN'); }
      $rows = $query->orderBy('id')->range(0, $eventBudget + 1)->execute()->fetchAll(\PDO::FETCH_ASSOC);
      if (count($rows) > $eventBudget) { $result['more'] = TRUE; array_pop($rows); }
      foreach ($rows as $candidate) {
        $keys = AccountErasure::tryLockAccounts([(int) $candidate[$owner]]);
        if ($keys === NULL) { $result['more'] = TRUE; continue; }
        try {
          $deleted = $db->delete($table)->condition('id', (int) $candidate['id'])->condition($owner, (int) $candidate[$owner])->condition('created', $now - 7200, '<')->execute();
          $result['events'] += (int) $deleted; $eventBudget -= (int) $deleted;
        }
        finally { AccountErasure::releaseAccountLocks($keys); }
      }
    }
    return $result;
  }

  private static function expired(array $row, int $now): bool {
    return (string) $row['status'] === 'closed' || (int) $row['expires'] <= $now;
  }

  /** Caller holds the account mutation lock. Does not modify the filesystem. */
  public static function preflightAccountFiles(int $uid): void {
    self::accountFileManifest($uid);
  }

  /** Caller holds the account mutation lock; no recursive or foreign-UID deletion. */
  public static function purgeAccountFiles(int $uid): void {
    foreach (self::accountFileManifest($uid) as $directory => $paths) {
      foreach ($paths as $path) { self::deleteFile($path); }
      if (is_dir($directory) && !@rmdir($directory)) { throw new \RuntimeException('Cannot remove the account connection directory.'); }
    }
  }

  /** Remove only the eight exact supported filenames for a validated session. */
  private static function purgeSessionFiles(int $uid, string $session): void {
    if (!self::validSessionId($session)) { throw new \RuntimeException('Unsafe connection session identifier.'); }
    foreach (['private', 'public'] as $scheme) {
      $directory = self::accountDirectory($scheme, $uid);
      if ($directory === NULL) { continue; }
      $paths = [];
      foreach (self::SUFFIXES as $suffix) {
        $path = $directory . DIRECTORY_SEPARATOR . $session . '.' . $suffix;
        if (is_link($path)) { throw new \RuntimeException('Connection files must not be symbolic links.'); }
        if (!file_exists($path)) { continue; }
        self::validateFile($path, $directory); $paths[] = $path;
      }
      foreach ($paths as $path) { self::deleteFile($path); }
      // Other active sessions in this UID directory must survive.
      $handle = @opendir($directory);
      if ($handle === FALSE) { throw new \RuntimeException('Cannot inspect the connection directory.'); }
      $empty = TRUE;
      while (($name = readdir($handle)) !== FALSE) { if ($name !== '.' && $name !== '..') { $empty = FALSE; break; } }
      closedir($handle);
      if ($empty) { @rmdir($directory); }
    }
  }

  private static function accountFileManifest(int $uid): array {
    $manifest = [];
    foreach (['private', 'public'] as $scheme) {
      $directory = self::accountDirectory($scheme, $uid);
      if ($directory === NULL) { continue; }
      if (!is_writable($directory)) { throw new \RuntimeException('Connection directory is not writable.'); }
      $handle = @opendir($directory);
      if ($handle === FALSE) { throw new \RuntimeException('Cannot inspect account connection files.'); }
      $paths = [];
      try {
        while (($name = readdir($handle)) !== FALSE) {
          if ($name === '.' || $name === '..') { continue; }
          $recognized = FALSE;
          foreach (self::SUFFIXES as $suffix) {
            $ending = '.' . $suffix;
            if (str_ends_with($name, $ending) && self::validSessionId(substr($name, 0, -strlen($ending)))) { $recognized = TRUE; break; }
          }
          if (!$recognized) { throw new \RuntimeException('Unexpected file in account connection storage; manual review required.'); }
          $path = $directory . DIRECTORY_SEPARATOR . $name;
          self::validateFile($path, $directory); $paths[] = $path;
        }
      }
      finally { closedir($handle); }
      $manifest[$directory] = $paths;
    }
    return $manifest;
  }

  private static function accountDirectory(string $scheme, int $uid): ?string {
    if ($uid < 1 || !in_array($scheme, ['private', 'public'], TRUE)) { throw new \InvalidArgumentException('Invalid connection file owner.'); }
    $base = \Drupal::service('file_system')->realpath($scheme . '://');
    if (!is_string($base) || $base === '') { return NULL; }
    $base = realpath($base);
    if ($base === FALSE) { return NULL; }
    $root = $base . DIRECTORY_SEPARATOR . 'nelkano-stream';
    $directory = $root . DIRECTORY_SEPARATOR . $uid;
    foreach ([$root, $directory] as $path) {
      if (is_link($path)) { throw new \RuntimeException('Connection directories must not be symbolic links.'); }
      if (!file_exists($path)) { return NULL; }
      if (!is_dir($path) || realpath($path) !== $path) { throw new \RuntimeException('Unsafe connection storage path.'); }
    }
    return $directory;
  }

  private static function validSessionId(string $session): bool {
    return $session !== '.' && $session !== '..' && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $session) === 1;
  }

  private static function validateFile(string $path, string $directory): void {
    if (is_link($path) || !is_file($path) || realpath($path) !== $path || dirname($path) !== $directory || !is_writable($directory)) {
      throw new \RuntimeException('Unsafe or non-removable connection file.');
    }
  }

  private static function deleteFile(string $path): void {
    self::validateFile($path, dirname($path));
    if (!@unlink($path)) { throw new \RuntimeException('Cannot remove the connection file.'); }
  }
}
