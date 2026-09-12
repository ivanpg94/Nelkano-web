<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Report deletion with attachment ownership checks and bounded batch requests. */
final class ReportDeletion {
  public const PERMISSION = 'delete nelkano error reports';
  private const ATTACHMENTS = ['field_report_state_uri', 'field_report_screenshot_uri'];

  public static function requirePermission(AccountInterface $account): void {
    if (!$account->hasPermission('administer nelkano error reports') || !$account->hasPermission(self::PERMISSION)) {
      throw new AccessDeniedHttpException();
    }
  }

  public static function idsForStatus(string $status): array {
    self::requirePermission(\Drupal::currentUser());
    if (!in_array($status, ['resolved', 'rejected'], TRUE)) {
      throw new \InvalidArgumentException('Estado de limpieza no válido.');
    }
    return array_values(array_map('intval', \Drupal::entityTypeManager()->getStorage('node')
      ->getQuery()->accessCheck(FALSE)->condition('type', 'nelkano_error_report')
      ->condition(WorkflowStatus::FIELD . '.target_id', WorkflowStatus::termId($status))
      ->sort('nid')->execute()));
  }

  /** Snapshot all matching IDs; each item is checked again under the API lock. */
  public static function start(array $ids, ?string $status = NULL): void {
    self::requirePermission(\Drupal::currentUser());
    if (!$ids || ($status === NULL && count($ids) > ReportBulkUpdater::MAX_REPORTS)) {
      throw new \InvalidArgumentException('Selecciona entre 1 y 50 reportes.');
    }
    foreach ($ids as $id) {
      if (!is_int($id) || $id < 1) {
        throw new \InvalidArgumentException('Selección de reportes no válida.');
      }
    }
    $operations = [];
    foreach (array_chunk(array_values(array_unique($ids)), 10) as $chunk) {
      $operations[] = [[self::class, 'process'], [$chunk, $status]];
    }
    batch_set([
      'title' => t('Eliminando reportes y archivos'),
      'operations' => $operations,
      'finished' => [self::class, 'finished'],
    ]);
  }

  public static function process(array $ids, ?string $status, array &$context): void {
    foreach ($ids as $id) {
      try {
        $key = self::deleteOne($id, \Drupal::currentUser(), $status) ? 'deleted' : 'skipped';
      }
      catch (\Throwable $error) {
        $key = 'failed';
        \Drupal::logger('nelkano_home')->error('No se pudo eliminar el reporte @id: @message', ['@id' => $id, '@message' => $error->getMessage()]);
      }
      $context['results'][$key] = ($context['results'][$key] ?? 0) + 1;
    }
  }

  public static function finished(bool $success, array $results, array $operations): void {
    \Drupal::messenger()->addStatus(t('Reportes eliminados con sus archivos: @count.', ['@count' => $results['deleted'] ?? 0]));
    if (!empty($results['skipped'])) {
      \Drupal::messenger()->addStatus(t('Omitidos porque ya no existen o cambiaron de estado: @count.', ['@count' => $results['skipped']]));
    }
    if (!$success || !empty($results['failed'])) {
      \Drupal::messenger()->addError(t('No se pudieron eliminar algunos reportes. Consulta el registro de errores y vuelve a intentarlo.'));
    }
  }

  public static function deleteOne(int $id, AccountInterface $account, ?string $expected_status = NULL): bool {
    self::requirePermission($account);
    if ($expected_status !== NULL && !in_array($expected_status, ['resolved', 'rejected'], TRUE)) {
      throw new \InvalidArgumentException('Estado de limpieza no válido.');
    }
    $lock = \Drupal::lock();
    $key = 'nelkano_report_workflow:' . $id;
    if (!$lock->acquire($key, 300)) {
      throw new \RuntimeException('El reporte está siendo modificado.');
    }
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $storage->resetCache([$id]);
      $node = $storage->load($id);
      if (!$node) { return FALSE; }
      if (!$node instanceof NodeInterface || $node->bundle() !== 'nelkano_error_report') {
        throw new \InvalidArgumentException('Solo se pueden eliminar reportes.');
      }
      if ($expected_status !== NULL && (int) $node->get(WorkflowStatus::FIELD)->target_id !== WorkflowStatus::termId($expected_status)) {
        return FALSE;
      }
      // Preflight all current and historical directories before removing anything.
      $directories = self::directories($node);
      $fs = \Drupal::service('file_system');
      foreach ($directories as $path) {
        if (is_dir($path) && !$fs->deleteRecursive($path)) {
          throw new \RuntimeException('No se pudieron eliminar los archivos del reporte.');
        }
      }
      $transaction = \Drupal::database()->startTransaction();
      try {
        $uuid = $node->uuid();
        $node->delete();
        $db = \Drupal::database();
        $db->delete('key_value')->condition('collection', 'nelkano_report_api_receipts')
          ->condition('name', '%:' . $db->escapeLike($uuid), 'LIKE')->execute();
        unset($transaction);
      }
      catch (\Throwable $error) {
        $transaction->rollBack();
        throw $error;
      }
      return TRUE;
    }
    finally {
      $lock->release($key);
    }
  }

  /** Only dedicated report directories, never paths outside private report storage. */
  private static function directories(NodeInterface $node): array {
    $db = \Drupal::database();
    $uris = [];
    foreach (self::ATTACHMENTS as $field) {
      foreach (['node__', 'node_revision__'] as $prefix) {
        $uris = array_merge($uris, $db->select($prefix . $field, 'a')->fields('a', [$field . '_value'])
          ->condition('entity_id', $node->id())->execute()->fetchCol());
      }
    }
    $directory_uris = [];
    foreach (array_unique(array_filter($uris)) as $uri) {
      if (!preg_match('@^(private://nelkano-error-reports/[a-f0-9-]{36})/[^/\\\\]+$@D', $uri, $matches)) {
        throw new \RuntimeException('Ruta de adjunto no válida; el reporte se conserva.');
      }
      $directory_uris[$matches[1]] = $matches[1];
    }
    $fs = \Drupal::service('file_system');
    $root = realpath($fs->realpath('private://nelkano-error-reports') ?: '');
    $paths = [];
    foreach ($directory_uris as $uri) {
      // Sharing a directory must never delete another report's attachments.
      foreach (self::ATTACHMENTS as $field) {
        foreach (['node__', 'node_revision__'] as $prefix) {
          $shared = $db->select($prefix . $field, 'a')->condition('entity_id', $node->id(), '<>')
            ->condition($field . '_value', $db->escapeLike($uri . '/') . '%', 'LIKE')->countQuery()->execute()->fetchField();
          if ($shared) {
            throw new \RuntimeException('Los archivos están compartidos con otro reporte; no se ha eliminado ninguno.');
          }
        }
      }
      $candidate = $fs->realpath($uri);
      if (!$candidate) {
        throw new \RuntimeException('No se puede resolver la carpeta del reporte.');
      }
      if (is_link($candidate)) {
        throw new \RuntimeException('No se eliminan enlaces simbólicos.');
      }
      if (!file_exists($candidate)) { continue; }
      $path = realpath($candidate);
      if (!$root || !$path || dirname($path) !== $root || !is_dir($path)) {
        throw new \RuntimeException('La carpeta no pertenece al almacenamiento de reportes.');
      }
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
      foreach ($iterator as $entry) {
        if ($entry->isLink()) {
          throw new \RuntimeException('La carpeta contiene un enlace simbólico.');
        }
      }
      $paths[] = $path;
    }
    return $paths;
  }
}
