<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 5) . '/vendor/autoload.php';
require_once __DIR__ . '/../src/Service/PackageVerifier.php';
require_once __DIR__ . '/../src/Service/DownloadClientInterface.php';
require_once __DIR__ . '/../src/Service/SecureDownloadClient.php';
require_once __DIR__ . '/ModuleFixtures.php';

use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\nelkano_modules\Service\SecureDownloadClient;
use NelkanoModuleTests\Packages;

$checks = 0;
$check = static function ($ok, $label) use (&$checks) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $checks++; echo "PASS: $label\n";
};
$reject = static function (callable $test, string $label) use ($check) {
  try { $test(); }
  catch (InvalidArgumentException) { $check(TRUE, $label); return; }
  throw new RuntimeException('FAIL: accepted ' . $label);
};
$key = sodium_crypto_sign_keypair();
$files = Packages::files('example.catalog', '1.2.0', $key);
$bytes = Packages::zip($files);
$verifier = new PackageVerifier();
$metadata = $verifier->verify($bytes);
$check($metadata['id'] === 'example.catalog' && $metadata['version'] === '1.2.0' && $metadata['sdk'] === '0.2.0', 'SDK metadata decoded');
$check($metadata['author'] === hash('sha256', hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($key)), 'Ed25519 publisher fingerprint');
$check($metadata['sha256'] === hash('sha256', $bytes), 'archive digest');
$altered = $files; $altered['src/main.js'] .= 'altered';
$reject(fn() => $verifier->verify(Packages::zip($altered)), 'altered content rejected');
$altered = $files; $signature = json_decode($altered['signature.json'], TRUE); $signature['signature'] = base64_encode(str_repeat('x', 64)); $altered['signature.json'] = json_encode($signature);
$reject(fn() => $verifier->verify(Packages::zip($altered)), 'forged signature rejected');
$altered = $files; $altered['../evil.js'] = 'evil';
$reject(fn() => $verifier->verify(Packages::zip($altered)), 'unsafe ZIP paths rejected');
$altered = $files; $altered['extra.js'] = 'extra';
$reject(fn() => $verifier->verify(Packages::zip($altered)), 'undeclared file rejected');
$reject(fn() => $verifier->verify('<html>error</html>'), 'HTML masquerading as module rejected');
$reject(fn() => $verifier->verify($bytes . 'trailing'), 'trailing ZIP data rejected');
$reject(fn() => $verifier->verify(substr($bytes, 0, -3)), 'truncated archive rejected');
$altered = $files; $altered['SRC/main.js'] = 'duplicate';
$reject(fn() => $verifier->verify(Packages::zip($altered)), 'case-insensitive duplicate rejected');
$altered = $files; $manifest = json_decode($altered['manifest.json'], TRUE); $manifest['version'] = 'latest'; $altered['manifest.json'] = json_encode($manifest);
$reject(fn() => $verifier->verify(Packages::zip($altered)), 'invalid manifest version rejected');
foreach (['http://github.com/u/r/a.nelkmod', 'https://user:password@github.com/a.nelkmod', 'https://github.com:444/a.nelkmod', 'https://github.com/a.zip', 'https://github.com/a.nelkmod#fragment', 'file:///tmp/a.nelkmod'] as $url) {
  $reject(fn() => SecureDownloadClient::validateUrl($url, TRUE), 'unsafe or non-package URL: ' . $url);
}
foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.169.254', '::1', '::ffff:127.0.0.1', '100.64.0.1', '224.0.0.1'] as $ip) {
  $check(!SecureDownloadClient::publicIp($ip), 'non-public address denied: ' . $ip);
}
$check(SecureDownloadClient::publicIp('1.1.1.1'), 'public address accepted');

class RedirectTestClient extends SecureDownloadClient {
  public array $calls = [];
  public array $responses = [];
  protected function resolve(string $host): string {
    if ($host === 'private.example') { throw new InvalidArgumentException('Private DNS address'); }
    return '1.1.1.1';
  }
  protected function requestPinned(string $url, string $host, string $ip, array $validators, int $seconds): array {
    $this->calls[] = [$url, $host, $ip, $seconds];
    return $this->responses[$url] ?? ['status' => 200, 'body' => 'fixture', 'location' => ''];
  }
}
$client = new RedirectTestClient();
$start = 'https://public.example/module.nelkmod';
$client->responses[$start] = ['status' => 302, 'body' => '', 'location' => 'https://cdn.example/download'];
$check($client->fetch($start)['status'] === 200 && count($client->calls) === 2, 'public HTTPS redirect followed');
$check($client->calls[0][2] === '1.1.1.1' && $client->calls[1][2] === '1.1.1.1', 'each request receives the prevalidated pinned address');
$client->responses[$start]['location'] = 'https://private.example/payload';
$reject(fn() => $client->fetch($start), 'redirect into private network denied');
$client->responses[$start]['location'] = 'http://public.example/payload';
$reject(fn() => $client->fetch($start), 'HTTPS downgrade redirect denied');
$client->responses[$start]['location'] = $start;
$reject(fn() => $client->fetch($start), 'redirect loop bounded and rejected');
$reject(fn() => $client->fetch($start, ['etag' => "x\r\nInjected: value"]), 'validator header injection denied');
// Optional artifact for cross-verification against the SDK's own verifier.
if (getenv('NELKANO_PACKAGE_FIXTURE_PATH')) { file_put_contents(getenv('NELKANO_PACKAGE_FIXTURE_PATH'), $bytes); }
echo "SUCCESS: $checks package/network checks.\n";