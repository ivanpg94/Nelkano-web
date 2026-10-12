<?php

namespace Drupal\nelkano_modules\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\node\NodeInterface;

final class ModuleAccess {
  public function view(NodeInterface $module, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($module->bundle() === 'nelkano_module' && $module->access('view', $account))
      ->cachePerUser()->cachePerPermissions()->addCacheableDependency($module);
  }
  public function edit(NodeInterface $module, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(ModuleRegistry::canEdit($module, $account))
      ->cachePerUser()->cachePerPermissions()->addCacheableDependency($module);
  }
  public function release(NodeInterface $module, NodeInterface $release, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(ModuleRegistry::canEdit($module, $account)
      && $release->bundle() === 'nelkano_module_release'
      && (int) $release->get('field_release_module')->target_id === (int) $module->id())
      ->cachePerUser()->cachePerPermissions()->addCacheableDependency($module)->addCacheableDependency($release);
  }
}