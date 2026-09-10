<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** All backlog writes use the same lock and optimistic revision check. */
final class HuWorkflow {
  public const PERMISSION = 'administer nelkano backlog';

  public static function number(NodeInterface $node): string {
    return sprintf('%05d', (int) $node->get('field_hu_number')->value);
  }

  public static function save(?int $id, ?int $revision, string $status, ?string $title = NULL, ?string $description = NULL, array $fields = []): NodeInterface {
    if (!\Drupal::currentUser()->hasPermission(self::PERMISSION)) {
      throw new AccessDeniedHttpException();
    }
    if (!in_array($status, WorkflowStatus::HU_CODES, TRUE)) {
      throw new \InvalidArgumentException('Estado no válido para una HU.');
    }
    // Omission preserves the sprint during a card move; explicit NULL clears it.
    if (array_diff(array_keys($fields), ['sprint']) || (isset($fields['sprint']) && (!is_int($fields['sprint']) || $fields['sprint'] < -2147483648 || $fields['sprint'] > 2147483647))) {
      throw new \InvalidArgumentException('Sprint debe ser un número entero de 32 bits o quedar vacío.');
    }
    if (($title !== NULL && (trim($title) === '' || mb_strlen($title) > 255)) || ($description !== NULL && (trim($description) === '' || mb_strlen($description) > 20000))) {
      throw new \InvalidArgumentException('Completa el título y la descripción (máximo 255 y 20000 caracteres).');
    }
    $key = 'nelkano_hu:' . ($id ?? 'create');
    $lock = \Drupal::lock();
    if (!$lock->acquire($key, 60)) {
      throw new ConflictHttpException('La HU se está guardando. Vuelve a intentarlo.');
    }
    $transaction = \Drupal::database()->startTransaction();
    try {
      if ($id !== NULL) {
        \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
        $node = Node::load($id);
        if (!$node || $node->bundle() !== 'hu') {
          throw new NotFoundHttpException('HU no encontrada.');
        }
        if ((int) $node->getRevisionId() !== $revision) {
          throw new ConflictHttpException('Otra persona ha modificado esta HU. Recarga la página antes de continuar.');
        }
        $current_sprint = $node->get('field_hu_sprint')->isEmpty() ? NULL : (int) $node->get('field_hu_sprint')->value;
        if (WorkflowStatus::get($node) === $status && ($title === NULL || $title === $node->label()) && ($description === NULL || $description === $node->get('field_hu_description')->value) && (!array_key_exists('sprint', $fields) || $fields['sprint'] === $current_sprint)) {
          return $node;
        }
      }
      else {
        if ($title === NULL || $description === NULL) {
          throw new \InvalidArgumentException('Completa el título y la descripción.');
        }
        $node = Node::create(['type' => 'hu', 'uid' => \Drupal::currentUser()->id(), 'status' => 0]);
      }
      WorkflowStatus::set($node, $status);
      if ($title !== NULL) {
        $node->setTitle($title);
      }
      if ($description !== NULL) {
        $node->set('field_hu_description', $description);
      }
      if (array_key_exists('sprint', $fields)) {
        $node->set('field_hu_sprint', $fields['sprint']);
      }
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) \Drupal::currentUser()->id());
      $node->setRevisionCreationTime(time());
      $node->setRevisionLogMessage('Backlog HU: ' . $status);
      $node->setChangedTime(time());
      $node->save();
      unset($transaction);
      return $node;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      // Commit even for idempotent writes before releasing the lock.
      unset($transaction);
      $lock->release($key);
    }
  }
}
