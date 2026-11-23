<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\comment\CommentInterface;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CommunityModeration {

  public const TABLE = 'nelkano_community_report';
  public const PERMISSION = 'moderate nelkano community';
  public const REASONS = ['spam' => 'Spam', 'harassment' => 'Acoso o amenazas',
    'impersonation' => 'Suplantación', 'inappropriate' => 'Contenido inapropiado', 'other' => 'Otro motivo'];
  public const KINDS = ['post' => 'Publicación', 'comment' => 'Comentario', 'user' => 'Usuario'];
  public const STATUSES = ['pending' => 'Pendiente', 'dismissed' => 'Archivada', 'hidden' => 'Contenido retirado', 'suspended' => 'Cuenta suspendida'];

  public static function schema(): array {
    $id = ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE];
    return [self::TABLE => [
      'description' => 'Reports from the Nelkano Community and their moderation decisions.',
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'reporter_uid' => $id, 'target_id' => $id, 'owner_uid' => $id,
        'kind' => ['type' => 'varchar', 'length' => 12, 'not null' => TRUE],
        'reason' => ['type' => 'varchar', 'length' => 20, 'not null' => TRUE],
        'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'pending'],
        'created' => $id, 'resolved' => $id + ['default' => 0], 'moderator_uid' => $id + ['default' => 0],
      ],
      'primary key' => ['id'],
      'unique keys' => ['reporter_target' => ['reporter_uid', 'kind', 'target_id']],
      'indexes' => ['queue' => ['status', 'created'], 'owner' => ['owner_uid'], 'moderator' => ['moderator_uid']],
    ]];
  }

  public static function target(string $kind, string $target): ?EntityInterface {
    $type = match ($kind) { 'post' => 'node', 'comment' => 'comment', 'user' => 'user', default => NULL };
    if (!$type || !\Drupal::entityTypeManager()->hasDefinition($type)) {
      return NULL;
    }
    $storage = \Drupal::entityTypeManager()->getStorage($type);
    if (preg_match('/^[1-9][0-9]{0,9}$/D', $target)) {
      $entity = $storage->load((int) $target);
    }
    elseif ($kind === 'comment' && preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $target)) {
      $matches = $storage->loadByProperties(['uuid' => $target]);
      $entity = reset($matches) ?: NULL;
    }
    else {
      return NULL;
    }
    return match ($kind) {
      'post' => $entity instanceof NodeInterface && $entity->bundle() === 'community_post' ? $entity : NULL,
      'comment' => $entity instanceof CommentInterface && $entity->bundle() === 'community_comment' &&
        $entity->getCommentedEntity()?->bundle() === 'community_post' ? $entity : NULL,
      'user' => $entity instanceof UserInterface && $entity->isActive() ? $entity : NULL,
    };
  }

  public static function reportable(AccountInterface $account, string $kind, string $target): EntityInterface {
    $entity = self::target($kind, $target);
    if (!$entity || !$account->isAuthenticated() || !$account->hasPermission('use nelkano app features')) {
      throw new HttpException(404, 'El contenido o perfil no está disponible.');
    }
    $owner = self::owner($entity);
    $parent = $entity instanceof CommentInterface ? $entity->getCommentedEntity() : NULL;
    if ($owner === (int) $account->id() || ($kind !== 'user' && (!$entity->access('view', $account) ||
      ($parent && !$parent->access('view', $account))))) {
      throw new HttpException(404, 'El contenido o perfil no está disponible.');
    }
    return $entity;
  }

  public static function owner(EntityInterface $entity): int {
    return $entity instanceof UserInterface ? (int) $entity->id() : (int) $entity->getOwnerId();
  }

  public static function submit(AccountInterface $account, string $kind, string $target, string $reason): void {
    if (!isset(self::REASONS[$reason])) {
      throw new HttpException(400, 'Elige un motivo válido.');
    }
    $entity = self::reportable($account, $kind, $target);
    $uid = (int) $account->id();
    $flood = \Drupal::service('flood');
    if (!$flood->isAllowed('nelkano_community_report', 12, 3600, (string) $uid)) {
      throw new HttpException(429, 'Has enviado varias denuncias. Inténtalo más tarde.');
    }
    try {
      \Drupal::database()->insert(self::TABLE)->fields([
        'reporter_uid' => $uid, 'kind' => $kind, 'target_id' => (int) $entity->id(),
        'owner_uid' => self::owner($entity), 'reason' => $reason, 'created' => \Drupal::time()->getRequestTime(),
      ])->execute();
      $flood->register('nelkano_community_report', 3600, (string) $uid);
    }
    catch (IntegrityConstraintViolationException $error) {
      $existing = \Drupal::database()->select(self::TABLE, 'r')->fields('r', ['id'])
        ->condition('reporter_uid', $uid)->condition('kind', $kind)->condition('target_id', (int) $entity->id())
        ->execute()->fetchField();
      if (!$existing) {
        throw $error;
      }
    }
  }

  public static function resolve(AccountInterface $moderator, int $id, string $decision): void {
    if (!$moderator->hasPermission(self::PERMISSION)) {
      throw new HttpException(403);
    }
    $db = \Drupal::database();
    $lock = \Drupal::lock();
    $name = "nelkano.community.report.$id";
    if (!$lock->acquire($name, 10)) {
      throw new HttpException(409, 'Otra persona está revisando esta denuncia.');
    }
    try {
      $transaction = $db->startTransaction();
      $row = $db->select(self::TABLE, 'r')->fields('r')->condition('id', $id)->execute()->fetchAssoc();
      if (!$row || $row['status'] !== 'pending') {
        throw new HttpException(409, 'La denuncia ya se ha revisado.');
      }
      if ($decision === 'hidden') {
        $entity = self::target($row['kind'], (string) $row['target_id']);
        if (!$entity instanceof NodeInterface && !$entity instanceof CommentInterface) {
          throw new HttpException(400, 'El contenido ya no está disponible.');
        }
        $entity->setUnpublished()->save();
      }
      elseif ($decision === 'suspended') {
        $account = \Drupal::entityTypeManager()->getStorage('user')->load((int) $row['owner_uid']);
        if (!$account instanceof UserInterface || !$account->isActive() || (int) $account->id() <= 1 ||
          (int) $account->id() === (int) $moderator->id() || $account->hasPermission('administer users') ||
          $account->hasPermission(self::PERMISSION)) {
          throw new HttpException(400, 'La cuenta no se puede suspender desde esta bandeja.');
        }
        $account->block()->save();
      }
      elseif ($decision !== 'dismissed') {
        throw new HttpException(400, 'Acción no válida.');
      }
      $db->update(self::TABLE)->fields(['status' => $decision, 'moderator_uid' => (int) $moderator->id(),
        'resolved' => \Drupal::time()->getRequestTime()])->condition('id', $id)->execute();
      unset($transaction);
    }
    catch (\Throwable $error) {
      if (isset($transaction)) $transaction->rollBack();
      unset($transaction);
      throw $error;
    }
    finally {
      $lock->release($name);
    }
  }

  public static function purge(int $uid): void {
    $db = \Drupal::database();
    if (!$db->schema()->tableExists(self::TABLE)) {
      return;
    }
    $query = $db->delete(self::TABLE);
    $query->condition($query->orConditionGroup()->condition('reporter_uid', $uid)->condition('owner_uid', $uid))->execute();
    $db->update(self::TABLE)->fields(['moderator_uid' => 0])->condition('moderator_uid', $uid)->execute();
  }

}
