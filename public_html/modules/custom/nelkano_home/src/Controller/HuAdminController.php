<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\nelkano_home\Form\AdminFormUiTrait;
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\search_api\Entity\Index;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class HuAdminController extends ControllerBase {
  use AdminFormUiTrait;

  /** Parse only supported filter values; distinguish Sprint 0 from no sprint. */
  public static function sprintFilter(Request $request): string {
    $value = $request->query->all()['sprint'] ?? '';
    if (!is_string($value) || ($value !== '' && $value !== 'none' && (!preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]) === FALSE))) {
      throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('El filtro Sprint debe ser un número entero.');
    }
    return $value;
  }

  /** Query text is also carried through editing and creation. */
  public static function searchFilter(Request $request): string {
    $value = $request->query->all()['q'] ?? '';
    if (!is_string($value) || mb_strlen($value) > 200) {
      throw new BadRequestHttpException('La búsqueda admite como máximo 200 caracteres.');
    }
    return trim($value);
  }

  public function board(): array|AjaxResponse {
    // Guard direct calls too: the index contains unpublished HU.
    if (!$this->currentUser()->hasPermission(HuWorkflow::PERMISSION)) {
      throw new AccessDeniedHttpException();
    }
    $sprint = self::sprintFilter(\Drupal::request());
    $search = self::searchFilter(\Drupal::request());
    $filter_query = array_filter(['sprint' => $sprint, 'q' => $search], static fn(string $value): bool => $value !== '');
    $columns = [];
    foreach (WorkflowStatus::options(TRUE) as $code => $label) {
      $columns[$code] = ['code' => $code, 'label' => $label, 'cards' => []];
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $index = Index::load('nelkano_backlog');
    if (!$index || !$index->status() || !$index->getServerInstance()->status()) {
      throw new ServiceUnavailableHttpException(NULL, 'El índice del backlog no está disponible.');
    }
    $query = $index->query()->range(0, NULL)->sort('number');
    if ($sprint === 'none') {
      $query->addCondition('sprint', NULL);
    }
    elseif ($sprint !== '') {
      $query->addCondition('sprint', (int) $sprint);
    }
    if ($search !== '') {
      // HU 00042 and 42 both refer to the existing immutable number.
      if (preg_match('/^(?:HU\s*#?\s*)?([0-9]+)$/iD', $search, $matches)) {
        $query->addCondition('number', ltrim($matches[1], '0') ?: '0');
      }
      else {
        $query->keys($search)->setFulltextFields(['title', 'description']);
        $query->setParseMode(\Drupal::service('plugin.manager.search_api.parse_mode')->createInstance('terms'));
      }
    }
    $ids = [];
    foreach ($query->execute()->getResultItems() as $item) {
      try { $node = $item->getOriginalObject()->getValue(); }
      catch (\Drupal\search_api\SearchApiException) { continue; }
      // Nodes can disappear while another administrator is deleting a HU.
      if ($node instanceof \Drupal\node\NodeInterface && $node->bundle() === 'hu' && $node->access('view', $this->currentUser())) {
        $ids[$node->id()] = $node->id();
      }
    }
    // Populate options from all HU, independent of the selected filter.
    $sprint_query = \Drupal::database()->select('node__field_hu_sprint', 's')->distinct();
    $sprints = array_map('intval', $sprint_query->fields('s', ['field_hu_sprint_value'])->condition('s.bundle', 'hu')->condition('s.deleted', 0)->orderBy('field_hu_sprint_value')->execute()->fetchCol());
    if ($sprint !== '' && $sprint !== 'none' && !in_array((int) $sprint, $sprints, TRUE)) {
      $sprints[] = (int) $sprint;
      sort($sprints, SORT_NUMERIC);
    }
    foreach ($storage->loadMultiple($ids) as $node) {
      $columns[WorkflowStatus::get($node)]['cards'][] = [
        'id' => $node->id(), 'revision' => $node->getRevisionId(),
        'number' => HuWorkflow::number($node), 'title' => $node->label(),
        'description' => $node->get('field_hu_description')->value,
        'sprint' => $node->get('field_hu_sprint')->isEmpty() ? NULL : (int) $node->get('field_hu_sprint')->value,
        'edit_url' => Url::fromRoute('nelkano_home.admin_hu_edit', ['hu' => $node->id()], ['query' => $filter_query])->toString(),
        'move_url' => Url::fromRoute('nelkano_home.admin_hu_move', ['hu' => $node->id()])->toString(),
        'delete_url' => Url::fromRoute('nelkano_home.admin_hu_delete', ['hu' => $node->id()])->toString(),
      ];
    }
    $results = [
      '#theme' => 'nelkano_backlog_cards',
      '#columns' => $columns,
      '#cache' => ['max-age' => 0],
    ];
    $create_url = Url::fromRoute('nelkano_home.admin_hu_add', [], ['query' => $filter_query])->toString();
    if (\Drupal::request()->query->get('_wrapper_format') === 'drupal_ajax') {
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('#nk-backlog-results', $results));
      $response->addCommand(new InvokeCommand('#nk-backlog-total', 'text', [count($ids)]));
      $response->addCommand(new InvokeCommand('.nk-hu-create', 'attr', ['href', $create_url]));
      $response->addCommand(new InvokeCommand('.nk-backlog-feedback', 'text', [count($ids) . ' historias de usuario.']));
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }
    $module_path = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    return [
      '#type' => 'container', '#attributes' => ['class' => ['nk-admin-config-form']],
      '#prefix' => $this->nelkanoAdminHeader($module_path) . '<div class="nk-admin-app nk-redesign-admin nk-admin-data"><div class="nk-admin-body">' . $this->nelkanoAdminSidebar('backlog') . '<div class="nk-admin-workspace"><section class="nk-admin-panel">',
      '#suffix' => '</section></div></div></div>',
      '#attached' => [
        'library' => ['nelkano_home/admin_forms', 'nelkano_home/backlog', 'nelkano_home/admin_data'],
        'drupalSettings' => ['nelkanoBacklog' => ['csrfToken' => \Drupal::service('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY)]],
      ],
      '#cache' => ['max-age' => 0],
      'board' => ['#theme' => 'nelkano_backlog', '#columns' => $columns, '#create_url' => $create_url, '#total' => count($ids), '#sprint' => $sprint, '#sprints' => $sprints, '#search' => $search, '#results' => $results, '#filter_url' => Url::fromRoute('nelkano_home.admin_backlog')->toString()],
    ];
  }

  public function delete(Request $request, int $hu): JsonResponse {
    try {
      if (strlen($request->getContent()) > 2048) { throw new \InvalidArgumentException('Solicitud demasiado grande.'); }
      $body = json_decode($request->getContent(), TRUE, 8, JSON_THROW_ON_ERROR);
      if (!is_array($body) || array_diff(array_keys($body), ['revision']) || !is_int($body['revision'] ?? NULL) || $body['revision'] < 1) {
        throw new \InvalidArgumentException('Revisión no válida.');
      }
      $response = new JsonResponse(HuWorkflow::delete($hu, $body['revision']));
    }
    catch (\InvalidArgumentException | \JsonException $error) { $response = new JsonResponse(['error' => $error->getMessage()], 400); }
    catch (HttpExceptionInterface $error) { $response = new JsonResponse(['error' => $error->getMessage()], $error->getStatusCode()); }
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  public function move(Request $request, int $hu): JsonResponse {
    try {
      if (strlen($request->getContent()) > 2048) {
        throw new \InvalidArgumentException('Solicitud demasiado grande.');
      }
      $body = json_decode($request->getContent(), TRUE, 8, JSON_THROW_ON_ERROR);
      if (!is_array($body) || array_diff(array_keys($body), ['status', 'revision']) || !is_string($body['status'] ?? NULL) || !is_int($body['revision'] ?? NULL) || $body['revision'] < 1) {
        throw new \InvalidArgumentException('Estado y revisión no válidos.');
      }
      $node = HuWorkflow::save($hu, $body['revision'], $body['status']);
      $response = new JsonResponse(['id' => (int) $node->id(), 'status' => WorkflowStatus::get($node), 'revision' => (int) $node->getRevisionId()]);
    }
    catch (\InvalidArgumentException | \JsonException $e) {
      $response = new JsonResponse(['error' => $e->getMessage()], 400);
    }
    catch (HttpExceptionInterface $e) {
      $response = new JsonResponse(['error' => $e->getMessage()], $e->getStatusCode());
    }
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }
}
