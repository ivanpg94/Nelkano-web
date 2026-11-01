<?php

declare(strict_types=1);
namespace Drupal\nelkano_home\Service;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;

/** UUID/date suppression register with an independent, non-site-backup copy. */
final class AccountErasureLedger {
  private const MAX_BYTES = 16777216;

  public static function record(string $uuid): void {
    if (!preg_match('/^[a-f0-9-]{36}$/D', $uuid)) throw new \InvalidArgumentException('Invalid account identifier.');
    $time = \Drupal::time()->getCurrentTime();
    // Write the non-restored copy before completing the account deletion.
    self::append(self::mirrorPath(), $uuid, $time);
    self::append(self::path(), $uuid, $time);
  }

  private static function append(string $path, string $uuid, int $time): void {
    $handle = fopen($path, 'c+b');
    if (!$handle || !flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot persist the private suppression register.');
    try {
      if (fstat($handle)['size'] > self::MAX_BYTES) throw new \RuntimeException('Suppression register requires maintenance.');
      $found = FALSE;
      while (($line = fgets($handle)) !== FALSE) {
        $row = self::row($line);
        if ($row['account_uuid'] === $uuid) $found = TRUE;
      }
      if (!$found) {
        fseek($handle, 0, SEEK_END);
        $line = json_encode(['version'=>1,'account_uuid'=>$uuid,'requested_at'=>$time], JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) throw new \RuntimeException('Cannot persist suppression request.');
        if (function_exists('fsync') && !fsync($handle)) throw new \RuntimeException('Cannot sync suppression request.');
      }
      self::alignOwner($path, dirname($path));
      chmod($path, 0600);
    }
    finally { flock($handle, LOCK_UN); fclose($handle); }
  }

  private static function row(string $line): array {
    $row = json_decode($line, TRUE, 8, JSON_THROW_ON_ERROR);
    if (($row['version'] ?? NULL) !== 1 || !preg_match('/^[a-f0-9-]{36}$/D', (string)($row['account_uuid'] ?? '')) || !is_int($row['requested_at'] ?? NULL) || $row['requested_at'] < 1) throw new \RuntimeException('Invalid private suppression register.');
    return $row;
  }

  public static function paths(): array { return [self::path(), self::mirrorPath()]; }

  /** Merge the current independent register with any restored site copy. */
  public static function entries(): array {
    $entries = [];
    foreach (self::paths() as $path) {
      if (!is_file($path)) continue;
      if (filesize($path) > self::MAX_BYTES) throw new \RuntimeException('Suppression register requires maintenance.');
      $handle = fopen($path, 'rb');
      if (!$handle || !flock($handle, LOCK_SH)) throw new \RuntimeException('Cannot read suppression register.');
      try {
        while (($line = fgets($handle)) !== FALSE) {
          $row = self::row($line); $uuid = $row['account_uuid'];
          if (!isset($entries[$uuid]) || $row['requested_at'] < $entries[$uuid]['requested_at']) $entries[$uuid] = $row;
        }
      }
      finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    return array_values($entries);
  }

  public static function seedMirror(): int {
    $rows = self::entries(); $mirror = self::mirrorPath();
    foreach ($rows as $row) self::append($mirror, $row['account_uuid'], $row['requested_at']);
    return count($rows);
  }

  public static function path(): string {
    $fs = \Drupal::service('file_system'); $uri = (string)Settings::get('nelkano_erasure_ledger_uri', 'private://nelkano-account-erasures');
    if (!preg_match('@^private://nelkano-account-erasures(?:-qa-[a-z0-9_]+)?$@D', $uri)) throw new \RuntimeException('Unsafe suppression URI.');
    if (!$fs->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) throw new \RuntimeException('Private storage unavailable.');
    $private = realpath($fs->realpath('private://') ?: ''); $directory = $fs->realpath($uri);
    if (!$private || !$directory || is_link($directory) || dirname(realpath($directory) ?: '') !== $private) throw new \RuntimeException('Unsafe suppression directory.');
    self::alignOwner($directory, $private); chmod($directory, 0700);
    return self::safeFile($directory);
  }

  public static function mirrorPath(): string {
    $directory = (string) Settings::get('nelkano_erasure_registry_directory', dirname(DRUPAL_ROOT, 2) . '/nelkano-erasure-registry');
    $public = realpath(DRUPAL_ROOT); $private = realpath(\Drupal::service('file_system')->realpath('private://') ?: '');
    if (!str_starts_with($directory, '/') || str_contains($directory, '://') || is_link($directory)) throw new \RuntimeException('Unsafe independent suppression directory.');
    $fs = \Drupal::service('file_system');
    if (!$fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) throw new \RuntimeException('Independent suppression storage unavailable.');
    $real = realpath($directory);
    foreach ([$public, $private] as $root) if ($root && ($real === $root || str_starts_with($real ?: '', $root . '/'))) throw new \RuntimeException('Independent register must be outside public and private site files.');
    if (!$real || $real !== rtrim($directory, '/')) throw new \RuntimeException('Independent register path is not canonical.');
    chmod($real, 0700);
    return self::safeFile($real);
  }

  private static function safeFile(string $directory): string {
    $path = $directory . '/ledger.jsonl';
    if (is_link($path) || (file_exists($path) && !is_file($path))) throw new \RuntimeException('Unsafe suppression file.');
    return $path;
  }

  /** Root CLI fixtures/deploy preparation must not make files unwritable to PHP. */
  private static function alignOwner(string $path, string $parent): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
      clearstatcache(TRUE, $parent);
      if (!chown($path, fileowner($parent)) || !chgrp($path, filegroup($parent))) throw new \RuntimeException('Cannot preserve private storage ownership.');
    }
  }
}