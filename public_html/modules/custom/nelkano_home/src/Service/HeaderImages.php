<?php

namespace Drupal\nelkano_home\Service;

use Drupal\image\Entity\ImageStyle;

/** Small presentation-only derivatives; profile uploads retain their originals. */
final class HeaderImages {

  public static function avatarUrl(string $uri): string {
    $original = \Drupal::service('file_url_generator')->generateString($uri);
    // Header avatars are public uploads. Never fetch remote or private images.
    if (!str_starts_with($uri, 'public://') || !is_file($uri)) {
      return $original;
    }
    try {
      $style = ImageStyle::load('nelkano_header_avatar');
      if (!$style || !$style->supportsUri($uri)) {
        return $original;
      }
      // Uploads can overwrite the same URI: content, not just the filename or
      // timestamp, must invalidate both the derivative and the browser cache.
      $fingerprint = hash_file('sha256', $uri);
      if ($fingerprint === FALSE) {
        return $original;
      }
      $derivative = $style->buildUri($uri);
      $cache = \Drupal::cache();
      $cid = 'nelkano_header_avatar:' . hash('sha256', $uri);
      $cached = $cache->get($cid);
      if (!$cached || $cached->data !== $fingerprint || !is_file($derivative)) {
        if (!$style->createDerivative($uri, $derivative)) {
          return $original;
        }
        $cache->set($cid, $fingerprint, \Drupal\Core\Cache\Cache::PERMANENT, $style->getCacheTags());
      }
      $url = $style->buildUrl($uri);
      return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . substr($fingerprint, 0, 12);
    }
    catch (\Throwable) {
      return $original;
    }
  }

}
