<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/** Server-owned audience: no role or access decision is accepted from the client. */
final class ModuleAudience {
  public static function access(NodeInterface $module): string {
    $value = $module->hasField('field_module_access') ? (string) $module->get('field_module_access')->value : '';
    return $value === '' || $value === 'public' ? 'public' : 'tester';
  }

  public static function isTester(AccountInterface $account): bool {
    return $account->isAuthenticated() && in_array('tester', $account->getRoles(), TRUE);
  }

  public static function canManageTester(AccountInterface $account): bool {
    return $account->isAuthenticated() && $account->hasPermission('administer nelkano modules');
  }

  public static function canRead(NodeInterface $module, AccountInterface $account): bool {
    return self::access($module) === 'public' || self::isTester($account) || self::canManageTester($account);
  }

  /** An explicit invalid Bearer must never silently become a cookie/anonymous request. */
  public static function account(): AccountInterface {
    $request = \Drupal::request();
    if ($request->attributes->has('_nelkano_module_account')) {
      return $request->attributes->get('_nelkano_module_account');
    }
    $header = trim((string) $request->headers->get('Authorization', ''));
    if ($header !== '') {
      $account = preg_match('/^Bearer\s+(nkat_[a-f0-9]{64})$/D', $header, $matches)
        ? AppSessions::accountFromAccessToken($matches[1]) : NULL;
      if (!$account instanceof User) { throw new UnauthorizedHttpException('Bearer realm="nelkano-modules"', 'invalid_token'); }
    }
    else {
      $current = \Drupal::currentUser();
      $account = $current->isAuthenticated() ? User::load($current->id()) : $current;
      if (!$account || ($account instanceof User && !$account->isActive())) {
        $account = new \Drupal\Core\Session\AnonymousUserSession();
      }
    }
    $request->attributes->set('_nelkano_module_account', $account);
    return $account;
  }

  /** Negative indexed condition includes legacy records whose field is still empty. */
  public static function filterQuery($query, AccountInterface $account): void {
    if (!self::isTester($account) && !self::canManageTester($account)) {
      $group = $query->createConditionGroup('OR')->addCondition('module_access', 'public')->addCondition('module_access', NULL);
      $query->addConditionGroup($group);
    }
  }
}
