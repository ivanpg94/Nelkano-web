<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Service;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Index;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Public projection only: no sessions, private release nodes or owner errors. */
final class ModuleApiCatalog {
  public function __construct(private readonly ModuleRegistry $registry) {}

  public function module(string $id): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'nelkano_module')
      ->condition('status', 1)->condition('field_module_id', $id)->range(0, 1)->execute();
    $node = $ids ? $storage->load(reset($ids)) : NULL;
    if (!$node || !$this->publicModule($node)) { throw new NotFoundHttpException('module_not_found'); }
    return $node;
  }

  private function publicModule(NodeInterface $node): bool {
    $account = ModuleAudience::account();
    return $node->bundle() === 'nelkano_module' && $node->isPublished()
      && ModuleAudience::canRead($node, $account) && $node->access('view', $account);
  }

  private function matches(NodeInterface $release, array $query): bool {
    return (!$query['platform'] || in_array($query['platform'], array_column($release->get('field_release_platforms')->getValue(), 'value'), TRUE))
      && (!$query['sdks'] || in_array($release->get('field_release_sdk')->value, $query['sdks'], TRUE));
  }

  public function release(NodeInterface $module, NodeInterface $release, bool $full = FALSE): array {
    $available = $release->get('field_release_state')->value === 'available';
    $url = (string) $release->get('field_release_url')->value;
    $hash = (string) $release->get('field_release_hash')->value;
    $size = (int) $release->get('field_release_size')->value;
    $fid = ModuleHostedPackages::fid($release);
    if ($fid) {
      $url = $this->url('nelkano_modules.api_download', ['module_id' => $module->get('field_module_id')->value,
        'version' => $release->get('field_release_version')->value]);
      try { \Drupal::service('nelkano_modules.hosted_packages')->file($fid, $module, $release, FALSE); }
      catch (\InvalidArgumentException) { $available = FALSE; }
    }
    else {
      if (ModuleAudience::access($module) === 'tester') { $available = FALSE; }
      try { SecureDownloadClient::validateUrl($url, TRUE); }
      catch (\InvalidArgumentException) { $available = FALSE; }
    }
    $available = $available && preg_match('/^[0-9a-f]{64}$/D', $hash)
      && $size > 0 && $size <= PackageVerifier::MAX_ARCHIVE;
    $result = [
      'version' => (string) $release->get('field_release_version')->value,
      'sdk_version' => (string) $release->get('field_release_sdk')->value,
      'platforms' => array_column($release->get('field_release_platforms')->getValue(), 'value'),
      'capabilities' => array_column($release->get('field_release_capabilities')->getValue(), 'value'),
      'available' => (bool) $available, 'download_url' => $available ? $url : NULL,
      'download_auth' => $fid && ModuleAudience::access($module) === 'tester' ? 'session' : 'none',
      'size_bytes' => $size, 'sha256' => $hash,
      'checked_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $release->get('field_release_checked')->value),
      'api_url' => $this->url('nelkano_modules.api_version', ['module_id' => $module->get('field_module_id')->value,
        'version' => $release->get('field_release_version')->value]),
    ];
    if ($full) { $result['notes'] = (string) $release->get('field_release_notes')->value; }
    return $result;
  }

  public function summary(NodeInterface $module, array $query, bool $full = FALSE): array {
    $releases = $this->registry->releases($module);
    $latest = NULL; $platforms = [];
    foreach ($releases as $release) {
      if (!$this->matches($release, $query)) { continue; }
      $item = $this->release($module, $release);
      if (!$item['available']) { continue; }
      $latest ??= $item;
      foreach ($item['platforms'] as $platform) { $platforms[$platform] = $platform; }
    }
    $id = (string) $module->get('field_module_id')->value;
    $description = (string) $module->get('field_module_description')->value;
    $data = [
      'id' => $id, 'name' => $module->label(), 'access' => ModuleAudience::access($module),
      'description' => $full ? $description : mb_substr($description, 0, 320),
      'author' => ['name' => $module->getOwner()?->getDisplayName() ?? ''],
      'signing' => ['algorithm' => 'Ed25519', 'author_fingerprint_sha256' => (string) $module->get('field_module_author')->value],
      'image' => $this->image($module), 'available' => $latest !== NULL,
      'systems' => array_column($module->get('field_module_systems')->getValue(), 'value'),
      'platforms' => array_values($platforms), 'latest_release' => $latest,
      'web_url' => $this->url('nelkano_modules.detail', ['module' => $module->id()]),
      'api_url' => $this->url('nelkano_modules.api_detail', ['module_id' => $id]),
      'versions_url' => $this->url('nelkano_modules.api_versions', ['module_id' => $id]),
    ];
    if ($full) { $data['source_url'] = ModuleAudience::access($module) === 'tester' ? NULL : ($module->get('field_module_source')->value ?: NULL); }
    return $data;
  }

  private function image(NodeInterface $module): ?array {
    if (ModuleAudience::access($module) === 'tester') { return NULL; }
    $field = $module->get('field_module_image');
    $file = $field->entity;
    if (!$file || !$file->access('download', new AnonymousUserSession())) { return NULL; }
    $uri = $file->getFileUri();
    return [
      'url' => ImageStyle::load('nelkano_module_detail')->buildUrl($uri),
      'thumbnail_url' => ImageStyle::load('nelkano_module_card')->buildUrl($uri),
      'alt' => $field->alt ?: $module->label(),
    ];
  }

  /** Offset follows index candidates, not returned items; scan work is bounded. */
  public function search(array $parameters): array {
    $index = Index::load('nelkano_modules');
    try {
      if (!$index || !$index->status() || !$index->getServerInstance()?->status()) {
        throw new ServiceUnavailableHttpException(60, 'catalog_unavailable');
      }
    }
    catch (\Drupal\search_api\SearchApiException $e) { throw new ServiceUnavailableHttpException(60, 'catalog_unavailable', $e); }
    $query = $index->query()->addCondition('available', TRUE)->addCondition('published', TRUE)
      ->sort('changed', 'DESC')->sort('search_api_id', 'ASC');
    ModuleAudience::filterQuery($query, ModuleAudience::account());
    if ($parameters['platform']) { $query->addCondition('platforms', $parameters['platform']); }
    if (!empty($parameters['system'])) { $query->addCondition('systems', $parameters['system']); }
    if ($parameters['q']) {
      $query->keys($parameters['q'])->setFulltextFields(['title', 'description', 'module_id']);
      $query->setParseMode(\Drupal::service('plugin.manager.search_api.parse_mode')->createInstance('terms'));
    }
    $offset = $parameters['offset']; $items = []; $scanned = 0; $has_more = FALSE;
    do {
      try { $batch = clone $query; $result = $batch->range($offset, 50)->execute(); }
      catch (\Drupal\search_api\SearchApiException $e) { throw new ServiceUnavailableHttpException(60, 'catalog_unavailable', $e); }
      $candidates = $result->getResultItems();
      $total = $result->getResultCount();
      foreach ($candidates as $candidate) {
        $offset++; $scanned++;
        try { $module = $candidate->getOriginalObject()->getValue(); }
        catch (\Drupal\search_api\SearchApiException) { continue; }
        if ($module instanceof NodeInterface && $this->publicModule($module) && $module->get('field_module_available')->value) {
          if (!empty($parameters['system']) && !in_array($parameters['system'], array_column($module->get('field_module_systems')->getValue(), 'value'), TRUE)) { continue; }
          $item = $this->summary($module, $parameters);
          if ($item['available']) { $items[] = $item; }
        }
        if (count($items) === $parameters['limit']) { break; }
      }
      $has_more = (bool) $candidates && $offset < $total;
    } while ($has_more && count($items) < $parameters['limit'] && $scanned < 200);
    return ['items' => $items, 'pagination' => $this->pagination('nelkano_modules.api_catalog', [], $parameters, $has_more ? $offset : NULL)];
  }

  public function versions(NodeInterface $module, array $parameters): array {
    $all = array_values(array_filter($this->registry->releases($module), fn($r) => $this->matches($r, $parameters)));
    $items = array_map(fn($r) => $this->release($module, $r), array_slice($all, $parameters['offset'], $parameters['limit']));
    $offset = $parameters['offset'] + count($items);
    return ['module_id' => (string) $module->get('field_module_id')->value, 'items' => $items,
      'pagination' => $this->pagination('nelkano_modules.api_versions', ['module_id' => $module->get('field_module_id')->value],
        $parameters, $offset < count($all) ? $offset : NULL)];
  }

  public function version(NodeInterface $module, string $version, array $parameters): array {
    foreach ($this->registry->releases($module) as $release) {
      if ($release->get('field_release_version')->value === $version && $this->matches($release, $parameters)) {
        return $this->release($module, $release, TRUE);
      }
    }
    throw new NotFoundHttpException('version_not_found');
  }

  private function pagination(string $route, array $route_parameters, array $parameters, ?int $offset): array {
    $query = ['limit' => $parameters['limit'], 'offset' => $offset];
    if ($parameters['q']) { $query['q'] = $parameters['q']; }
    if ($parameters['platform']) { $query['platform'] = $parameters['platform']; }
    if (!empty($parameters['system'])) { $query['system'] = $parameters['system']; }
    if ($parameters['sdks']) { $query['sdk'] = implode(',', $parameters['sdks']); }
    return ['limit' => $parameters['limit'], 'has_more' => $offset !== NULL, 'next_offset' => $offset,
      'next_url' => $offset === NULL ? NULL : $this->url($route, $route_parameters, $query)];
  }

  private function url(string $route, array $parameters, array $query = []): string {
    return Url::fromRoute($route, $parameters, ['absolute' => TRUE, 'query' => $query])->toString();
  }
}