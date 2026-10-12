<?php

namespace Drupal\nelkano_modules\Service;

interface DownloadClientInterface {
  /** Return status, body and optional etag/modified; throw on unsafe destinations. */
  public function fetch(string $url, array $validators = []): array;
}