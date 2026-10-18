<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Update channels for the new app and Studio; legacy APK rows without build codes are excluded. */
final class AppUpdatesController extends ControllerBase {
  public const PLATFORMS = ['android-arm64' => ['com.ivanp.nelkano.modular', 'apk'], 'windows-x86_64' => ['nelkano.desktop', 'msi'], 'linux-x86_64' => ['nelkano.desktop', 'zip'], 'sdk-studio-windows-x86_64' => ['nelkano.sdk.studio', 'zip']];

  public function latest(string $platform): JsonResponse {
    if (!isset(self::PLATFORMS[$platform])) throw new NotFoundHttpException();
    $catalog = $this->catalog($platform);
    if ($catalog !== NULL) {
      $latest = NULL;
      foreach ($catalog as $row) {
        if (\Drupal\nelkano_home\Service\ReleaseArtifact::hidden($row)) continue;
        $file = \Drupal\nelkano_home\Service\ReleaseArtifact::file($row, 'update');
        if ($file['path'] === '') continue;
        $latest = ['app_id' => self::PLATFORMS[$platform][0], 'platform' => $platform, 'version' => $row['version'],
          'version_code' => (int) $row['version_code'], 'format' => self::PLATFORMS[$platform][1],
          'bytes' => filesize($file['path']), 'sha256' => hash_file('sha256', $file['path']), 'notes' => (string) ($row['changes'] ?? ''),
          'download_url' => '/api/nelkano/v1/app/releases/' . $platform . '/' . $row['version_code'] . '/download'];
        break;
      }
      return new JsonResponse(['api_version' => 1, 'ok' => TRUE, 'latest' => $latest], 200, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    $config = $this->config('nelkano_home.app_updates');
    $build = $config->get('active.' . $platform);
    $row = $build ? $config->get('releases.' . $platform . '.' . $build) : NULL;
    $latest = NULL;
    if (is_array($row) && $this->artifact($platform, $row)) {
      $latest = ['app_id' => self::PLATFORMS[$platform][0], 'platform' => $platform,
        'version' => $row['version'], 'version_code' => (int) $build, 'format' => self::PLATFORMS[$platform][1],
        'bytes' => (int) $row['bytes'], 'sha256' => $row['sha256'], 'notes' => $row['notes'] ?? '',
        'download_url' => '/api/nelkano/v1/app/releases/' . $platform . '/' . $build . '/download'];
    }
    return new JsonResponse(['api_version' => 1, 'ok' => TRUE, 'latest' => $latest], 200,
      ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
  }

  public function download(string $platform, string $build): BinaryFileResponse {
    if (!isset(self::PLATFORMS[$platform])) throw new NotFoundHttpException();
    $catalog = $this->catalog($platform);
    if ($catalog !== NULL) {
      foreach ($catalog as $row) {
        if ((string) $row['version_code'] !== $build || \Drupal\nelkano_home\Service\ReleaseArtifact::hidden($row)) continue;
        $file = \Drupal\nelkano_home\Service\ReleaseArtifact::file($row, 'update');
        if ($file['path'] === '') continue;
        $response = new \Drupal\nelkano_home\Http\ReleaseFileResponse($file['path']);
        $response->setContentDisposition('attachment', $file['filename']);
        $response->headers->set('Content-Type', $platform === 'android-arm64' ? 'application/vnd.android.package-archive' : 'application/octet-stream');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
      }
      throw new NotFoundHttpException();
    }
    $row = $this->config('nelkano_home.app_updates')->get('releases.' . $platform . '.' . $build);
    $path = is_array($row) ? $this->artifact($platform, $row) : NULL;
    if (!$path) throw new NotFoundHttpException();
    $response = new \Drupal\nelkano_home\Http\ReleaseFileResponse($path);
    $response->setContentDisposition('attachment', 'Nelkano-' . preg_replace('/[^0-9.]/', '', $row['version']) . '.' . self::PLATFORMS[$platform][1]);
    $response->headers->set('Content-Type', $platform === 'android-arm64' ? 'application/vnd.android.package-archive' : 'application/octet-stream');
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  /** Spanish catalog owns update publication; translated copy controls its public page. */
  private function catalog(string $channel): ?array {
    $rows = $this->config('nelkano_home.docs')->get('es.releases_items') ?? [];
    $matches = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
      $platform = \Drupal\nelkano_home\Service\ReleaseArtifact::platform($row);
      if ((\Drupal\nelkano_home\Service\ReleaseArtifact::PLATFORMS[$platform][2] ?? '') === $channel && (int) ($row['version_code'] ?? 0) > 0) $matches[] = $row;
    }
    if (!$matches) return NULL;
    usort($matches, static fn($a, $b) => ((int) $b['version_code']) <=> ((int) $a['version_code']));
    return $matches;
  }

  private function artifact(string $platform, array $row): ?string {
    $file = File::load((int) ($row['file_id'] ?? 0));
    if (!$file || !str_starts_with($file->getFileUri(), 'private://app-updates/' . $platform . '/')) return NULL;
    $path = \Drupal::service('file_system')->realpath($file->getFileUri());
    $directory = \Drupal::service('file_system')->realpath('private://app-updates/' . $platform);
    return is_string($path) && is_string($directory) && str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
      && is_file($path) && filesize($path) === (int) ($row['bytes'] ?? 0)
      && preg_match('/^[a-f0-9]{64}$/D', $row['sha256'] ?? '') ? $path : NULL;
  }
}
