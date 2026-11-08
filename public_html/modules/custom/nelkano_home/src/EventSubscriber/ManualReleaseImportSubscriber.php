<?php
namespace Drupal\nelkano_home\EventSubscriber;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Administrator-managed downloads and SDK visibility are not source defaults. */
final class ManualReleaseImportSubscriber implements EventSubscriberInterface {
  public function __construct(private readonly StorageInterface $activeStorage) {}
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::STORAGE_TRANSFORM_IMPORT => 'preserve'];
  }
  public function preserve(StorageTransformEvent $event): void {
    $storage = $event->getStorage();
    foreach (['nelkano_home.docs'=>'releases_items','nelkano_home.settings'=>'extensions_sdk_enabled'] as $name=>$field) {
      $incoming=$storage->read($name); $active=$this->activeStorage->read($name);
      if (!is_array($incoming) || !is_array($active)) continue;
      foreach (['es','en'] as $language) {
        $exists=false; $value=NestedArray::getValue($active,[$language,$field],$exists);
        if ($exists) NestedArray::setValue($incoming,[$language,$field],$value);
      }
      $storage->write($name,$incoming);
    }
    $name='nelkano_home.app_updates'; $active=$this->activeStorage->read($name);
    if (is_array($storage->read($name)) && is_array($active)) $storage->write($name,$active);
  }
}
