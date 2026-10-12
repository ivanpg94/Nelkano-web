<?php

/** Synthetic packages and transport; never contacts remote servers. */
namespace NelkanoModuleTests;

use Drupal\nelkano_modules\Service\DownloadClientInterface;
use Drupal\nelkano_modules\Service\SecureDownloadClient;

final class FakeDownloadClient implements DownloadClientInterface {
  public array $responses = [];
  public array $requests = [];
  public function fetch(string $url, array $validators = []): array {
    SecureDownloadClient::validateUrl($url, TRUE);
    $this->requests[] = [$url, $validators];
    $value = $this->responses[$url] ?? ['status' => 404, 'body' => ''];
    if ($value instanceof \Throwable) { throw $value; }
    return $value;
  }
}

final class Packages {
  public static function zip(array $files): string {
    $local = '';
    $central = '';
    $offset = 0;
    foreach ($files as $path => $data) {
      $packed = gzdeflate($data);
      $crc = crc32($data);
      $size = strlen($data);
      $length = strlen($packed);
      $name_size = strlen($path);
      $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 33, $crc, $length, $size, $name_size, 0);
      $local .= $header . $path . $packed;
      $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 33, $crc, $length, $size, $name_size, 0, 0, 0, 0, 0, $offset) . $path;
      $offset += strlen($header) + $name_size + $length;
    }
    return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($local), 0);
  }

  public static function files(string $id, string $version, string $keypair, array $platforms = ['android', 'jvm-desktop'], string $sdk = '0.2.0'): array {
    $manifest = ['formatVersion' => 1, 'id' => $id, 'name' => 'Synthetic module', 'version' => $version,
      'sdkVersion' => $sdk, 'platforms' => $platforms, 'entry' => 'src/main.js',
      'bundledByDefault' => FALSE, 'dependencies' => [], 'capabilities' => ['library.read'], 'files' => ['src/main.js']];
    $files = ['manifest.json' => json_encode($manifest, JSON_UNESCAPED_SLASHES), 'src/main.js' => 'export function createModule() { return {}; }'];
    $index = [];
    foreach ($files as $path => $data) { $index[] = ['path' => $path, 'size' => strlen($data), 'sha256' => hash('sha256', $data)]; }
    $public = hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($keypair);
    $signature = ['algorithm' => 'Ed25519', 'publicKey' => base64_encode($public), 'files' => $index,
      'signature' => base64_encode(sodium_crypto_sign_detached(json_encode($index, JSON_UNESCAPED_SLASHES), sodium_crypto_sign_secretkey($keypair)))];
    $files['signature.json'] = json_encode($signature, JSON_UNESCAPED_SLASHES);
    return $files;
  }

  public static function make(string $id, string $version, string $keypair, array $platforms = ['android', 'jvm-desktop'], string $sdk = '0.2.0'): string {
    return self::zip(self::files($id, $version, $keypair, $platforms, $sdk));
  }
}