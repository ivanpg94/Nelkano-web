<?php

declare(strict_types=1);

use Drupal\Core\Authentication\AuthenticationCollector;
use Drupal\Core\Authentication\AuthenticationManager;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\nelkano_ai\Authentication\AiEndpointAuthentication;
use Symfony\Component\HttpFoundation\Request;

if (getenv('NELKANO_LOCAL_AI_TEST') !== '1' || !str_contains((string) \Drupal::request()->getHost(), 'localhost')) {
  throw new RuntimeException('Local AI regression only.');
}

$provider = new AiEndpointAuthentication();
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
  $checks++;
};
$oauth = new class implements AuthenticationProviderInterface {
  public int $calls = 0;
  public function applies(Request $request): bool { return $request->headers->has('Authorization'); }
  public function authenticate(Request $request) { $this->calls++; throw new RuntimeException('The JWT string must have two dots'); }
};
$collector = new AuthenticationCollector();
$collector->addProvider($oauth, 'oauth', 35, TRUE);
$collector->addProvider($provider, 'nelkano_ai_endpoint', 1000, TRUE);
$manager = new AuthenticationManager($collector);
foreach (['/status', '/questions', '/questions/00000000-0000-0000-0000-000000000000/cancel', '/questions/00000000-0000-0000-0000-000000000000/tool-results', '/worker/heartbeat', '/worker/claim', '/worker/questions/00000000-0000-0000-0000-000000000000/result'] as $path) {
  foreach (['nkaw_' . str_repeat('a', 64), 'nkat_' . str_repeat('b', 64), 'invalid', 'jwt.with.dots', ''] as $token) {
    $request = Request::create('http://localhost/api/nelkano/v1/ai' . $path);
    if ($token !== '') { $request->headers->set('Authorization', 'Bearer ' . $token); }
    $original = $request->headers->get('Authorization');
    $check($manager->applies($request), 'owned endpoint selects controller authentication');
    $check($manager->authenticate($request) === NULL, 'never authenticates a Drupal user');
    $check($request->headers->get('Authorization') === $original, 'credential remains available to AiAccess');
  }
}
$check($oauth->calls === 0, 'OAuth never parses AI credentials');
foreach (['/admin/config/services/nelkan-ai', '/oauth/token', '/api/nelkano/v1/auth/me', '/api/nelkano/v1/ai-other/status', '/api/nelkano/v1/ai'] as $path) {
  $request = Request::create('http://localhost' . $path, 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer oauth-token']);
  $check(!$provider->applies($request), 'unrelated routes unchanged');
  try { $manager->authenticate($request); throw new LogicException('OAuth should receive this request'); }
  catch (RuntimeException $error) { $check($error->getMessage() === 'The JWT string must have two dots', 'unrelated authentication reaches OAuth'); }
}
$registered = \Drupal::service('authentication_collector')->getSortedProviders();
$check(reset($registered) instanceof AiEndpointAuthentication, 'compiled container selects AI provider before OAuth');
echo json_encode(['checks' => $checks, 'result' => 'PASS', 'oauth_parser' => 'simulated', 'drupal_authentication_manager' => 'real'], JSON_THROW_ON_ERROR) . PHP_EOL;
