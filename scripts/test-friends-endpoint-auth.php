<?php

declare(strict_types=1);

use Drupal\Core\Authentication\AuthenticationCollector;
use Drupal\Core\Authentication\AuthenticationManager;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\nelkano_home\Authentication\NativeAccountEndpointAuthentication;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

// Composer autoloader and the provider must be loaded; no DB/HTTP operations.
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
  $checks++;
};
$services = Yaml::parseFile(__DIR__ . '/../public_html/modules/custom/nelkano_home/nelkano_home.services.yml')['services'];
$definition = $services['nelkano_home.native_account_endpoint_authentication'];
$check($definition['class'] === NativeAccountEndpointAuthentication::class, 'native account authentication is registered');
$tag = $definition['tags'][0];
$check($tag['name'] === 'authentication_provider', 'service is an authentication provider');
$class = $definition['class'];
$provider = new $class();
$oauth = new class implements AuthenticationProviderInterface {
  public int $calls = 0;
  public function applies(Request $request): bool { return $request->headers->has('Authorization'); }
  public function authenticate(Request $request) { $this->calls++; throw new RuntimeException('JWT parser reached'); }
};
$collector = new AuthenticationCollector();
$collector->addProvider($oauth, 'oauth', 35, TRUE);
$collector->addProvider($provider, $tag['provider_id'], $tag['priority'], $tag['global']);
$manager = new AuthenticationManager($collector);
$nativePaths = [
  '/friends/request', '/friends/accept', '/friends/reject', '/friends/remove', '/friends/presence', '/profile',
  '/game/session/connect', '/game/session/end', '/game/signaling/event',
];
foreach ($nativePaths as $path) {
  foreach (['nkat_' . str_repeat('a', 64), '1.' . str_repeat('b', 64), 'invalid', 'jwt.with.dots', ''] as $token) {
    $request = Request::create('/api/nelkano' . $path, 'POST');
    if ($token !== '') { $request->headers->set('Authorization', 'Bearer ' . $token); }
    $original = $request->headers->get('Authorization');
    $check($provider->applies($request), 'exact route matches native POST authentication');
    $check($manager->applies($request), 'native mutation selects its controller');
    $check($manager->authenticate($request) === NULL, 'no Drupal identity granted');
    $check($request->headers->get('Authorization') === $original, 'credential reaches the controller intact');
  }
}
$check($oauth->calls === 0, 'OAuth never parses native account or multiplayer mutation credentials');
$streamPaths = [
  '/api/nelkano/stream/signaling/session',
  '/api/nelkano/stream/signaling/event',
  '/api/nelkano/stream/signaling/session/end',
];
foreach ($streamPaths as $path) {
  foreach (['nkat_' . str_repeat('a', 64), '1.' . str_repeat('b', 64)] as $token) {
    $request = Request::create($path, 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    $check($provider->applies($request), 'native streaming token selects its controller');
    $check($manager->authenticate($request) === NULL, 'native streaming route grants no Drupal identity');
    $check($request->headers->get('Authorization') === 'Bearer ' . $token, 'streaming controller receives the original token');
    $check(!$provider->applies(Request::create($path, 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token])), 'streaming GET is unchanged');
  }
  $browser = Request::create($path, 'POST', [], ['SESSexample' => 'browser-session']);
  $check(!$provider->applies($browser), 'browser sessions retain existing authentication');
  $jwt = Request::create($path, 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer jwt.with.dots']);
  $check(!$provider->applies($jwt), 'non-native bearer tokens retain existing authentication');
}
$check($oauth->calls === 0, 'OAuth never parses native streaming signaling credentials');
$otherRoutes = [
  ['GET', '/api/nelkano/friends/request'],
  ['GET', '/api/nelkano/game/session/connect'],
  ['GET', '/api/nelkano/game/signaling/events'],
  ['POST', '/api/nelkano/game/signaling/events'],
  ['POST', '/api/nelkano/game/session/connect/other'],
  ['POST', '/api/nelkano/friends/request/other'],
  ['POST', '/api/nelkano/friends/request-other'],
  ['POST', '/api/nelkano/error-reports'],
  ['POST', '/api/nelkano/stream/signaling/session/other'],
  ['POST', '/api/nelkano/stream/invitations'],
  ['POST', '/oauth/token'],
  ['POST', '/jsonapi/node/community_post'],
  ['POST', '/api/nelkano/community/groups'],
];
foreach ($otherRoutes as [$method, $path]) {
  $request = Request::create($path, $method, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer oauth-token']);
  $check(!$provider->applies($request), 'unrelated route unchanged');
  try { $manager->authenticate($request); throw new LogicException('OAuth should handle this route'); }
  catch (RuntimeException $error) { $check($error->getMessage() === 'JWT parser reached', 'unrelated route reaches OAuth'); }
}
echo json_encode(['checks' => $checks, 'result' => 'PASS', 'drupal_authentication_manager' => 'real', 'database_writes' => 0], JSON_THROW_ON_ERROR) . PHP_EOL;
