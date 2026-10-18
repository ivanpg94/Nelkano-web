<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\BacklogApiCredentials;
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/** Private HU API; browser sessions and credentials for other APIs are ignored. */
final class BacklogApiController extends ControllerBase {
  private const FIELDS = ['title', 'description', 'status', 'sprint'];

  public function listing(Request $request): Response {
    return $this->authorized($request, function () use ($request): Response {
      $query = $request->query->all();
      $after = $this->integer($query['after_id'] ?? '0', 0, PHP_INT_MAX);
      $limit = $this->integer($query['limit'] ?? '25', 1, 100);
      $storage = $this->entityTypeManager()->getStorage('node');
      // The dedicated credential grants access to private HU, never other bundles.
      $select = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'hu')
        ->condition('nid', $after, '>')->sort('nid')->range(0, $limit + 1);
      if (array_key_exists('sprint', $query)) {
        if ($query['sprint'] === 'none') {
          $select->notExists('field_hu_sprint');
        }
        else {
          $select->condition('field_hu_sprint', $this->integer($query['sprint'], -2147483648, 2147483647));
        }
      }
      if (array_key_exists('status', $query)) {
        if (!is_string($query['status']) || !in_array($query['status'], WorkflowStatus::HU_CODES, TRUE)) {
          throw new BadRequestHttpException('Invalid HU status.');
        }
        $select->condition(WorkflowStatus::FIELD . '.target_id', WorkflowStatus::termId($query['status']));
      }
      $ids = array_values($select->execute());
      $more = count($ids) > $limit;
      $ids = array_slice($ids, 0, $limit);
      $items = [];
      foreach ($storage->loadMultiple($ids) as $node) {
        if ($node->access('view', $this->currentUser())) {
          $items[] = $this->serialize($node);
        }
      }
      return new JsonResponse([
        'api_version' => 1, 'capabilities' => ['create' => TRUE, 'edit' => TRUE, 'sprint' => TRUE, 'conditional_update' => TRUE],
        'statuses' => WorkflowStatus::options(TRUE), 'items' => $items,
        'next_after_id' => $ids ? (int) end($ids) : $after, 'has_more' => $more,
      ]);
    });
  }

  public function detail(Request $request, int $hu): Response {
    return $this->authorized($request, fn(): Response => $this->nodeResponse($this->loadHu($hu)));
  }

  public function createHu(Request $request): Response {
    return $this->authorized($request, function () use ($request): Response {
      $body = $this->body($request);
      if (!isset($body['title'], $body['description'])) {
        throw new BadRequestHttpException('Title and description are required.');
      }
      $node = HuWorkflow::save(NULL, NULL, $body['status'] ?? 'new', $body['title'], $body['description'], ['sprint' => $body['sprint'] ?? NULL]);
      $response = $this->nodeResponse($node, 201);
      $response->headers->set('Location', Url::fromRoute('nelkano_home.backlog_api_detail', ['hu' => $node->id()])->toString());
      return $response;
    });
  }

  public function updateHu(Request $request, int $hu): Response {
    return $this->authorized($request, function () use ($request, $hu): Response {
      $body = $this->body($request);
      $match = $request->headers->get('If-Match');
      if ($match === NULL) {
        throw new HttpException(428, 'If-Match with the current revision ETag is required.');
      }
      if (!preg_match('/^"([1-9][0-9]*)"$/D', $match, $parts)) {
        throw new BadRequestHttpException('If-Match must contain one quoted revision ID.');
      }
      $revision = $this->integer($parts[1], 1, PHP_INT_MAX);
      $node = $this->loadHu($hu);
      if ((int) $node->getRevisionId() !== $revision) {
        throw new PreconditionFailedHttpException('HU changed; retrieve it again before updating.');
      }
      try {
        $node = HuWorkflow::save($hu, $revision, $body['status'] ?? WorkflowStatus::get($node), $body['title'] ?? NULL, $body['description'] ?? NULL,
          array_key_exists('sprint', $body) ? ['sprint' => $body['sprint']] : []);
      }
      catch (ConflictHttpException $error) {
        // A browser or another API client may have saved after our initial read.
        if ((int) $this->loadHu($hu)->getRevisionId() !== $revision) {
          throw new PreconditionFailedHttpException('HU changed; retrieve it again before updating.');
        }
        throw $error;
      }
      return $this->nodeResponse($node);
    });
  }

  private function body(Request $request): array {
    if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0])) !== 'application/json') {
      throw new UnsupportedMediaTypeHttpException('Content-Type must be application/json.');
    }
    // 20000 Unicode characters can require 240000 bytes when escaped in JSON.
    if (strlen($request->getContent()) > 262144) {
      throw new HttpException(413, 'Request body too large.');
    }
    try {
      $object = json_decode($request->getContent(), FALSE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new BadRequestHttpException('Invalid JSON.');
    }
    if (!$object instanceof \stdClass) {
      throw new BadRequestHttpException('Expected a JSON object.');
    }
    $body = (array) $object;
    if (!$body || array_diff(array_keys($body), self::FIELDS)) {
      throw new BadRequestHttpException('Supply only title, description, status or sprint.');
    }
    foreach (['title' => 255, 'description' => 20000] as $field => $max) {
      if (array_key_exists($field, $body) && (!is_string($body[$field]) || trim($body[$field]) === '' || mb_strlen($body[$field]) > $max)) {
        throw new BadRequestHttpException('Invalid ' . $field . '; maximum ' . $max . ' characters.');
      }
    }
    if (array_key_exists('status', $body) && (!is_string($body['status']) || !in_array($body['status'], WorkflowStatus::HU_CODES, TRUE))) {
      throw new BadRequestHttpException('Invalid HU status.');
    }
    if (isset($body['sprint']) && (!is_int($body['sprint']) || $body['sprint'] < -2147483648 || $body['sprint'] > 2147483647)) {
      throw new BadRequestHttpException('Sprint must be a 32-bit integer or null.');
    }
    return $body;
  }

  private function integer(mixed $value, int $min, int $max): int {
    if (!is_string($value) || !preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === FALSE) {
      throw new BadRequestHttpException('Invalid integer parameter.');
    }
    return (int) $value;
  }

  private function loadHu(int $id): NodeInterface {
    $storage = $this->entityTypeManager()->getStorage('node');
    $storage->resetCache([$id]);
    $node = $storage->load($id);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'hu' || !$node->access('view', $this->currentUser())) {
      throw new NotFoundHttpException('HU not found.');
    }
    return $node;
  }

  private function serialize(NodeInterface $node): array {
    return [
      'api_version' => 1, 'id' => (int) $node->id(), 'uuid' => $node->uuid(),
      'number' => HuWorkflow::number($node), 'revision_id' => (int) $node->getRevisionId(),
      'title' => $node->label(), 'description' => (string) $node->get('field_hu_description')->value,
      'status' => WorkflowStatus::get($node),
      'sprint' => $node->get('field_hu_sprint')->isEmpty() ? NULL : (int) $node->get('field_hu_sprint')->value,
      'created' => (int) $node->getCreatedTime(), 'changed' => (int) $node->getChangedTime(),
    ];
  }

  private function nodeResponse(NodeInterface $node, int $status = 200): JsonResponse {
    $response = new JsonResponse($this->serialize($node), $status);
    $response->setEtag((string) $node->getRevisionId());
    return $response;
  }

  private function authorized(Request $request, callable $action): Response {
    $account = BacklogApiCredentials::authenticate($request->headers->get('Authorization', ''));
    if ($account === NULL) {
      $response = new JsonResponse(['error' => 'invalid_or_expired_token'], 401);
      $response->headers->set('WWW-Authenticate', 'Bearer realm="nelkano-backlog"');
    }
    else {
      // Workflow permission checks and revision authorship use the token's account.
      $switcher = \Drupal::service('account_switcher');
      $switcher->switchTo($account);
      try {
        $response = $action();
      }
      catch (HttpExceptionInterface $error) {
        $response = new JsonResponse(['error' => $error->getMessage()], $error->getStatusCode());
      }
      catch (\InvalidArgumentException $error) {
        $response = new JsonResponse(['error' => $error->getMessage()], 400);
      }
      finally {
        $switcher->switchBack();
      }
    }
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('Vary', 'Authorization');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }
}
