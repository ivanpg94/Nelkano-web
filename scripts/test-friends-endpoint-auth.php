<?php

declare(strict_types=1);

use Drupal\Core\Authentication\AuthenticationCollector;
use Drupal\Core\Authentication\AuthenticationManager;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\nelkano_home\Authentication\FriendsEndpointAuthentication;
use Symfony\Component\HttpFoundation\Request;

// Composer autoloader and the provider must be loaded; no DB/HTTP operations.
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
  $checks++;
};
$provider = new FriendsEndpointAuthentication();
$oauth = new class implements AuthenticationProviderInterface {
  public int $calls = 0;
  public function applies(Request $request): bool { return $request->headers->has('Authorization'); }
  public function authenticate(Request $request) { $this->calls++; throw new RuntimeException('JWT parser reached'); }
};
$collector = new AuthenticationCollector();
$collector->addProvider($oauth, 'oauth', 35, TRUE);
$collector->addProvider($provider, 'nelkano_friends_endpoint', 1000, TRUE);
$manager = new AuthenticationManager($collector);
foreach (['/friends/request', '/friends/accept', '/friends/reject', '/friends/remove', '/friends/presence', '/profile'] as $path) {
  foreach (['nkat_' . str_repeat('a', 64), '1.' . str_repeat('b', 64), 'invalid', 'jwt.with.dots', ''] as $token) {
    $request = Request::create('/api/nelkano' . $path, 'POST');
    if ($token !== '') { $request->headers->set('Authorization', 'Bearer ' . $token); }
    $original = $request->headers->get('Authorization');
    $check($manager->applies($request), 'native mutation selects its controller');
    $check($manager->authenticate($request) === NULL, 'no Drupal identity granted');
    $check($request->headers->get('Authorization') === $original, 'credential reaches the controller intact');
  }
}
$check($oauth->calls === 0, 'OAuth never parses native mutation credentials');
foreach ([['GET', '/api/nelkano/friends/request'], ['POST', '/api/nelkano/friends/request/other'], ['POST', '/api/nelkano/friends/request-other'], ['POST', '/api/nelkano/error-reports'], ['POST', '/oauth/token'], ['POST', '/jsonapi/node/community_post'], ['POST', '/api/nelkano/community/groups']] as [$method, $path]) {
  $request = Request::create($path, $method, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer oauth-token']);
  $check(!$provider->applies($request), 'unrelated route unchanged');
  try { $manager->authenticate($request); throw new LogicException('OAuth should handle this route'); }
  catch (RuntimeException $error) { $check($error->getMessage() === 'JWT parser reached', 'unrelated route reaches OAuth'); }
}
echo json_encode(['checks' => $checks, 'result' => 'PASS', 'drupal_authentication_manager' => 'real', 'database_writes' => 0], JSON_THROW_ON_ERROR) . PHP_EOL;
