<?php
/**
 * Integration tests against a disposable Drupal multisite, never production.
 * drush --uri=http://book-qa.localhost php:script scripts/test-book-import-export.php
 */
use Drupal\node\Entity\Node;
use Drupal\Component\Uuid\Php;
use Symfony\Component\Yaml\Yaml;
$db = \Drupal::database();
if (!str_starts_with($db->getConnectionOptions()['database'], 'nelkano_book_qa_')) {
  throw new RuntimeException('Refusing to run: database name must start nelkano_book_qa_.');
}
$qaTransaction = $db->startTransaction();
$qaExports = [tempnam(sys_get_temp_dir(), 'book-qa-a-'), tempnam(sys_get_temp_dir(), 'book-qa-b-')];
try {
$transfer = \Drupal::service('book_import_export.transfer');
$source = $transfer->read(getenv('BOOK_QA_SOURCE') ?: dirname(DRUPAL_ROOT) . '/content/books/nelkano-sdk.yml');
$checks = 0;
$assert = function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
  $checks++; echo "PASS $message\n";
};
$node = function (string $uuid): ?\Drupal\node\NodeInterface {
  $found = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]);
  return $found ? reset($found) : NULL;
};
$snapshot = function () use ($db): string {
  $data = [];
  foreach (['node', 'node_field_data', 'node_revision', 'node_field_revision', 'node__body', 'node_revision__body', 'book', 'path_alias', 'path_alias_revision', 'users_field_data'] as $table) {
    $rows = $db->select($table, 't')->fields('t')->execute()->fetchAll();
    $rows = array_map(fn($row) => json_encode($row), $rows); sort($rows); $data[$table] = $rows;
  }
  return hash('sha256', serialize($data));
};
$mustFail = function (callable $operation, string $message) use ($assert, $snapshot): void {
  $before = $snapshot(); $failed = FALSE;
  try { $operation(); } catch (\Throwable $e) { $failed = TRUE; }
  $assert($failed && $before === $snapshot(), $message . ' / no content writes');
};
// Refresh the fixture inside the rollback transaction when versioned prose changes.
$transfer->import($source);
$assert($transfer->export($source['book']) == $source, 'cross-installation import/export preserves portable content with different NIDs');
$initial = $snapshot();
$plan = $transfer->import($source, TRUE);
$assert(!array_diff($plan, ['unchanged']) && $initial === $snapshot(), 'dry-run repeated import has no content writes');
$transfer->import($source);
$assert($initial === $snapshot(), 'repeated real import does not duplicate nodes or revisions');
$root = $node($source['book']);
$assert((int) $root->id() < 100, 'QA numeric IDs differ from working-site IDs');
// Unrelated content is a sentinel; importer must never export/update it.
$sentinel = Node::create(['type' => 'sdk_page', 'title' => 'Unrelated private sentinel', 'status' => 0,
  'body' => ['value' => 'PRIVATE_NON_BOOK_SENTINEL', 'format' => 'basic_html'], 'uid' => 1]);
$sentinel->save();
$sentinelUuid = $sentinel->uuid();
$sentinelRevision = $sentinel->getRevisionId();
$before = $snapshot();
$changed = $source;
$changed['pages'][0]['translations']['es']['title'] .= ' (revision test)';
$revision = $root->getRevisionId();
$transfer->import($changed, TRUE);
$assert($before === $snapshot(), 'dry-run changed title preserves nodes, aliases, revisions, users and outline');
$transfer->import($changed);
$root = $node($source['book']);
$assert($root->getRevisionId() > $revision && str_ends_with($root->label(), '(revision test)'), 'existing UUID update creates a revision');
$assert($node($sentinelUuid)->getRevisionId() === $sentinelRevision, 'unrelated page untouched');
$export = $transfer->export($source['book']);
$assert(!str_contains(Yaml::dump($export, 12), 'PRIVATE_NON_BOOK_SENTINEL'), 'unrelated private content excluded from export');
$assert(!array_intersect(['uid', 'revision_uid', 'nid', 'vid', 'created', 'password', 'roles'], array_keys($export['pages'][0])), 'editorial allowlist excludes account, revision and local ID metadata');
// Restore source through revisions and verify export shape.
$transfer->import($source);
$assert($transfer->export($source['book']) == $source, 'restore exact book content through import');
// Add actual translations and cross-language links, fragments, queries and weights.
$translated = $source;
$translated['pages'][0]['translations']['en'] = [
  'title' => 'Nelkano SDK QA', 'status' => TRUE,
  'body' => ['value' => '<p><a href="book://' . $source['pages'][1]['uuid'] . '/es?q=one&amp;x=two#details">Spanish child</a></p>', 'summary' => '', 'format' => 'basic_html'],
  'alias' => '/qa-sdk-en'];
$translated['pages'][1]['translations']['en'] = [
  'title' => 'Runtime QA', 'status' => FALSE,
  'body' => ['value' => '<p>English child</p>', 'summary' => '', 'format' => 'basic_html'], 'alias' => '/qa-runtime-en'];
$translated['pages'][1]['translations']['es']['body']['value'] .= '<p><a href="book://' . $source['book'] . '/en#english">English root</a></p>';
$translated['pages'][1]['weight'] = -20;
$transfer->import($translated);
$export = $transfer->export($source['book']);
$assert($export == $translated, 'languages, publication, aliases, ordering, queries, fragments and cross-language links round-trip');
$assert(str_contains($node($source['book'])->getTranslation('en')->body->value, '?q=one&amp;x=two#details'), 'query and fragment resolved in stored HTML');
$assert(!str_contains($node($source['book'])->getTranslation('en')->body->value, 'book://'), 'portable links resolved to destination URLs');
$assert(str_contains($node($source['book'])->getTranslation('en')->body->value, '/es/sdk-de-nelkano/funcionamiento'), 'destination URL includes target language prefix');
$again = $snapshot(); $transfer->import($translated);
$assert($again === $snapshot(), 'translated reimport is idempotent');
// Alias changes/removal must not silently leave stale destination links.
$aliasChanged = $translated;
$aliasChanged['pages'][1]['translations']['es']['alias'] = '/qa-runtime-renamed';
$transfer->import($aliasChanged);
$assert($transfer->export($source['book']) == $aliasChanged, 'alias rename preserves UUID links');
$aliasRemoved = $aliasChanged;
$aliasRemoved['pages'][1]['translations']['es']['alias'] = NULL;
$transfer->import($aliasRemoved);
$assert($transfer->export($source['book']) == $aliasRemoved, 'alias removal resolves to language-prefixed destination node URL');
$transfer->import($translated);
// Omitted translations retained; no delete mode.
$partialTranslations = $translated;
unset($partialTranslations['pages'][0]['translations']['en']);
$transfer->import($partialTranslations);
$assert($node($source['book'])->hasTranslation('en'), 'omitted translation retained');
// Add an extra leaf and then omit it from a subsequent import.
$extra = $translated['pages'][1];
$extra['uuid'] = (new Php())->generate(); $extra['parent'] = $source['book']; $extra['weight'] = 1000;
$extra['translations'] = ['es' => ['title' => 'Retained extra page', 'status' => FALSE,
  'body' => ['value' => '<p>Additional page</p>', 'summary' => '', 'format' => 'basic_html'], 'alias' => '/qa-retained-page']];
$extended = $translated; $extended['pages'][] = $extra;
$transfer->import($extended); $transfer->import($translated);
$assert($node($extra['uuid']) !== NULL && count($transfer->export($source['book'])['pages']) === count($extended['pages']), 'omitted page retained');
// Reparent existing page beneath another page, then restore.
$moved = $translated;
$moved['pages'][1]['parent'] = $source['pages'][2]['uuid'];
$transfer->import($moved);
$outline = \Drupal::service('book.manager')->loadBookLink((int) $node($source['pages'][1]['uuid'])->id(), FALSE);
$assert((int) $outline['pid'] === (int) $node($source['pages'][2]['uuid'])->id(), 'reparent existing node without duplication');
$transfer->import($translated);
$duplicate = $translated; $duplicate['pages'][] = $duplicate['pages'][0];
$mustFail(fn() => $transfer->import($duplicate), 'reject duplicate UUID');
$ambiguous = $translated;
$ambiguous['pages'][2]['weight'] = $ambiguous['pages'][1]['weight'];
$ambiguous['pages'][2]['translations']['es']['title'] = $ambiguous['pages'][1]['translations']['es']['title'];
$mustFail(fn() => $transfer->import($ambiguous), 'reject ordering that depends on source numeric IDs');
$cycle = $translated; $cycle['pages'][1]['parent'] = $cycle['pages'][1]['uuid'];
$mustFail(fn() => $transfer->import($cycle, TRUE), 'reject cycle in simulation');
$missing = $translated; $missing['pages'][1]['parent'] = (new Php())->generate();
$mustFail(fn() => $transfer->import($missing), 'reject missing parent');
$unknown = $translated; $unknown['pages'][0]['translations']['zz-unknown'] = $unknown['pages'][0]['translations']['es'];
$mustFail(fn() => $transfer->import($unknown), 'reject unknown language');
$format = $translated; $format['pages'][0]['translations']['es']['body']['format'] = 'nonexistent';
$mustFail(fn() => $transfer->import($format), 'reject missing format');
$broken = $translated; $broken['pages'][0]['translations']['es']['body']['value'] = '<a href="book://' . (new Php())->generate() . '/es">Broken</a>';
$mustFail(fn() => $transfer->import($broken), 'reject unresolved internal link');
$numeric = $translated; $numeric['pages'][0]['translations']['es']['body']['value'] = '<a href=/node/999>Bad local ID</a>';
$mustFail(fn() => $transfer->import($numeric), 'reject numeric links including unquoted href');
$media = $translated; $media['pages'][0]['translations']['es']['body']['value'] = '<img src="/sites/default/files/private.png">';
$mustFail(fn() => $transfer->import($media), 'reject nonportable uploaded media');
$secret = $translated; $secret['pages'][0]['uid'] = 1;
$mustFail(fn() => $transfer->import($secret), 'reject undeclared metadata');
$collision = $translated; $collision['pages'][0]['uuid'] = $sentinelUuid; $collision['book'] = $sentinelUuid;
foreach ($collision['pages'] as &$page) { if ($page['parent'] === $source['book']) { $page['parent'] = $sentinelUuid; } }
unset($page);
$mustFail(fn() => $transfer->import($collision), 'reject UUID belonging to unrelated content');
$aliasCollision = $translated; $aliasCollision['pages'][0]['translations']['es']['alias'] = '/qa-retained-page';
$mustFail(fn() => $transfer->import($aliasCollision), 'reject alias owned by another page');
// The export is validated before writing and output is deterministic.
$transfer->write($source['book'], $qaExports[0]); $transfer->write($source['book'], $qaExports[1]);
$assert(hash_file('sha256', $qaExports[0]) === hash_file('sha256', $qaExports[1]), 'deterministic YAML bytes');
$assert($transfer->read($qaExports[0]) == $transfer->export($source['book']), 'YAML parse/write round-trip');
$mustFail(fn() => $transfer->write($source['book'], DRUPAL_ROOT . '/book-secret.yml'), 'reject export inside docroot');
// Inject a real outline API failure after the importer has allocated/saved nodes.
$rollback = $source;
$rollback['pages'] = [$source['pages'][0]];
$rollback['book'] = (new Php())->generate();
$rollback['pages'][0]['uuid'] = $rollback['book'];
$rollback['pages'][0]['translations']['es']['body']['value'] = '<p>Rollback test</p>';
$rollback['pages'][0]['translations']['es']['alias'] = '/qa-rollback-book';
$failingBooks = new class(\Drupal::service('book.manager')) extends \Drupal\book\BookManager {
  public bool $injected = FALSE;
  public function __construct(private \Drupal\book\BookManagerInterface $original) {}
  public function getLinkDefaults(int|string $nid): array { return $this->original->getLinkDefaults($nid); }
  public function loadBookLink(int $nid, bool $translate = TRUE): array { return $this->original->loadBookLink($nid, $translate); }
  public function updateOutline(\Drupal\node\NodeInterface $node): bool {
    $this->injected = TRUE;
    throw new RuntimeException('Injected outline persistence failure');
  }
};
$badTransfer = new \Drupal\book_import_export\BookTransfer(\Drupal::entityTypeManager(), $db, $failingBooks, \Drupal::languageManager(), \Drupal::lock());
$mustFail(fn() => $badTransfer->import($rollback), 'transaction rollback on mid-import persistence failure');
$assert($failingBooks->injected && $node($rollback['book']) === NULL, 'failure occurred after node allocation and created nodes were rolled back');
$assert($node($sentinelUuid)->getRevisionId() === $sentinelRevision, 'sentinel still intact after all operations');
echo "$checks integration checks passed. QA fixture changes rolled back.\n";
} finally {
  foreach ($qaExports as $path) { if (is_file($path)) { unlink($path); } }
  $qaTransaction->rollBack();
}
