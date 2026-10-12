<?php

/** Local-only presentation regression checks; fixtures roll back automatically. */
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\nelkano_home\Controller\HomeController;
use Drupal\node\Entity\Node;

$database = \Drupal::database();
if (!str_starts_with($database->getConnectionOptions()['database'], 'nelkano_book_qa_')) {
  throw new RuntimeException('Run on the disposable book QA database only.');
}
$transaction = $database->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new AnonymousUserSession());
$count = 0;
$check = function (bool $ok, string $message) use (&$count): void {
  if (!$ok) { throw new RuntimeException($message); }
  echo 'PASS ' . ++$count . ': ' . $message . PHP_EOL;
};
try {
  $controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(HomeController::class);
  $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => 'de520020-2026-4000-8000-000000000001']);
  $root = reset($nodes);
  $check((bool) $root, 'SDK book fixture exists');
  $request = \Symfony\Component\HttpFoundation\Request::create('/node/' . $root->id());
  $request->attributes->set('_route', 'entity.node.canonical');
  $request->attributes->set('node', $root);
  $original = static fn() => NULL;
  $event = new \Symfony\Component\HttpKernel\Event\ControllerEvent(\Drupal::service('http_kernel'), $original, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
  $subscriber = \Drupal::service('nelkano_home.sdk_book_controller');
  $subscriber->onController($event);
  $check(is_array($event->getController()) && $event->getController()[1] === 'sdkBookPage', 'Canonical SDK route selects shared presentation');
  $request->attributes->set('_route', 'entity.node.edit_form');
  $event = new \Symfony\Component\HttpKernel\Event\ControllerEvent(\Drupal::service('http_kernel'), $original, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
  $subscriber->onController($event);
  $check($event->getController() === $original, 'Editorial routes retain their Drupal controller');
  $html = $controller->sdkBookPage($root)->getContent();
  $check(str_contains($html, 'class="nk-app-nav"') && str_contains($html, 'r-footer-shell'), 'Shared site header and footer');
  $check(str_contains($html, 'r-guide-layout r-sdk-layout') && str_contains($html, 'r-guide-hero'), 'Guide layout and hero reused');
  $check(str_contains($html, 'aria-current="page"'), 'Current chapter identified accessibly');
  $check(str_contains($html, 'r-sdk-depth-2'), 'Nested chapters retain hierarchy');
  $check(str_contains($html, 'rel="next"'), 'Next chapter navigation');
  $check(!str_contains($html, 'href="/en/sdk-de-nelkano"'), 'No link to a nonexistent English translation');
  $fixture = Node::create(['type' => 'sdk_page', 'title' => 'PRIVATE SDK SENTINEL', 'langcode' => 'es', 'status' => 0,
    'body' => ['value' => '<p>PRIVATE BODY SENTINEL</p>', 'format' => 'basic_html']]);
  $fixture->save();
  $book = ['nid' => $fixture->id(), 'bid' => $root->id(), 'pid' => $root->id(), 'weight' => 99];
  $fixture->setBook($book);
  \Drupal::service('book.manager')->updateOutline($fixture);
  $html = $controller->sdkBookPage($root)->getContent();
  $check(!str_contains($html, 'PRIVATE SDK SENTINEL') && !str_contains($html, 'PRIVATE BODY SENTINEL'), 'Anonymous index excludes unpublished chapters');
  $root->set('body', ['value' => '<p>SAFE TEXT</p><script>alert("unsafe")</script>', 'format' => 'basic_html']);
  $html = $controller->sdkBookPage($root)->getContent();
  $check(str_contains($html, 'SAFE TEXT') && !str_contains($html, '<script>alert("unsafe")</script>'), 'Body uses Drupal text filtering');
  $root->setUnpublished();
  $root->save();
  \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
  $denied = FALSE;
  try { $controller->sdkBookPage($root); }
  catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) { $denied = TRUE; }
  $check($denied, 'Inaccessible root rejected');
  $chrome = new ReflectionMethod($controller, 'chromeContext');
  $modulePath = \Drupal::service('extension.list.module')->getPath('nelkano_home');
  $hasSdk = static fn(array $context): bool => in_array('SDK', array_column($context['nav_links'], 'label'), TRUE);
  $check(!$hasSdk($chrome->invoke($controller, $modulePath, 'es')), 'Unpublished SDK absent from anonymous menu');
  $switcher->switchTo(\Drupal\user\Entity\User::load(1));
  try {
    $check(!$hasSdk($chrome->invoke($controller, $modulePath, 'es')), 'Unpublished SDK absent from administrator menu');
    $check($controller->sdkBookPage($root)->getStatusCode() === 200, 'Administrator can still preview unpublished SDK');
    $root->setPublished();
    $root->save();
    \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
    $check($hasSdk($chrome->invoke($controller, $modulePath, 'es')), 'Republished SDK returns to administrator menu');
  }
  finally { $switcher->switchBack(); }
  $check($hasSdk($chrome->invoke($controller, $modulePath, 'es')), 'Republished SDK returns to anonymous menu');
  $check($controller->sdkBookPage($root)->getStatusCode() === 200, 'Republished SDK is publicly readable');
  $root->addTranslation('en', ['title' => 'SDK English draft', 'status' => 0]);
  $root->save();
  \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
  $check(!$hasSdk($chrome->invoke($controller, $modulePath, 'en')), 'English draft does not appear in English menu');
  $check($hasSdk($chrome->invoke($controller, $modulePath, 'es')), 'English draft preserves published Spanish menu');
  $root->getTranslation('en')->setPublished();
  $root->save();
  \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
  $check($hasSdk($chrome->invoke($controller, $modulePath, 'en')), 'Publishing English translation restores English menu');
}
finally {
  $transaction->rollBack();
  $switcher->switchBack();
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
}
echo "Presentation checks complete: $count; fixtures rolled back." . PHP_EOL;
