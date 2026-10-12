<?php

declare(strict_types=1);

namespace Drupal\nelkano_modules\Service;

/**
 * Verifies the SDK's bounded ZIP format and Ed25519 signature without extraction.
 *
 * Wire contract: SDK tooling package.mjs/zip.mjs, format 1, SDK 0.1.0/0.2.0.
 * Package authenticity is distinct from endorsement or publisher identity.
 */
final class PackageVerifier {
  public const MAX_ARCHIVE = 33554432;
  private const MAX_FILE = 8388608;
  private const CAPABILITIES = [
    'emulation.game-mod', 'emulation.memory.read', 'emulation.memory.write',
    'emulation.resources', 'emulation.handoff', 'presentation.overlay',
    'network.multiplayer', 'runtime.events', 'storage.private.cas', 'library.read',
    'library.delete', 'library.launch', 'library.cover', 'saves.read',
    'saves.autosave', 'emulation.read', 'emulation.control', 'presentation.player',
    'saves.write', 'saves.load', 'modules.hooks', 'presentation.appearance',
    'environment.display', 'presentation.control-layouts', 'account.session',
    'network.account', 'resources.image', 'input.gamepad', 'media.second-screen',
    'media.streaming', 'resources.patch', 'appearance.locale',
  ];

  private function require(bool $condition): void {
    if (!$condition) {
      throw new \InvalidArgumentException('El archivo no es un paquete .nelkmod válido y firmado.');
    }
  }

  private function integer(string $bytes, int $offset, int $size): int {
    $this->require($offset >= 0 && $offset + $size <= strlen($bytes));
    return unpack($size === 4 ? 'V' : 'v', substr($bytes, $offset, $size))[1];
  }

  private function safe(string $path): bool {
    return strlen($path) <= 255 && preg_match('/^[a-zA-Z0-9_.\/-]+$/D', $path)
      && !array_intersect(explode('/', $path), ['', '.', '..']);
  }

  private function contractSchema($schema, int $depth = 0): void {
    $this->require(is_array($schema) && $depth <= 8
      && !array_diff(array_keys($schema), ['type', 'properties', 'required', 'items', 'enum', 'additionalProperties'])
      && in_array($schema['type'] ?? NULL, ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'], TRUE));
    if (isset($schema['properties'])) {
      $this->require(is_array($schema['properties']) && count($schema['properties']) <= 32);
      foreach ($schema['properties'] as $property) { $this->contractSchema($property, $depth + 1); }
    }
    if (isset($schema['items'])) { $this->contractSchema($schema['items'], $depth + 1); }
    if (isset($schema['required'])) {
      $this->require(is_array($schema['required']) && array_is_list($schema['required']));
      foreach ($schema['required'] as $key) { $this->require(is_string($key) && array_key_exists($key, $schema['properties'] ?? [])); }
    }
    if (isset($schema['additionalProperties'])) { $this->require(is_bool($schema['additionalProperties'])); }
    if (isset($schema['enum'])) { $this->require(is_array($schema['enum']) && count($schema['enum']) > 0 && count($schema['enum']) <= 64); }
  }

  /** Match SDK hook/dependency/skin manifest constraints, without evaluating JS. */
  private function contracts(array $manifest): void {
    $name = static fn($value) => is_string($value) && preg_match('/^[a-z][a-z0-9.-]{0,127}$/D', $value);
    $version = static fn($value) => is_string($value) && preg_match('/^[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}$/D', $value);
    $provides = $manifest['provides'] ?? [];
    $consumes = $manifest['consumes'] ?? [];
    $dependencies = $manifest['dependencies'] ?? NULL;
    foreach ([[$provides, 16], [$consumes, 32], [$dependencies, 32]] as [$entries, $max]) {
      $this->require(is_array($entries) && array_is_list($entries) && count($entries) <= $max);
    }
    $seen = [];
    foreach ($provides as $hook) {
      $this->require(is_array($hook) && $name($hook['name'] ?? NULL) && $version($hook['version'] ?? NULL)
        && !isset($seen[$hook['name']]));
      $seen[$hook['name']] = TRUE;
      foreach (['contribution', 'input', 'output'] as $key) { $this->contractSchema($hook[$key] ?? NULL); }
    }
    foreach ([[$consumes, TRUE], [$dependencies, FALSE]] as [$entries, $consumer]) {
      $seen = [];
      foreach ($entries as $entry) {
        $this->require(is_array($entry));
        $identity = $consumer ? ($entry['provider'] ?? NULL) : ($entry['id'] ?? NULL);
        $this->require($name($identity) && $version($entry['version'] ?? NULL)
          && is_bool($entry['optional'] ?? NULL));
        if ($consumer) { $this->require($name($entry['hook'] ?? NULL)); }
        if (isset($entry['author'])) { $this->require(is_string($entry['author']) && preg_match('/^[0-9a-f]{64}$/D', $entry['author'])); }
        if (!$consumer) { $this->require($identity !== $manifest['id']); }
        $identity .= $consumer ? ':' . $entry['hook'] : '';
        $this->require(!isset($seen[$identity])); $seen[$identity] = TRUE;
      }
    }
    if ($provides || $consumes) { $this->require(in_array('modules.hooks', $manifest['capabilities'], TRUE)); }
    $skins = $manifest['skins'] ?? [];
    $this->require(is_array($skins) && array_is_list($skins) && count($skins) <= 8);
    if ($skins) { $this->require(in_array('presentation.appearance', $manifest['capabilities'], TRUE)); }
    $seen = [];
    foreach ($skins as $skin) {
      $this->require(is_array($skin) && $name($skin['id'] ?? NULL) && !isset($seen[$skin['id']])
        && is_string($skin['label'] ?? NULL) && mb_strlen($skin['label']) > 0 && mb_strlen($skin['label']) <= 256);
      $seen[$skin['id']] = TRUE;
      $this->require(is_array($skin['colors'] ?? []) && is_array($skin['icons'] ?? []) && count($skin['icons'] ?? []) <= 32);
      foreach ($skin['colors'] ?? [] as $color) { $this->require(is_string($color) && preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/D', $color)); }
      foreach (['cornerRadius' => [0, 32], 'spacing' => [2, 24], 'controlHeight' => [36, 64], 'headerHeight' => [48, 88]] as $key => [$min, $max]) {
        if (isset($skin[$key])) { $this->require((is_int($skin[$key]) || is_float($skin[$key])) && $skin[$key] >= $min && $skin[$key] <= $max); }
      }
      if (isset($skin['navigation'])) { $this->require(in_array($skin['navigation'], ['bottom', 'top'], TRUE)); }
      if (isset($skin['libraryView'])) { $this->require(in_array($skin['libraryView'], ['grid', 'list'], TRUE)); }
      foreach (array_merge([$skin['font'] ?? NULL, $skin['background'] ?? NULL], array_values($skin['icons'] ?? [])) as $file) {
        if ($file !== NULL) { $this->require(is_string($file) && in_array($file, $manifest['files'], TRUE) && preg_match('/\.(png|ttf)$/D', $file)); }
      }
      if (isset($skin['font'])) { $this->require(str_ends_with($skin['font'], '.ttf')); }
      if (isset($skin['background'])) { $this->require(str_ends_with($skin['background'], '.png')); }
      foreach ($skin['icons'] ?? [] as $file) { $this->require(str_ends_with($file, '.png')); }
    }
  }
  /** No filesystem writes, source execution, dependencies or ZIP64 support. */
  public function verify(string $bytes): array {
    $this->require(strlen($bytes) >= 22 && strlen($bytes) <= self::MAX_ARCHIVE);
    $n = fn(int $offset, int $size = 2): int => $this->integer($bytes, $offset, $size);
    $end = strlen($bytes) - 22;
    $this->require($n($end, 4) === 0x06054b50 && $n($end + 4) === 0
      && $n($end + 6) === 0 && $n($end + 20) === 0);
    $count = $n($end + 10);
    $start = $n($end + 16, 4);
    $this->require($count > 0 && $count <= 128 && $n($end + 8) === $count
      && $start + $n($end + 12, 4) === $end);
    $central = $start;
    $local = 0;
    $total = 0;
    $metadata_bytes = [];
    $files = [];
    $seen = [];
    for ($i = 0; $i < $count; $i++) {
      $this->require($central + 46 <= $end && $n($central, 4) === 0x02014b50);
      $method = $n($central + 10);
      $packed_size = $n($central + 20, 4);
      $size = $n($central + 24, 4);
      $name_size = $n($central + 28);
      $mode = ($n($central + 38, 4) >> 16) & 0xf000;
      $this->require($n($central + 8) === 0 && in_array($method, [0, 8], TRUE)
        && $n($central + 30) === 0 && $n($central + 32) === 0
        && $n($central + 34) === 0 && in_array($mode, [0, 0x8000], TRUE)
        && $name_size > 0 && $central + 46 + $name_size <= $end
        && $size <= self::MAX_FILE && $packed_size <= self::MAX_ARCHIVE);
      $name = substr($bytes, $central + 46, $name_size);
      $this->require($this->safe($name) && !isset($seen[strtolower($name)]));
      $seen[strtolower($name)] = TRUE;
      $this->require($n($central + 42, 4) === $local && $local + 30 <= $start
        && $n($local, 4) === 0x04034b50 && $n($local + 6) === 0
        && $n($local + 8) === $method && $n($local + 14, 4) === $n($central + 16, 4)
        && $n($local + 18, 4) === $packed_size && $n($local + 22, 4) === $size
        && $n($local + 26) === $name_size && $n($local + 28) === 0);
      $data_start = $local + 30 + $name_size;
      $this->require($data_start + $packed_size <= $start
        && substr($bytes, $local + 30, $name_size) === $name);
      $packed = substr($bytes, $data_start, $packed_size);
      $data = $method === 0 ? $packed : @gzinflate($packed, self::MAX_FILE);
      $this->require(is_string($data) && strlen($data) === $size
        && crc32($data) === $n($central + 16, 4));
      $total += $size;
      $this->require($total <= self::MAX_ARCHIVE);
      $files[$name] = ['size' => $size, 'sha256' => hash('sha256', $data)];
      if (in_array($name, ['manifest.json', 'signature.json'], TRUE)) {
        $this->require($size <= 262144);
        $metadata_bytes[$name] = $data;
      }
      $local = $data_start + $packed_size;
      $central += 46 + $name_size;
    }
    $this->require($central === $end && $local === $start
      && isset($files['manifest.json'], $files['signature.json']));
    try {
      $manifest = json_decode($metadata_bytes['manifest.json'], TRUE, 32, JSON_THROW_ON_ERROR);
      $signature = json_decode($metadata_bytes['signature.json'], TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \InvalidArgumentException('El manifiesto o la firma del paquete no es válido.');
    }
    $this->require(is_array($manifest) && is_array($signature)
      && ($manifest['formatVersion'] ?? NULL) === 1
      && in_array($manifest['sdkVersion'] ?? NULL, ['0.1.0', '0.2.0'], TRUE)
      && is_string($manifest['id'] ?? NULL) && strlen($manifest['id']) <= 128
      && preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9-]*)+$/D', $manifest['id'])
      && is_string($manifest['version'] ?? NULL) && strlen($manifest['version']) <= 32
      && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $manifest['version'])
      && is_string($manifest['name'] ?? NULL) && trim($manifest['name']) !== ''
      && mb_strlen($manifest['name']) <= 256
      && is_bool($manifest['bundledByDefault'] ?? NULL));
    foreach (['files', 'platforms', 'capabilities'] as $key) {
      $this->require(is_array($manifest[$key] ?? NULL) && array_is_list($manifest[$key]));
      foreach ($manifest[$key] as $value) { $this->require(is_string($value)); }
      $this->require(count($manifest[$key]) === count(array_unique($manifest[$key])));
    }
    $this->require(count($manifest['files']) > 0 && count($manifest['files']) <= 120
      && count($manifest['platforms']) > 0 && count($manifest['platforms']) <= 2
      && !array_diff($manifest['platforms'], ['android', 'jvm-desktop'])
      && !array_diff($manifest['capabilities'], self::CAPABILITIES)
      && is_string($manifest['entry'] ?? NULL)
      && str_ends_with($manifest['entry'], '.js')
      && in_array($manifest['entry'], $manifest['files'], TRUE));
    foreach ($manifest['files'] as $name) {
      $this->require($this->safe($name) && !in_array(strtolower($name), ['manifest.json', 'signature.json'], TRUE)
        && preg_match('/\.(js|json|svg|png|txt|ttf)$/D', $name));
    }
    $this->contracts($manifest);
    $ordered = array_merge(['manifest.json'], $manifest['files']);
    sort($ordered, SORT_STRING);
    $actual = array_keys($files);
    sort($actual, SORT_STRING);
    $expected = array_merge($ordered, ['signature.json']);
    sort($expected, SORT_STRING);
    $this->require($actual === $expected && ($signature['algorithm'] ?? '') === 'Ed25519'
      && is_array($signature['files'] ?? NULL) && array_is_list($signature['files'])
      && count($signature['files']) === count($ordered));
    $canonical = [];
    foreach ($ordered as $i => $name) {
      $entry = $signature['files'][$i];
      $this->require(is_array($entry));
      $keys = array_keys($entry);
      sort($keys);
      $hash = $files[$name]['sha256'];
      $this->require($keys === ['path', 'sha256', 'size']
        && ($entry['path'] ?? NULL) === $name
        && ($entry['size'] ?? NULL) === $files[$name]['size']
        && ($entry['sha256'] ?? NULL) === $hash);
      $canonical[] = ['path' => $name, 'size' => $files[$name]['size'], 'sha256' => $hash];
    }
    $this->require(is_string($signature['publicKey'] ?? NULL) && is_string($signature['signature'] ?? NULL));
    $key = base64_decode($signature['publicKey'], TRUE);
    $signed = base64_decode($signature['signature'], TRUE);
    $this->require(is_string($key) && strlen($key) === 44
      && substr($key, 0, 12) === hex2bin('302a300506032b6570032100')
      && is_string($signed) && strlen($signed) === 64
      && sodium_crypto_sign_verify_detached($signed, json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), substr($key, 12)));
    return [
      'id' => $manifest['id'], 'version' => $manifest['version'],
      'sdk' => $manifest['sdkVersion'], 'platforms' => $manifest['platforms'],
      'capabilities' => $manifest['capabilities'], 'author' => hash('sha256', $key),
      'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
    ];
  }
}
