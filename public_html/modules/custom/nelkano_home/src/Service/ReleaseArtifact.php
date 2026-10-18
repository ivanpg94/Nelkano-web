<?php
namespace Drupal\nelkano_home\Service;

/** Resolves only managed release files; private artifacts never have direct URLs. */
final class ReleaseArtifact {
  public const PLATFORMS = ['android' => ['Android', 'apk', 'android-arm64'], 'windows' => ['Windows', 'msi', 'windows-x86_64'], 'steamos' => ['SteamOS', 'zip', 'linux-x86_64'], 'sdk' => ['SDK Studio (Windows)', 'zip', 'sdk-studio-windows-x86_64']];
  public static function platform(array $row): string { return trim((string) ($row['platform'] ?? '')) ?: 'android'; }
  public static function hidden(array $row): bool {
    return isset($row['visible']) && (is_bool($row['visible']) ? !$row['visible'] : in_array(strtolower(trim((string) $row['visible'])), ['0','false','no','off'], TRUE));
  }
  public static function latestPublished(string $platform, string $language = 'es'): array {
    $rows = \Drupal::config('nelkano_home.docs')->get($language . '.releases_items') ?? [];
    $rows = array_filter(is_array($rows) ? $rows : [], static fn($row) => self::platform($row) === $platform && !self::hidden($row));
    usort($rows, static fn($a, $b) => version_compare($b['version'] ?? '', $a['version'] ?? ''));
    foreach ($rows as $row) { $file = self::file($row); if ($file['path'] !== '') return $file + ['version' => $row['version']]; }
    return [];
  }
  public static function file(array $row, string $purpose = 'download'): array {
    $platform = self::platform($row);
    $installer = $platform === 'sdk' && ($purpose === 'installer' || ($purpose === 'download' && !empty($row['installer_file'])));
    $format = $installer ? 'msi' : (self::PLATFORMS[$platform][1] ?? '');
    $uri = trim((string) ($row[$installer ? 'installer_file' : 'apk_file'] ?? ''));
    $empty = ['path' => '', 'filename' => (string) ($row['filename'] ?? ''), 'url' => ''];
    if ($format === '' || !preg_match('@^(public|private)://nelkano-releases/[^/\\\\]+\.' . $format . '$@D', $uri)) return $empty;
    $fs = \Drupal::service('file_system');
    $path = $fs->realpath($uri); $directory = $fs->realpath(dirname($uri));
    if (!is_string($path) || !is_string($directory) || !is_file($path) || !str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) return $empty;
    $version = preg_replace('/[^A-Za-z0-9.+_-]/', '', (string) ($row['version'] ?? ''));
    $filename = ($platform === 'sdk' ? 'Nelkano SDK Studio' : 'Nelkano') . ' ' . $version . ($platform === 'android' ? '' : '-' . $platform) . '.' . $format;
    $language = str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
    $url = \Drupal\Core\Url::fromRoute($installer ? 'nelkano_home.sdk_installer_download' : 'nelkano_home.platform_release_download', ['language' => $language, 'platform' => $platform, 'version' => (string) $row['version']])->toString();
    return ['path' => $path, 'filename' => $filename, 'url' => $url];
  }
}
