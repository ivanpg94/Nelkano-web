<?php

declare(strict_types=1);

namespace Drupal\nelkano_modules\Service;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/** Bounded HTTPS fetches: public DNS only, pinned IP, no proxy, safe redirects. */
class SecureDownloadClient implements DownloadClientInterface {
  public static function validateUrl(string $url, bool $package = FALSE): void {
    $parts = parse_url($url);
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)
      || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
      || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
      || (($parts['port'] ?? 443) !== 443)
      || !preg_match('/^[a-zA-Z0-9.-]+$/D', $parts['host'])
      || ($package && !preg_match('/\.nelkmod$/iD', $parts['path'] ?? ''))) {
      throw new \InvalidArgumentException($package
        ? 'Usa un enlace HTTPS directo a un archivo .nelkmod.'
        : 'Usa una dirección HTTPS pública válida.');
    }
  }

  protected function resolve(string $host): string {
    $records = dns_get_record($host, DNS_A | DNS_AAAA);
    $ips = [];
    foreach ($records ?: [] as $record) {
      $ip = $record['ip'] ?? $record['ipv6'] ?? NULL;
      if ($ip !== NULL) {
        if (!self::publicIp($ip)) { throw new \InvalidArgumentException('La dirección no es pública.'); }
        $ips[] = $ip;
      }
    }
    if (!$ips) { throw new \RuntimeException('No se pudo resolver el servidor de descarga.'); }
    return $ips[0];
  }

  public static function publicIp(string $ip): bool {
    $packed = @inet_pton($ip);
    if ($packed && ((strlen($packed) === 4 && ord($packed[0]) >= 224) || (strlen($packed) === 16 && ord($packed[0]) === 255))) { return FALSE; }
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
      && !str_starts_with(strtolower($ip), '::ffff:');
  }

  protected function requestPinned(string $url, string $host, string $ip, array $validators, int $seconds): array {
    $body = '';
    $headers = [];
    $too_large = FALSE;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
      CURLOPT_FOLLOWLOCATION => FALSE, CURLOPT_CONNECTTIMEOUT => min(5, $seconds),
      CURLOPT_TIMEOUT => $seconds, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_SSL_VERIFYPEER => TRUE, CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'Nelkano-Module-Catalog/1.0',
      CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
      CURLOPT_HTTPHEADER => array_values(array_filter([
        'Accept: application/octet-stream', 'Accept-Encoding: identity',
        !empty($validators['etag']) ? 'If-None-Match: ' . $validators['etag'] : NULL,
        !empty($validators['modified']) ? 'If-Modified-Since: ' . $validators['modified'] : NULL,
      ])),
      CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
        if (str_starts_with($line, 'HTTP/')) { $headers = []; }
        elseif (str_contains($line, ':')) {
          [$key, $value] = explode(':', $line, 2);
          $headers[strtolower(trim($key))] = trim($value);
        }
        return strlen($line);
      },
      CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$too_large): int {
        if (strlen($body) + strlen($chunk) > PackageVerifier::MAX_ARCHIVE) {
          $too_large = TRUE;
          return 0;
        }
        $body .= $chunk;
        return strlen($chunk);
      },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($too_large) { throw new \InvalidArgumentException('El paquete supera los 32 MiB permitidos.'); }
    if ($ok === FALSE) { throw new \RuntimeException('El servidor de descarga no respondió a tiempo.'); }
    return ['status' => $status, 'body' => $body, 'location' => $headers['location'] ?? '',
      'etag' => substr($headers['etag'] ?? '', 0, 256), 'modified' => substr($headers['last-modified'] ?? '', 0, 128)];
  }

  public function fetch(string $url, array $validators = []): array {
    self::validateUrl($url, TRUE);
    // Do not allow user-supplied or remote validators to inject request headers.
    foreach ($validators as $value) {
      if (!is_string($value) || preg_match('/[\r\n]/', $value)) { throw new \InvalidArgumentException('Metadatos HTTP no válidos.'); }
    }
    $deadline = microtime(TRUE) + 20;
    for ($redirect = 0; $redirect <= 5; $redirect++) {
      self::validateUrl($url);
      $host = strtolower(parse_url($url, PHP_URL_HOST));
      $ip = $this->resolve($host);
      $seconds = (int) ceil($deadline - microtime(TRUE));
      if ($seconds < 1) { throw new \RuntimeException('La descarga agotó su tiempo de espera.'); }
      $response = $this->requestPinned($url, $host, $ip, $validators, $seconds);
      if (!in_array($response['status'], [301, 302, 303, 307, 308], TRUE)) { return $response; }
      if (!$response['location']) { throw new \InvalidArgumentException('La redirección de descarga no es válida.'); }
      $url = (string) UriResolver::resolve(new Uri($url), new Uri($response['location']));
    }
    throw new \InvalidArgumentException('El enlace tiene demasiadas redirecciones.');
  }
}