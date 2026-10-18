<?php

/** Focused SDK reference checks; all temporary content changes are rolled back. */
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\nelkano_home\Controller\SdkReferenceController;
use Drupal\nelkano_home\Service\SdkReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) { throw new RuntimeException($message); }
  echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL;
};
$reference = \Drupal::service('nelkano_home.sdk_reference');
$controller = new SdkReferenceController($reference);
$storage = \Drupal::entityTypeManager()->getStorage('node');
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new AnonymousUserSession());
try {
  $cache = new CacheableMetadata();
  $baseline = $reference->snapshot($cache);
  $assert($baseline['schemaVersion'] === 1 && $baseline['sdkVersion'] === SdkReference::SDK_VERSION, 'Versioned reference contract');
  $assert($baseline['book'] === SdkReference::BOOK_UUID && count($baseline['chapters']) > 0, 'SDK root and its public chapters are present');
  $assert($baseline['chapters'][0]['id'] === SdkReference::BOOK_UUID, 'Book order starts with its root');
  $payload = $baseline;
  unset($payload['revision']);
  $assert(hash('sha256', SdkReference::encode($payload)) === $baseline['revision'], 'Revision hashes only deterministic contract and chapter content');
  $assert($reference->snapshot() === $baseline, 'Repeated snapshots are byte-stable');
  $assert($cache->getCacheMaxAge() === 0 && in_array('node_list:sdk_page', $cache->getCacheTags(), TRUE), 'Content and outline changes are revalidated without stale page caching');
  foreach ($baseline['chapters'] as $chapter) {
    $assert(array_keys($chapter) === ['id', 'language', 'title', 'path', 'content'], 'Chapter exports only public documentation fields: ' . $chapter['title']);
    $assert(str_starts_with($chapter['path'], '/') && !str_starts_with($chapter['path'], '//') && !str_contains($chapter['content'], 'book://'), 'Chapter paths and portable links are relative: ' . $chapter['title']);
  }
  $roots = $storage->loadByProperties(['uuid' => SdkReference::BOOK_UUID]);
  $root = reset($roots);
  $rows = \Drupal::database()->select('book', 'b')->fields('b', ['nid'])->condition('bid', $root->id())->execute()->fetchCol();
  $private = [];
  foreach ($storage->loadMultiple($rows) as $node) {
    if (!$node->isPublished() || !$node->access('view', new AnonymousUserSession())) {
      $private[] = $node->uuid();
    }
  }
  foreach ($private as $uuid) {
    $assert(!in_array($uuid, array_column($baseline['chapters'], 'id'), TRUE), 'Unpublished or restricted chapter is absent: ' . $uuid);
  }
  $admin = \Drupal::entityTypeManager()->getStorage('user')->load(1);
  if ($admin) {
    $switcher->switchTo($admin);
    try {
      $assert($reference->snapshot() === $baseline, 'Administrator requests get exactly the anonymous documentation');
    }
    finally { $switcher->switchBack(); }
  }
  $response = $controller->reference(Request::create('/api/nelkano/v1/sdk/reference'));
  $assert($response->getStatusCode() === 200 && $response->getEtag() === '"' . $baseline['revision'] . '"', 'HTTP response has the public revision ETag');
  $conditional = Request::create('/api/nelkano/v1/sdk/reference', 'GET', [], [], [], ['HTTP_IF_NONE_MATCH' => $response->getEtag()]);
  $unchanged = $controller->reference($conditional);
  $assert($unchanged->getStatusCode() === 304 && $unchanged->getContent() === '', 'Matching If-None-Match returns 304 without a response body');
  $assert($unchanged->headers->hasCacheControlDirective('must-revalidate'), 'Conditional responses preserve revalidation');
  $transaction = \Drupal::database()->startTransaction();
  try {
    $roots = $storage->loadByProperties(['uuid' => SdkReference::BOOK_UUID]);
    $root = reset($roots);
    $target = $baseline['chapters'][1];
    $root->body->value .= '<p>SDK reference temporary revision fixture. <a href="book://' . $target['id'] . '/' . $target['language'] . '#example">Public chapter</a></p><pre><code>&lt;tag&gt; &amp; unmodified code</code></pre>';
    $root->save();
    $storage->resetCache();
    $changed = $reference->snapshot();
    $assert($changed['revision'] !== $baseline['revision'], 'Editing a public paragraph changes the revision');
    $assert(str_contains($changed['chapters'][0]['content'], 'href="' . $target['path'] . '#example"'), 'Portable chapter links resolve to their current public relative URL');
    $assert(str_contains($changed['chapters'][0]['content'], '<pre><code>&lt;tag&gt; &amp; unmodified code</code></pre>'), 'Complete HTML and code examples retain their original bytes');
    $updated = $controller->reference($conditional);
    $assert($updated->getStatusCode() === 200 && str_contains($updated->getContent(), 'temporary revision fixture'), 'Old ETag returns the current complete text after an edit');
    $targets = $storage->loadByProperties(['uuid' => $target['id']]);
    $targetNode = reset($targets);
    $targetNode->setUnpublished()->save();
    $storage->resetCache();
    $withoutChapter = $reference->snapshot();
    $assert(!in_array($target['id'], array_column($withoutChapter['chapters'], 'id'), TRUE) && $withoutChapter['revision'] !== $changed['revision'], 'Unpublishing a chapter removes it and changes the revision');
    $assert(!str_contains($withoutChapter['chapters'][0]['content'], 'book://') && !str_contains($withoutChapter['chapters'][0]['content'], 'href="' . $target['path'] . '#example"'), 'Portable links cannot navigate to a chapter that became private');
    $roots = $storage->loadByProperties(['uuid' => SdkReference::BOOK_UUID]);
    $root = reset($roots);
    $root->setUnpublished()->save();
    $storage->resetCache();
    try {
      $reference->snapshot();
      $assert(FALSE, 'Unpublished root must return 404');
    }
    catch (NotFoundHttpException) { $assert(TRUE, 'Unpublished root returns 404 instead of exposing its children'); }
  }
  finally {
    $transaction->rollBack();
    unset($transaction);
    $storage->resetCache();
  }
  $assert($reference->snapshot() === $baseline, 'All temporary content and publication changes were rolled back');
  $path = '/tmp/nelkano-sdk-reference-' . bin2hex(random_bytes(6)) . '.json';
  try {
    $exported = $reference->write($path);
    $assert($exported === $baseline && json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR) === $baseline, 'Offline export exactly matches the HTTP contract');
  }
  finally { if (is_file($path)) { unlink($path); } }
  try {
    $reference->write(DRUPAL_ROOT . '/sdk-reference-should-not-exist.json');
    $assert(FALSE, 'Docroot exports must be forbidden');
  }
  catch (InvalidArgumentException) { $assert(TRUE, 'Exporter refuses web document root destinations'); }
}
finally { $switcher->switchBack(); }
echo "SDK reference: $checks checks passed." . PHP_EOL;
