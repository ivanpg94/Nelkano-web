<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\ReportApiCredentials;
use Drupal\nelkano_home\Service\ReportAttachment;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Versioned PC API. Every action authenticates before loading any report. */
final class ErrorReportApiController extends ControllerBase {

  public function listing(Request $request): Response {
    return $this->authorized($request, function (string $client) use ($request): Response {
      $after = $this->integerQuery($request, 'after_id', 0, 0, PHP_INT_MAX);
      $limit = $this->integerQuery($request, 'limit', 25, 1, 100);
      // PC credentials explicitly authorize access to unpublished reports.
      $ids = $this->entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)
        ->condition('type', 'nelkano_error_report')->condition('nid', $after, '>')
        ->condition('field_report_status', 'new')
        ->sort('nid', 'ASC')->range(0, $limit + 1)->execute();
      $has_more = count($ids) > $limit;
      $ids = array_slice(array_values($ids), 0, $limit);
      $items = [];
      foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($ids) as $node) {
        $items[] = [
          'id' => (int) $node->id(), 'uuid' => $node->uuid(), 'title' => $node->label(),
          'created' => $node->getCreatedTime(), 'changed' => $node->getChangedTime(),
          'status' => $node->get('field_report_status')->value,
          'system' => $node->get('field_report_system')->value,
          'detail_url' => Url::fromRoute('nelkano_home.report_api_detail', ['report' => $node->id()])->toString(),
        ];
      }
      return new JsonResponse(['api_version' => 1, 'items' => $items, 'next_after_id' => $ids ? (int) end($ids) : $after, 'has_more' => $has_more]);
    });
  }

  public function detail(Request $request, int $report): Response {
    return $this->authorized($request, function (string $client) use ($report): Response {
      $manifest = $this->manifest($this->loadReport($report));
      $receipt = \Drupal::keyValue('nelkano_report_api_receipts')->get($client . ':' . $manifest['uuid']);
      $manifest['receipt'] = $receipt && hash_equals($manifest['manifest_sha256'], $receipt['manifest_sha256']) ? $receipt : NULL;
      return new JsonResponse($manifest);
    });
  }

  public function download(Request $request, int $report, string $attachment): Response {
    return $this->authorized($request, function (string $client) use ($report, $attachment): Response {
      $node = $this->loadReport($report);
      $file = $this->attachment($node, $attachment);
      if ($file === NULL) {
        throw new NotFoundHttpException('Attachment not found.');
      }
      $field = $attachment === 'state' ? 'field_report_state_uri' : 'field_report_screenshot_uri';
      $response = new BinaryFileResponse(ReportAttachment::path((string) $node->get($field)->value));
      $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['filename']);
      $response->headers->set('Content-Type', $attachment === 'state' ? 'application/octet-stream' : 'image/png');
      $response->headers->set('X-Checksum-SHA256', $file['sha256']);
      return $response;
    });
  }

  public function receipt(Request $request, int $report): Response {
    return $this->authorized($request, function (string $client) use ($request, $report): Response {
      $body = $this->jsonBody($request);
      return $this->withReportLock($report, function () use ($client, $body, $report): Response {
        $node = $this->loadReport($report);
        $manifest = $this->manifest($node);
        $expected = ['manifest_sha256' => $manifest['manifest_sha256']];
        foreach ($manifest['attachments'] as $name => $file) {
          if ($file !== NULL) {
            $expected[$name . '_sha256'] = $file['sha256'];
          }
        }
        foreach ($expected as $key => $value) {
          if (!isset($body[$key]) || !is_string($body[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $body[$key])) {
            throw new BadRequestHttpException('Missing or invalid checksum: ' . $key);
          }
          if (!hash_equals($value, $body[$key])) {
            throw new ConflictHttpException('Report or attachment changed; download the current manifest and files again.');
          }
        }
        $store = \Drupal::keyValue('nelkano_report_api_receipts');
        $key = $client . ':' . $manifest['uuid'];
        $receipt = $store->get($key);
        $status = (string) $node->get('field_report_status')->value;
        // A lost response may be retried even after automation finishes. Never
        // regress a resolved/rejected report on a duplicate acknowledgement.
        $already_received = $receipt && hash_equals($manifest['manifest_sha256'], $receipt['manifest_sha256']);
        if ($status !== 'new' && !$already_received) {
          throw new ConflictHttpException('Report is no longer new.');
        }
        if ($status === 'new') {
          $this->saveStatus($node, 'in_progress', $client);
          $receipt = ['client' => $client, 'received_at' => time()] + $expected;
          $store->set($key, $receipt);
        }
        return new JsonResponse(['api_version' => 1, 'id' => $report, 'status' => $node->get('field_report_status')->value, 'receipt' => $receipt]);
      });
    });
  }

  public function updateStatus(Request $request, int $report): Response {
    return $this->authorized($request, function (string $client) use ($request, $report): Response {
      $body = $this->jsonBody($request);
      $status = $body['status'] ?? NULL;
      if (!is_string($status) || !in_array($status, ['new', 'in_progress', 'resolved', 'rejected'], TRUE) || array_diff(array_keys($body), ['status'])) {
        throw new BadRequestHttpException('Expected only status: new, in_progress, resolved or rejected.');
      }
      return $this->withReportLock($report, function () use ($client, $status, $report): Response {
        $node = $this->loadReport($report);
        $this->saveStatus($node, $status, $client);
        return new JsonResponse(['api_version' => 1, 'id' => $report, 'uuid' => $node->uuid(), 'status' => $status]);
      });
    });
  }

  private function saveStatus(NodeInterface $node, string $status, string $client): void {
    if ($node->get('field_report_status')->value !== $status) {
      $node->set('field_report_status', $status);
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Report API (' . $client . '): ' . $status);
      $node->setRevisionUserId(0);
      $node->setRevisionCreationTime(time());
      $node->setChangedTime(time());
      $node->save();
    }
  }

  /** Serialize API transitions; persist receipt and node state atomically. */
  private function withReportLock(int $report, callable $action): Response {
    $lock = \Drupal::lock();
    $key = 'nelkano_report_workflow:' . $report;
    if (!$lock->acquire($key, 300)) {
      throw new ConflictHttpException('Report busy; retry this request.');
    }
    $transaction = \Drupal::database()->startTransaction();
    try {
      $this->entityTypeManager()->getStorage('node')->resetCache([$report]);
      $response = $action();
      unset($transaction);
      return $response;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      $lock->release($key);
    }
  }

  private function jsonBody(Request $request): array {
    if (strlen($request->getContent()) > 4096) {
      throw new BadRequestHttpException('Request body too large.');
    }
    try {
      $body = json_decode($request->getContent(), TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new BadRequestHttpException('Invalid JSON.');
    }
    if (!is_array($body)) {
      throw new BadRequestHttpException('Expected a JSON object.');
    }
    return $body;
  }

  private function authorized(Request $request, callable $action): Response {
    // Neither Drupal cookies nor Android player tokens grant access here.
    $client = ReportApiCredentials::authenticate($request->headers->get('Authorization', ''));
    if ($client === NULL) {
      $response = new JsonResponse(['error' => 'invalid_or_expired_token'], 401);
      $response->headers->set('WWW-Authenticate', 'Bearer realm="nelkano-reports"');
    }
    else {
      try {
        $response = $action($client);
      }
      catch (HttpExceptionInterface $e) {
        $response = new JsonResponse(['error' => $e->getMessage()], $e->getStatusCode());
      }
    }
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('Vary', 'Authorization');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  private function loadReport(int $id): NodeInterface {
    $node = $this->entityTypeManager()->getStorage('node')->load($id);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'nelkano_error_report') {
      throw new NotFoundHttpException('Report not found.');
    }
    return $node;
  }

  private function manifest(NodeInterface $node): array {
    $data = [
      'api_version' => 1, 'id' => (int) $node->id(), 'uuid' => $node->uuid(),
      'revision_id' => (int) $node->getRevisionId(), 'title' => $node->label(),
      'steps' => (string) $node->get('body')->value,
      'created' => $node->getCreatedTime(), 'changed' => $node->getChangedTime(),
      'reporter' => ['uid' => (int) $node->getOwnerId(), 'email' => $node->getOwner()?->getEmail()],
      'metadata' => [],
    ];
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if (str_starts_with($name, 'field_report_') && !str_ends_with($name, '_uri')) {
        $value = $node->get($name)->value;
        $data['metadata'][substr($name, strlen('field_report_'))] = $value === NULL ? NULL : ($definition->getType() === 'integer' ? (int) $value : (string) $value);
      }
    }
    ksort($data['metadata']);
    $data['attachments'] = ['state' => $this->attachment($node, 'state'), 'screenshot' => $this->attachment($node, 'screenshot')];
    // Workflow updates do not change the downloaded report payload. Exclude
    // status and revision timestamps so receipt retries remain valid.
    $payload = $data;
    unset($payload['metadata']['status'], $payload['revision_id'], $payload['changed']);
    $data['manifest_sha256'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $data;
  }

  private function attachment(NodeInterface $node, string $kind): ?array {
    $uri = (string) $node->get($kind === 'state' ? 'field_report_state_uri' : 'field_report_screenshot_uri')->value;
    if ($uri === '') {
      return NULL;
    }
    $path = ReportAttachment::path($uri);
    // Check actual bytes, not potentially edited checksum metadata.
    $hash = hash_file('sha256', $path);
    if ($hash === FALSE) {
      throw new NotFoundHttpException('Attachment unreadable.');
    }
    return [
      'filename' => basename($path), 'size' => filesize($path), 'sha256' => $hash,
      'download_url' => Url::fromRoute('nelkano_home.report_api_download', ['report' => $node->id(), 'attachment' => $kind])->toString(),
    ];
  }

  private function integerQuery(Request $request, string $key, int $default, int $min, int $max): int {
    $raw = $request->query->all()[$key] ?? (string) $default;
    if (!is_string($raw) || !ctype_digit($raw) || filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === FALSE) {
      throw new BadRequestHttpException('Invalid parameter: ' . $key);
    }
    return (int) $raw;
  }

}
