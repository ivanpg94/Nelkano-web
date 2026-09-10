<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\nelkano_home\Form\AdminFormUiTrait;
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

  public function board(): array {
    $sprint = self::sprintFilter(\Drupal::request());
    $filter_query = $sprint === '' ? [] : ['sprint' => $sprint];
    $columns = [];
    foreach (WorkflowStatus::options(TRUE) as $code => $label) {
      $columns[$code] = ['code' => $code, 'label' => $label, 'cards' => []];
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    // Route permission grants access to the entire private backlog.
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'hu')->sort('field_hu_number');
    if ($sprint === 'none') {
      $query->notExists('field_hu_sprint');
    }
    elseif ($sprint !== '') {
      $query->condition('field_hu_sprint', (int) $sprint);
    }
    $ids = $query->execute();
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
      ];
    }
    $module_path = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    return [
      '#type' => 'container', '#attributes' => ['class' => ['nk-admin-config-form']],
      '#prefix' => $this->nelkanoAdminHeader($module_path) . '<div class="nk-admin-app"><div class="nk-admin-body">' . $this->nelkanoAdminSidebar('backlog') . '<div class="nk-admin-workspace"><section class="nk-admin-panel">',
      '#suffix' => '</section></div></div></div>',
      '#attached' => [
        'library' => ['nelkano_home/admin_forms', 'nelkano_home/backlog'],
        'drupalSettings' => ['nelkanoBacklog' => ['csrfToken' => \Drupal::service('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY)]],
      ],
      '#cache' => ['max-age' => 0],
      'board' => ['#theme' => 'nelkano_backlog', '#columns' => $columns, '#create_url' => Url::fromRoute('nelkano_home.admin_hu_add', [], ['query' => $filter_query])->toString(), '#total' => count($ids), '#sprint' => $sprint, '#sprints' => $sprints, '#filter_url' => Url::fromRoute('nelkano_home.admin_backlog')->toString()],
    ];
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
