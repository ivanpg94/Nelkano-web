<?php

namespace Drupal\nelkano_home\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Public SDK book snapshot shared by the HTTP API and offline Studio export. */
final class SdkReference {

  public const BOOK_UUID = HomeExtensions::SDK_ROOT;
  public const SDK_VERSION = '0.2.0';

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly LanguageManagerInterface $languages,
  ) {}

  /**
   * Return only the current public text, never revisions or privileged fields.
   *
   * Access always uses the anonymous account, even during an administrator's
   * request or Drush invocation. A published parent is required for its branch.
   */
  public function snapshot(?CacheableMetadata $cache = NULL): array {
    $cache ??= new CacheableMetadata();
    $cache->addCacheTags(['node_list', 'node_list:sdk_page', 'config:book.settings']);
    // Recompute on every conditional request; edits and outline changes cannot
    // leave a stale snapshot in Drupal's render or dynamic page caches.
    $cache->setCacheMaxAge(0);
    $storage = $this->entities->getStorage('node');
    $roots = $storage->loadByProperties(['uuid' => self::BOOK_UUID, 'type' => 'sdk_page']);
    $root = $roots ? reset($roots) : NULL;
    if (!$root instanceof NodeInterface || (int) ($root->getBook()['bid'] ?? 0) !== (int) $root->id()) {
      throw new NotFoundHttpException('The public SDK book is unavailable.');
    }
    $rows = $this->database->select('book', 'b')->fields('b', ['nid', 'pid', 'weight'])
      ->condition('bid', $root->id())->execute()->fetchAllAssoc('nid');
    $nodes = $storage->loadMultiple(array_keys($rows));
    $anonymous = new AnonymousUserSession();
    $chapters = [];
    $languages = $root->getTranslationLanguages();
    ksort($languages);
    foreach ($languages as $language => $definition) {
      $public = [];
      foreach ($nodes as $nid => $node) {
        $cache->addCacheableDependency($node);
        if ($node->bundle() !== 'sdk_page' || !$node->hasTranslation($language)) {
          continue;
        }
        $translation = $node->getTranslation($language);
        $access = $translation->access('view', $anonymous, TRUE);
        $cache->addCacheTags($access->getCacheTags());
        if ($translation->isPublished() && $access->isAllowed() && $translation->hasField('body')) {
          $public[$nid] = $translation;
        }
      }
      if (!isset($public[$root->id()])) {
        continue;
      }
      $children = [];
      foreach ($rows as $nid => $row) {
        if ($nid != $root->id() && isset($public[$nid])) {
          $children[(int) $row->pid][] = (int) $nid;
        }
      }
      foreach ($children as &$siblings) {
        usort($siblings, static fn(int $left, int $right): int =>
          ((int) $rows[$left]->weight <=> (int) $rows[$right]->weight)
          ?: strnatcasecmp($public[$left]->label(), $public[$right]->label())
          ?: ($left <=> $right));
      }
      unset($siblings);
      $visit = function (int $nid, array $visited = []) use (&$visit, &$chapters, $public, $children, $language): void {
        if (isset($visited[$nid]) || !isset($public[$nid])) {
          return;
        }
        $visited[$nid] = TRUE;
        $node = $public[$nid];
        $chapters[] = [
          'id' => $node->uuid(),
          'language' => $language,
          'title' => (string) $node->label(),
          'path' => $node->toUrl('canonical', [
            'absolute' => FALSE, 'language' => $this->languages->getLanguage($language),
          ])->toString(),
          'content' => (string) ($node->get('body')->value ?? ''),
        ];
        foreach ($children[$nid] ?? [] as $child) {
          $visit($child, $visited);
        }
      };
      $visit((int) $root->id());
    }
    if (!$chapters) {
      throw new NotFoundHttpException('The public SDK book is unavailable.');
    }
    $paths = [];
    foreach ($chapters as $chapter) {
      $paths[$chapter['id'] . '/' . $chapter['language']] = $chapter['path'];
    }
    foreach ($chapters as &$chapter) {
      $chapter['content'] = $this->resolveLinks($chapter['content'], $paths);
    }
    unset($chapter);
    $payload = [
      'schemaVersion' => 1,
      'sdkVersion' => self::SDK_VERSION,
      'book' => self::BOOK_UUID,
      'chapters' => $chapters,
    ];
    return array_slice($payload, 0, 3, TRUE)
      + ['revision' => hash('sha256', self::encode($payload))]
      + ['chapters' => $chapters];
  }

  /** Byte-stable UTF-8 encoding is also the revision hash input. */
  public static function encode(array $data): string {
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /** Rewrite portable links while preserving HTML and code block bytes. */
  private function resolveLinks(string $html, array $paths): string {
    return preg_replace_callback('~(\bhref\s*=\s*)(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))~i', static function (array $match) use ($paths): string {
      $href = html_entity_decode($match[2] ?: ($match[3] ?? '') ?: ($match[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if (!str_starts_with($href, 'book://')) {
        return $match[0];
      }
      if (!preg_match('~^book://([a-f0-9-]{36}/[a-zA-Z0-9_-]+)([?#].*)?$~D', $href, $target)
        || !isset($paths[$target[1]])) {
        // Do not expose a navigable private chapter or an unresolved local ID.
        return '';
      }
      return $match[1] . '"' . htmlspecialchars($paths[$target[1]] . ($target[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
    }, $html);
  }

  /** Export the same public response outside the web document root. */
  public function write(string $file): array {
    $directory = realpath(dirname($file));
    $docroot = realpath(DRUPAL_ROOT);
    if (!$directory || !$docroot || $directory === $docroot
      || str_starts_with($directory . DIRECTORY_SEPARATOR, $docroot . DIRECTORY_SEPARATOR)
      || is_link($file)) {
      throw new \InvalidArgumentException('Choose an existing directory outside docroot; symlinks are forbidden.');
    }
    $data = $this->snapshot();
    $json = self::encode($data) . "\n";
    $temporary = tempnam($directory, '.sdk-reference-');
    try {
      if ($temporary === FALSE || file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
        throw new \RuntimeException('Could not atomically write the SDK reference.');
      }
    }
    finally {
      if (is_string($temporary) && is_file($temporary)) {
        unlink($temporary);
      }
    }
    return $data;
  }
}
