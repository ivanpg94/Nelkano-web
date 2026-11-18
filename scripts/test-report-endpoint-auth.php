<?php

declare(strict_types=1);

use Drupal\Core\Authentication\AuthenticationCollector;
use Drupal\Core\Authentication\AuthenticationManager;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\nelkano_home\Authentication\ReportEndpointAuthentication;
use Symfony\Component\HttpFoundation\Request;

// Run with Composer's autoloader and ReportEndpointAuthentication loaded.
// No Drupal bootstrap, database, credentials or HTTP requests are needed.
$checks = 0;
$check = static function (bool $value, string $name) use (&$checks): void {
  if (!$value) { throw new RuntimeException('FAIL: ' . $name); }
  $checks++;
};
$provider = new ReportEndpointAuthentication();
$oauth = new class implements AuthenticationProviderInterface {
  public int $calls = 0;
  public function applies(Request $request): bool { return $request->headers->has('Authorization'); }
  public function authenticate(Request $request) { $this->calls++; throw new RuntimeException('The JWT string must have two dots'); }
};
$collector = new AuthenticationCollector();
$collector->addProvider($oauth, 'oauth', 35, TRUE);
$collector->addProvider($provider, 'nelkano_report_endpoint', 1000, TRUE);
$manager = new AuthenticationManager($collector);
foreach (['nkat_' . str_repeat('a', 64), '1.' . str_repeat('b', 64), 'invalid', 'jwt.with.dots', ''] as $token) {
  $request = Request::create('/api/nelkano/error-reports', 'POST');
  if ($token !== '') { $request->headers->set('Authorization', 'Bearer ' . $token); }
  $original = $request->headers->get('Authorization');
  $check($manager->applies($request), 'upload uses controller authentication');
  $check($manager->authenticate($request) === NULL, 'no Drupal identity granted');
  $check($request->headers->get('Authorization') === $original, 'controller receives the original credential');
}
$check($oauth->calls === 0, 'OAuth never parses report credentials');
foreach ([['GET', '/api/nelkano/error-reports'], ['POST', '/api/nelkano/error-reports-other'], ['POST', '/api/nelkano/error-reports/123'], ['GET', '/api/nelkano/v1/error-reports'], ['POST', '/oauth/token'], ['GET', '/jsonapi/node/article'], ['GET', '/admin/nelkano/error-reports']] as [$method, $path]) {
  $request = Request::create($path, $method, [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer oauth-token']);
  $check(!$provider->applies($request), 'unrelated method or route unchanged');
  try { $manager->authenticate($request); throw new LogicException('OAuth should handle this request'); }
  catch (RuntimeException $error) { $check($error->getMessage() === 'The JWT string must have two dots', 'unrelated credential still reaches OAuth'); }
}
echo json_encode(['checks' => $checks, 'result' => 'PASS', 'drupal_authentication_manager' => 'real', 'oauth_parser' => 'simulated', 'database_writes' => 0], JSON_THROW_ON_ERROR) . PHP_EOL;
