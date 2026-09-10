<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\nelkano_home\Service\WorkflowStatus;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Applies a bounded administrative selection atomically, sharing API locks. */
final class ReportBulkUpdater {

  public const PERMISSION = 'change nelkano error report status';
  public const MAX_REPORTS = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
  ) {}

  public function update(array $ids, string $status, ?string $observations, AccountInterface $account): int {
    if (!$account->hasPermission('administer nelkano error reports') || !$account->hasPermission(self::PERMISSION)) {
      throw new AccessDeniedHttpException();
    }
    if (!$ids || count($ids) > self::MAX_REPORTS) {
      throw new \InvalidArgumentException('Selecciona entre 1 y 50 reportes distintos.');
    }
    foreach ($ids as $id) {
      if (!is_int($id) || $id <= 0) {
        throw new \InvalidArgumentException('La selección de reportes no es válida.');
      }
    }
    if (count(array_unique($ids)) !== count($ids)) {
      throw new \InvalidArgumentException('La selección contiene reportes duplicados.');
    }
    $body = ['status' => $status];
    if ($observations !== NULL) {
      $body['observations'] = $observations;
    }
    ReportWorkflow::validateUpdate($body);
    sort($ids, SORT_NUMERIC);
    $storage = $this->entityTypeManager->getStorage('node');
    $acquired = [];
    $transaction = NULL;
    try {
      foreach ($ids as $id) {
        $key = 'nelkano_report_workflow:' . $id;
        if (!$this->lock->acquire($key, 300)) {
          throw new \RuntimeException('Un reporte está siendo actualizado. No se ha cambiado ninguno; vuelve a intentarlo.');
        }
        $acquired[] = $key;
      }
      $transaction = $this->database->startTransaction();
      $storage->resetCache($ids);
      $nodes = $storage->loadMultiple($ids);
      // Validate the entire selection before the first write.
      foreach ($ids as $id) {
        $node = $nodes[$id] ?? NULL;
        if (!$node instanceof NodeInterface || $node->bundle() !== 'nelkano_error_report') {
          throw new \RuntimeException('Un reporte ya no existe o la selección no es válida. No se ha cambiado ninguno.');
        }
      }
      $changed = 0;
      foreach ($ids as $id) {
        $node = $nodes[$id];
        $notes_changed = $observations !== NULL && (string) $node->get('field_report_observations')->value !== $observations;
        if ((string) WorkflowStatus::get($node) === $status && !$notes_changed) {
          continue;
        }
        WorkflowStatus::set($node, $status);
        if ($observations !== NULL) {
          $node->set('field_report_observations', $observations);
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage('Cambio masivo de reportes: ' . $status);
        $node->setRevisionUserId((int) $account->id());
        $node->setRevisionCreationTime(time());
        $node->setChangedTime(time());
        $node->save();
        $changed++;
      }
      unset($transaction);
      return $changed;
    }
    catch (\Throwable $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $storage->resetCache($ids);
      throw $e;
    }
    finally {
      foreach (array_reverse($acquired) as $key) {
        $this->lock->release($key);
      }
    }
  }

}
