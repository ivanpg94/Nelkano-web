<?php

namespace Drupal\book_import_export;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\book\BookManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Yaml\Yaml;

/** Explicit content serialization: no users, grants, config, uploads or revisions. */
final class BookTransfer {
  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly BookManagerInterface $books,
    private readonly LanguageManagerInterface $languages,
    private readonly LockBackendInterface $lock,
  ) {}

  private function node(string $uuid): ?NodeInterface {
    $nodes = $this->entities->getStorage('node')->loadByProperties(['uuid' => $uuid]);
    return $nodes ? reset($nodes) : NULL;
  }

  public function read(string $file): array {
    if (!is_file($file) || filesize($file) > 16 * 1024 * 1024) {
      throw new \InvalidArgumentException('Missing file or YAML larger than 16 MiB.');
    }
    $data = Yaml::parseFile($file, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
    if (!is_array($data)) { throw new \InvalidArgumentException('Expected YAML mapping.'); }
    return $data;
  }

  public function write(string $uuid, string $file): void {
    $directory = realpath(dirname($file));
    $docroot = realpath(DRUPAL_ROOT);
    if (!$directory || str_starts_with($directory . '/', $docroot . '/') || is_link($file)) {
      throw new \InvalidArgumentException('Export to an existing directory outside docroot; symlinks forbidden.');
    }
    $yaml = Yaml::dump($this->export($uuid), 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    $temporary = tempnam($directory, '.book-');
    try {
      if (file_put_contents($temporary, $yaml) !== strlen($yaml) || !rename($temporary, $file)) {
        throw new \RuntimeException('Could not atomically write book YAML.');
      }
    }
    finally { if (is_file($temporary)) { unlink($temporary); } }
  }

  public function export(string $uuid): array {
    $root = $this->node($uuid);
    if (!$root || (int) ($root->getBook()['bid'] ?? 0) !== (int) $root->id()) {
      throw new \InvalidArgumentException('UUID must identify a Book root.');
    }
    $rows = $this->database->select('book', 'b')->fields('b', ['nid', 'pid', 'weight'])
      ->condition('bid', $root->id())->execute()->fetchAllAssoc('nid');
    $nodes = $this->entities->getStorage('node')->loadMultiple(array_keys($rows));
    $pages = [];
    $links = [];
    foreach ($nodes as $node) {
      $id = $node->uuid();
      $row = $rows[$node->id()];
      $translations = [];
      foreach ($node->getTranslationLanguages() as $lang => $language) {
        $translation = $node->getTranslation($lang);
        if (!$translation->hasField('body') || !$translation->hasField('path')) {
          throw new \InvalidArgumentException('Only title/body/path node types are supported.');
        }
        $path = $translation->get('path')->first();
        $alias = $path?->alias ?: NULL;
        $translations[$lang] = [
          'title' => $translation->label(),
          'status' => $translation->isPublished(),
          'body' => [
            'value' => $translation->get('body')->value ?? '',
            'summary' => $translation->get('body')->summary ?? '',
            'format' => $translation->get('body')->format,
          ],
          'alias' => $alias,
        ];
        // Index URLs including configured language prefixes and aliases.
        $links[$lang]['/node/' . $node->id()] = "book://$id/$lang";
        if ($alias) { $links[$lang][$alias] = "book://$id/$lang"; $links['*'][$alias] = "book://$id/$lang"; }
        $url = $translation->toUrl('canonical', ['language' => $language])->toString();
        $links['*'][$url] = "book://$id/$lang";
        $links['*']['/' . $lang . '/node/' . $node->id()] = "book://$id/$lang";
      }
      ksort($translations);
      $pages[$id] = [
        'uuid' => $id, 'type' => $node->bundle(), 'langcode' => $node->language()->getId(),
        'parent' => $row->pid ? ($nodes[$row->pid]->uuid() ?? NULL) : NULL,
        'weight' => (int) $row->weight, 'translations' => $translations,
      ];
    }
    foreach ($pages as &$page) {
      foreach ($page['translations'] as $lang => &$translation) {
        foreach (['value', 'summary'] as $key) {
          $translation['body'][$key] = $this->links($translation['body'][$key], function (string $href) use ($links, $lang): string {
            [$path, $suffix] = $this->splitUrl($href);
            $portable = $links[$lang][$path] ?? $links['*'][$path] ?? NULL;
            if ($portable) { return $portable . $suffix; }
            if (preg_match('~^/(?:[^/]+/)?node/\d+(?:/|$)~', $path)) {
              throw new \InvalidArgumentException('Node link outside this book: ' . $href);
            }
            return $href;
          });
        }
      }
      unset($translation);
    }
    unset($page);
    // UUID sorting makes output independent of local numeric IDs and query order.
    ksort($pages);
    $data = ['schema' => 1, 'book' => $uuid, 'pages' => array_values($pages)];
    $this->validate($data);
    return $data;
  }

  private function splitUrl(string $url): array {
    $at = strcspn($url, '?#');
    return [substr($url, 0, $at), substr($url, $at)];
  }

  /** Preserve markup bytes; rewrite only href attribute values, including unquoted. */
  private function links(string $html, callable $rewrite): string {
    if (preg_match('~<(?:img|iframe|video|audio|source)\b|data-entity-type\s*=\s*["\x27]?(?:file|media)~i', $html)) {
      throw new \InvalidArgumentException('Uploaded/embedded media are not portable; use an external text link.');
    }
    return preg_replace_callback('~(\bhref\s*=\s*)(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))~i', function (array $match) use ($rewrite): string {
      $href = html_entity_decode($match[2] ?: ($match[3] ?? '') ?: ($match[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $new = $rewrite($href);
      if ($new === $href) { return $match[0]; }
      return $match[1] . '"' . htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
    }, $html);
  }

  private function resolveLinks(string $html, array $pages, array $nodes): string {
    return $this->links($html, function (string $href) use ($pages, $nodes): string {
      if (!str_starts_with($href, 'book://')) { return $href; }
      preg_match('~^book://([a-f0-9-]{36})/([a-zA-Z0-9_-]+)(.*)$~D', $href, $m);
      $target = $nodes[$m[1]];
      $alias = isset($pages[$m[1]]['translations'][$m[2]]) ? $pages[$m[1]]['translations'][$m[2]]['alias'] : ($target->getTranslation($m[2])->get('path')->first()?->alias ?: NULL);
      $options = ['language' => $this->languages->getLanguage($m[2]), 'alias' => TRUE];
      // New numeric IDs are allocated only during commit; dry-run uses a placeholder.
      $path = \Drupal::service('path_processor_manager')->processOutbound($alias ?: '/node/' . ($target?->id() ?? ('new-' . $m[1])), $options);
      $url = rtrim($options['base_url'] ?? base_path(), '/') . '/' . ($options['prefix'] ?? '') . ltrim($path, '/');
      return $url . $m[3];
    });
  }
  private function keys(array $value, array $expected): void {
    $actual = array_keys($value); sort($actual); sort($expected);
    if ($actual !== $expected) { throw new \InvalidArgumentException('Unknown or missing fields: expected ' . implode(', ', $expected)); }
  }

  /** Strict schema, graph, language and format validation with no database writes. */
  public function validate(array $data): array {
    $this->keys($data, ['schema', 'book', 'pages']);
    if ($data['schema'] !== 1 || !is_string($data['book']) || !Uuid::isValid($data['book']) || strtolower($data['book']) !== $data['book'] ||
        !is_array($data['pages']) || !array_is_list($data['pages']) || !$data['pages'] || count($data['pages']) > 1000) {
      throw new \InvalidArgumentException('Invalid schema, root UUID or page list (1–1000).');
    }
    $pages = [];
    $aliases = [];
    foreach ($data['pages'] as $page) {
      if (!is_array($page)) { throw new \InvalidArgumentException('Invalid page.'); }
      $this->keys($page, ['uuid', 'type', 'langcode', 'parent', 'weight', 'translations']);
      if (!is_string($page['uuid']) || !Uuid::isValid($page['uuid']) || strtolower($page['uuid']) !== $page['uuid'] || isset($pages[$page['uuid']]) ||
          !is_int($page['weight']) || abs($page['weight']) > 32767 ||
          (!is_null($page['parent']) && (!is_string($page['parent']) || !Uuid::isValid($page['parent']))) ||
          !is_string($page['type']) || !is_string($page['langcode']) || !is_array($page['translations']) ||
          !isset($page['translations'][$page['langcode']])) {
        throw new \InvalidArgumentException('Invalid/duplicate page UUID, hierarchy, weight or default language.');
      }
      $type = $this->entities->getStorage('node_type')->load($page['type']);
      $allowed = array_column(\Drupal::config('book.settings')->get('allowed_types') ?? [], 'content_type');
      if (!$type || !in_array($page['type'], $allowed, TRUE)) { throw new \InvalidArgumentException('Node type not enabled for Book: ' . $page['type']); }
      $bundleInfo = \Drupal::service('entity_type.bundle.info')->getBundleInfo('node');
      if (!is_a($bundleInfo[$page['type']]['class'] ?? '', \Drupal\book\BookInterface::class, TRUE)) {
        throw new \InvalidArgumentException('Bundle class must implement BookInterface.');
      }
      $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $page['type']);
      if (isset($definitions['moderation_state'])) { throw new \InvalidArgumentException('Moderated bundles are not supported by schema 1.'); }
      if (!isset($definitions['body'], $definitions['path'])) { throw new \InvalidArgumentException('Node type requires body and path.'); }
      if (count($page['translations']) > 1 && (!$definitions['body']->isTranslatable() || !$definitions['title']->isTranslatable())) {
        throw new \InvalidArgumentException('Title and body must be translatable.');
      }
      foreach ($page['translations'] as $lang => $translation) {
        if (!$this->languages->getLanguage($lang) || !is_array($translation)) { throw new \InvalidArgumentException('Unknown language: ' . $lang); }
        $this->keys($translation, ['title', 'status', 'body', 'alias']);
        if (!is_string($translation['title']) || !$translation['title'] || mb_strlen($translation['title']) > 255 || !is_bool($translation['status']) || !is_array($translation['body'])) {
          throw new \InvalidArgumentException('Invalid title/status/body.');
        }
        $this->keys($translation['body'], ['value', 'summary', 'format']);
        foreach (['value', 'summary'] as $key) {
          if (!is_string($translation['body'][$key]) || strlen($translation['body'][$key]) > 1024 * 1024) { throw new \InvalidArgumentException('Body must be text, maximum 1 MiB.'); }
        }
        $format = is_string($translation['body']['format']) ? $this->entities->getStorage('filter_format')->load($translation['body']['format']) : NULL;
        if (!$format || !$format->status()) { throw new \InvalidArgumentException('Missing or disabled text format.'); }
        $alias = $translation['alias'];
        if ($alias !== NULL) {
          if (!is_string($alias) || !preg_match('~^/(?!/|node(?:/|$))[a-zA-Z0-9/_\-]+$~D', $alias) || strlen($alias) > 255) {
            throw new \InvalidArgumentException('Use a portable absolute alias without query or fragment.');
          }
          $aliasKey = $lang . ':' . $alias;
          if (isset($aliases[$aliasKey])) { throw new \InvalidArgumentException('Duplicate alias in YAML.'); }
          $aliases[$aliasKey] = $page['uuid'];
        }
      }
      $pages[$page['uuid']] = $page;
    }
    if (!isset($pages[$data['book']]) || $pages[$data['book']]['parent'] !== NULL) { throw new \InvalidArgumentException('Root missing or has a parent.'); }
    // Book breaks equal weight/title ties by local NID, which is not portable.
    // Require an explicit weight in that case rather than silently change order.
    $orderKeys = [];
    foreach ($this->languages->getLanguages() as $lang => $language) {
      foreach ($pages as $uuid => $page) {
        $title = $page['translations'][$lang]['title'] ?? $page['translations'][$page['langcode']]['title'];
        $key = json_encode([$page['parent'], $page['weight'], $title, $lang]);
        if (isset($orderKeys[$key])) { throw new \InvalidArgumentException('Ambiguous sibling order: use distinct weights for equal translated titles.'); }
        $orderKeys[$key] = $uuid;
      }
    }
    $depths = [];
    foreach ($pages as $uuid => $page) {
      $seen = []; $cursor = $uuid;
      while ($cursor !== NULL) {
        if (isset($seen[$cursor]) || !isset($pages[$cursor])) { throw new \InvalidArgumentException('Cycle or missing parent.'); }
        $seen[$cursor] = TRUE; $cursor = $pages[$cursor]['parent'];
      }
      if (!isset($seen[$data['book']]) || count($seen) > 9) { throw new \InvalidArgumentException('Detached page or depth exceeds Book maximum 9.'); }
      $depths[$uuid] = count($seen);
      foreach ($page['translations'] as $translation) {
        foreach (['value', 'summary'] as $key) {
          $this->links($translation['body'][$key], function (string $href) use ($pages): string {
            if (str_starts_with($href, 'book://')) {
              if (!preg_match('~^book://([a-f0-9-]{36})/([a-zA-Z0-9_-]+)(?:[?#].*)?$~D', $href, $m) || !isset($pages[$m[1]])) {
                throw new \InvalidArgumentException('Unresolved portable link: ' . $href);
              }
              if (!isset($pages[$m[1]]['translations'][$m[2]]) && !$this->node($m[1])?->hasTranslation($m[2])) {
                throw new \InvalidArgumentException('Link translation missing in YAML and destination: ' . $href);
              }
            }
            elseif (preg_match('~^/(?:[^/]+/)?node/\d+(?:[/?#]|$)~', $href)) { throw new \InvalidArgumentException('Nonportable numeric node link.'); }
            return $href;
          });
        }
      }
    }
    uksort($pages, fn($a, $b) => [$depths[$a], $pages[$a]['weight'], $a] <=> [$depths[$b], $pages[$b]['weight'], $b]);
    return $pages;
  }

  /** Validate conflicts, build plan, then commit all writes in one transaction. */
  public function import(array $data, bool $dryRun = FALSE): array {
    if (!$this->lock->acquire('book_import_export', 120)) { throw new \RuntimeException('Another import is running.'); }
    $transaction = NULL;
    try {
      $pages = $this->validate($data);
      $root = $this->node($data['book']);
      $nodes = []; $plan = [];
      foreach ($pages as $uuid => $page) {
        $node = $this->node($uuid);
        if ($node && ($node->bundle() !== $page['type'] || $node->language()->getId() !== $page['langcode'] ||
            !$root || (int) ($node->getBook()['bid'] ?? 0) !== (int) $root->id())) {
          throw new \InvalidArgumentException('UUID conflicts with another book/type/default language: ' . $uuid);
        }
        // Alias collisions cannot silently reassign an unrelated route.
        foreach ($page['translations'] as $lang => $translation) {
          if (!$translation['alias']) { continue; }
          $existing = $this->entities->getStorage('path_alias')->loadByProperties(['alias' => $translation['alias'], 'langcode' => $lang]);
          foreach ($existing as $alias) {
            if (!$node || $alias->getPath() !== '/node/' . $node->id()) { throw new \InvalidArgumentException('Alias conflict: ' . $translation['alias']); }
          }
        }
        $nodes[$uuid] = $node;
        $plan[$uuid] = $node ? 'update' : 'create';
      }
      // Plan uses portable values, so a repeated import is a no-op, even with different NIDs.
      if ($root) {
        $current = array_column($this->export($data['book'])['pages'], NULL, 'uuid');
        foreach ($pages as $uuid => $page) {
          if (isset($current[$uuid])) {
            $comparison = $current[$uuid];
            $comparison['translations'] = array_intersect_key($comparison['translations'], $page['translations']);
            if ($comparison == $page) {
              $plan[$uuid] = 'unchanged';
              // Alias or language-prefix changes also revise referring pages.
              foreach ($page['translations'] as $lang => $values) {
                $translation = $nodes[$uuid]->getTranslation($lang);
                foreach (['value', 'summary'] as $key) {
                  if (($translation->get('body')->{$key} ?? '') !== $this->resolveLinks($values['body'][$key], $pages, $nodes)) {
                    $plan[$uuid] = 'update';
                  }
                }
              }
            }
          }
        }
        // Retained descendants must also fit the target hierarchy's depth limit.
        $all = $current;
        foreach ($pages as $uuid => $page) { $all[$uuid] = $page; }
        foreach ($all as $uuid => $page) {
          $seen = []; $cursor = $uuid;
          while ($cursor !== NULL) {
            if (isset($seen[$cursor]) || !isset($all[$cursor]) || count($seen) >= 9) { throw new \InvalidArgumentException('Retained descendants cause a cycle or depth overflow.'); }
            $seen[$cursor] = TRUE; $cursor = $all[$cursor]['parent'];
          }
        }
      }
      if ($dryRun) { return $plan; }
      $transaction = $this->database->startTransaction();
      $storage = $this->entities->getStorage('node');
      // First allocate node IDs so forward links can resolve. Everything rolls back on failure.
      foreach ($pages as $uuid => $page) {
        if (!$nodes[$uuid]) {
          $nodes[$uuid] = $storage->create(['type' => $page['type'], 'uuid' => $uuid,
            'langcode' => $page['langcode'], 'title' => $page['translations'][$page['langcode']]['title'], 'status' => 0, 'uid' => 0]);
          $nodes[$uuid]->save();
        }
      }
      foreach ($pages as $uuid => $page) {
        if ($plan[$uuid] === 'unchanged') { continue; }
        $node = $nodes[$uuid];
        foreach ($page['translations'] as $lang => $values) {
          $translation = $node->hasTranslation($lang) ? $node->getTranslation($lang) : $node->addTranslation($lang);
          $translation->setTitle($values['title']);
          $translation->set('status', $values['status']);
          $body = $values['body'];
          foreach (['value', 'summary'] as $key) {
            $body[$key] = $this->resolveLinks($body[$key], $pages, $nodes);
          }
          $translation->set('body', $body);
          $oldPath = $translation->get('path')->first();
          $translation->set('path', ['alias' => $values['alias'], 'pid' => $oldPath?->pid, 'langcode' => $lang, 'pathauto' => 0]);
          $translation->setRevisionTranslationAffected(TRUE);
        }
        $book = $node->getBook() ?: $this->books->getLinkDefaults($node->id());
        $book['bid'] = (int) $nodes[$data['book']]->id();
        $book['pid'] = $page['parent'] ? (int) $nodes[$page['parent']]->id() : 0;
        $book['nid'] = (int) $node->id(); $book['weight'] = $page['weight'];
        $node->setBook($book);
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage('Book YAML import schema 1; root ' . $data['book']);
        $node->setRevisionUserId(0);
        $node->setRevisionCreationTime(time());
        $node->save();
        // Entity saves can clone translations and drop the non-field outline property.
        // Persist the outline explicitly through Book's API, never by writing its table.
        $savedOutline = $this->books->loadBookLink((int) $node->id(), FALSE);
        if (!$savedOutline || (int) $savedOutline['bid'] !== $book['bid'] ||
            (int) $savedOutline['pid'] !== $book['pid'] || (int) $savedOutline['weight'] !== $book['weight']) {
          $book['original_bid'] = $savedOutline['bid'] ?? 0;
          $node->setBook($book);
          if (!$this->books->updateOutline($node)) { throw new \RuntimeException('Book outline update failed.'); }
        }
      }
      unset($transaction);
      $storage->resetCache();
      return $plan;
    }
    catch (\Throwable $exception) {
      if ($transaction) { $transaction->rollBack(); }
      $this->entities->getStorage('node')->resetCache();
      $this->entities->getStorage('path_alias')->resetCache();
      throw $exception;
    }
    finally { $this->lock->release('book_import_export'); }
  }
}
