<?php
namespace Drupal\nelkano_home\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Streams large managed releases without retaining them in PHP output buffers. */
final class ReleaseFileResponse extends BinaryFileResponse {
  public function sendContent(): static {
    self::closeOutputBuffers(0, TRUE);
    return parent::sendContent();
  }
}
