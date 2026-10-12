<?php

declare(strict_types=1);

namespace Drupal\nelkano_modules\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Index;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ModuleController extends ControllerBase {
  public function title(NodeInterface $module): string { return $module->label(); }
  public function canonical(NodeInterface $node): array { return $this->detail($node); }

  public function card(NodeInterface $node): array {
    $registry = \Drupal::service('nelkano_modules.registry');
    $versions = $registry->releases($node);
    $available = array_values(array_filter($versions, static fn($r) => $r->get('field_release_state')->value === 'available'));
    $latest = $available[0] ?? NULL;
    return [
      'id' => $node->id(), 'title' => $node->label(), 'initial' => mb_strtoupper(mb_substr($node->label(), 0, 1)),
      'image' => $node->get('field_module_image')->isEmpty() ? [] : $node->get('field_module_image')->view([
        'label' => 'hidden', 'type' => 'image', 'settings' => ['image_style' => 'nelkano_module_card', 'image_link' => '']]),
      'image_detail' => $node->get('field_module_image')->isEmpty() ? [] : $node->get('field_module_image')->view([
        'label' => 'hidden', 'type' => 'image', 'settings' => ['image_style' => 'nelkano_module_detail', 'image_link' => '']]),
      'description' => $node->get('field_module_description')->value, 'module_id' => $node->get('field_module_id')->value,
      'author' => $node->getOwner()?->getDisplayName() ?? '', 'source' => $node->get('field_module_source')->value,
      'fingerprint' => $node->get('field_module_author')->value, 'published' => $node->isPublished(),
      'available' => (bool) $available, 'version' => $latest?->get('field_release_version')->value,
      'systems' => array_values(array_intersect_key(\Drupal\nelkano_modules\Service\ModuleSystems::options(),
        array_flip(array_column($node->get('field_module_systems')->getValue(), 'value')))),
      'platforms' => array_map(static fn($p) => $p === 'android' ? 'Android' : 'Windows · Linux',
        array_column($node->get('field_module_platforms')->getValue(), 'value')),
      'url' => Url::fromRoute('nelkano_modules.detail', ['module' => $node->id()])->toString(),
      'edit_url' => Url::fromRoute('nelkano_modules.edit', ['module' => $node->id()])->toString(),
      'release_url' => Url::fromRoute('nelkano_modules.release_add', ['module' => $node->id()])->toString(),
    ];
  }

  public function detail(NodeInterface $module): array {
    if ($module->bundle() !== 'nelkano_module' || !$module->access('view', $this->currentUser())) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    $can_edit = ModuleRegistry::canEdit($module, $this->currentUser());
    $versions = [];
    foreach (\Drupal::service('nelkano_modules.registry')->releases($module) as $release) {
      $versions[] = [
        'version' => $release->get('field_release_version')->value, 'sdk' => $release->get('field_release_sdk')->value,
        'notes' => $release->get('field_release_notes')->value,
        'available' => $release->get('field_release_state')->value === 'available',
        'url' => $release->get('field_release_url')->value, 'sha256' => $release->get('field_release_hash')->value,
        'size' => round((int) $release->get('field_release_size')->value / 1024, 1),
        'platforms' => array_map(static fn($p) => $p === 'android' ? 'Android' : 'Windows · Linux',
          array_column($release->get('field_release_platforms')->getValue(), 'value')),
        'capabilities' => array_column($release->get('field_release_capabilities')->getValue(), 'value'),
        'error' => $can_edit ? $release->get('field_release_error')->value : '',
        'checked' => $can_edit ? date('d/m/Y H:i', (int) $release->get('field_release_checked')->value) : '',
        'edit_url' => Url::fromRoute('nelkano_modules.release_edit', ['module' => $module->id(), 'release' => $release->id()])->toString(),
      ];
    }
    return ['#theme' => 'nelkano_module_detail', '#module' => $this->card($module),
      '#versions' => $versions, '#can_edit' => $can_edit, '#cache' => ['max-age' => 0]];
  }

  public function mine(): array {
    $ids = $this->entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'nelkano_module')->condition('uid', $this->currentUser()->id())->sort('changed', 'DESC')->execute();
    $items = [];
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($ids) as $node) { $items[] = $this->card($node); }
    return ['#theme' => 'nelkano_module_catalog', '#mine' => TRUE, '#items' => $items, '#total' => count($items),
      '#can_create' => TRUE, '#cache' => ['max-age' => 0]];
  }

  public function catalog(): array|AjaxResponse {
    $params = \Drupal::request()->query->all();
    $text = $params['q'] ?? '';
    $platform = $params['plataforma'] ?? '';
    $system = $params['sistema'] ?? '';
    $system_options = \Drupal\nelkano_modules\Service\ModuleSystems::options();
    $page = $params['pagina'] ?? '0';
    if (!is_string($text) || mb_strlen($text) > 200 || !is_string($platform)
      || !in_array($platform, ['', 'android', 'jvm-desktop'], TRUE)
      || !is_string($system) || ($system !== '' && !isset($system_options[$system]))
      || !is_string($page) || !preg_match('/^[0-9]{1,5}$/D', $page)) { throw new BadRequestHttpException(); }
    $page = (int) $page;
    $index = Index::load('nelkano_modules');
    if (!$index || !$index->status()) { throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(); }
    $query = $index->query()->addCondition('available', TRUE)->addCondition('published', TRUE)
      ->sort('changed', 'DESC')->range($page * 12, 12);
    if ($platform !== '') { $query->addCondition('platforms', $platform); }
    if ($system !== '') { $query->addCondition('systems', $system); }
    if (trim($text) !== '') {
      $query->keys(trim($text))->setFulltextFields(['title', 'description', 'module_id']);
      $query->setParseMode(\Drupal::service('plugin.manager.search_api.parse_mode')->createInstance('terms'));
    }
    $result = $query->execute();
    $items = [];
    foreach ($result->getResultItems() as $item) {
      try { $node = $item->getOriginalObject()->getValue(); }
      catch (\Drupal\search_api\SearchApiException) { continue; }
      if (!$node instanceof NodeInterface || $node->bundle() !== 'nelkano_module') { continue; }
      if ($platform !== '' && !in_array($platform, array_column($node->get('field_module_platforms')->getValue(), 'value'), TRUE)) { continue; }
      if ($system !== '' && !in_array($system, array_column($node->get('field_module_systems')->getValue(), 'value'), TRUE)) { continue; }
      // Search indexes can lag behind cron/content edits. Check live availability.
      if ($node->isPublished() && $node->get('field_module_available')->value && $node->access('view', $this->currentUser())) {
        $card = $this->card($node);
        if ($card['available']) { $items[] = $card; }
      }
    }
    $total = max(0, $result->getResultCount() - (count($result->getResultItems()) - count($items)));
    $pager = [];
    foreach (['previous' => $page > 0 ? $page - 1 : NULL, 'next' => ($page + 1) * 12 < $total ? $page + 1 : NULL] as $key => $number) {
      if ($number !== NULL) {
        $pager[$key] = Url::fromRoute('nelkano_modules.catalog', [], ['query' => ['q' => $text, 'plataforma' => $platform, 'sistema' => $system, 'pagina' => $number]])->toString();
      }
    }
    $results = ['#theme' => 'nelkano_module_results', '#items' => $items, '#total' => $total, '#pager' => $pager, '#cache' => ['max-age' => 0]];
    if (($params['_wrapper_format'] ?? '') === 'drupal_ajax') {
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('#nm-results', $results));
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }
    return ['#theme' => 'nelkano_module_catalog', '#sdk_download' => \Drupal\nelkano_home\Service\ReleaseArtifact::latestPublished('sdk'), '#items' => $items, '#total' => $total,
      '#query' => $text, '#platform' => $platform, '#system' => $system, '#system_options' => $system_options, '#pager' => $pager,
      '#can_create' => $this->currentUser()->isAuthenticated() && ($this->currentUser()->hasPermission('create nelkano modules')
        || $this->currentUser()->hasPermission('administer nelkano modules')),
      '#cache' => ['max-age' => 0]];
  }
}
