<?php

namespace Drupal\nelkano_home\EventSubscriber;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\nelkano_home\Controller\HomeController;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Apply public chrome after Drupal has checked the canonical node route access. */
final class SdkBookControllerSubscriber implements EventSubscriberInterface {
  public function __construct(private readonly ClassResolverInterface $classResolver) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => 'onController'];
  }

  public function onController(ControllerEvent $event): void {
    $request = $event->getRequest();
    $node = $request->attributes->get('node');
    if ($request->attributes->get('_route') === 'entity.node.canonical'
      && $node instanceof NodeInterface && $node->bundle() === 'sdk_page'
      && $node instanceof \Drupal\book\BookInterface && !empty($node->getBook()['bid'])) {
      $event->setController([$this->classResolver->getInstanceFromDefinition(HomeController::class), 'sdkBookPage']);
    }
  }
}
