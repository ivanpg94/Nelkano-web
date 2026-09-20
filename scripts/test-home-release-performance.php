<?php
namespace Drupal\nelkano_home\Controller {
  // Count actual checksum calls without changing the computed result.
  function hash_file($algorithm, $filename, $binary = FALSE) {
    $GLOBALS['release_hash_calls']++;
    return \hash_file($algorithm, $filename, $binary);
  }
}
namespace {
  use Drupal\nelkano_home\Controller\HomeController;
  $GLOBALS['release_hash_calls'] = 0;
  $controller = HomeController::create(\Drupal::getContainer());
  $module = \Drupal::service('extension.list.module')->getPath('nelkano_home');
  $latest = new \ReflectionMethod($controller, 'latestReleaseDownload');
  $version = new \ReflectionMethod($controller, 'publicVersion');
  $rows = new \ReflectionMethod($controller, 'releaseRows');
  $content = \Drupal::config('nelkano_home.docs')->get('es') ?? [];
  $start = microtime(TRUE);
  $download = $latest->invoke($controller, 'es', $module);
  $publicVersion = $version->invoke($controller);
  if ($GLOBALS['release_hash_calls'] !== 0) throw new \RuntimeException('Home/SEO read APK checksums');
  if (empty($download['url']) || empty($download['meta']['version']) || $publicVersion === '') throw new \RuntimeException('Missing download/version');
  echo 'PASS: home and SEO retain download/version with zero checksum calls (' . round((microtime(TRUE)-$start)*1000,2) . " ms)\n";
  $start = microtime(TRUE);
  $releases = $rows->invoke($controller, $content, $module);
  if ($GLOBALS['release_hash_calls'] < 1 || empty($releases[0]['meta']['sha256'])) throw new \RuntimeException('Versions lost checksum');
  echo 'PASS: versions retain SHA-256 (' . $GLOBALS['release_hash_calls'] . ' APK reads, ' . round((microtime(TRUE)-$start)*1000,2) . " ms)\n";
}
