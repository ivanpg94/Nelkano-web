<?php

namespace Drupal\nelkano_modules\EventSubscriber;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\nelkano_modules\Controller\ModuleController;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ModuleNodePageSubscriber implements EventSubscriberInterface {
  public function __construct(private readonly ClassResolverInterface $resolver) {}
  public static function getSubscribedEvents(): array { return [KernelEvents::CONTROLLER => 'onController']; }
  public function onController(ControllerEvent $event): void {
    $request = $event->getRequest();
    $node = $request->attributes->get('node');
    if ($request->attributes->get('_route') === 'entity.node.canonical'
      && $node instanceof NodeInterface && $node->bundle() === 'nelkano_module') {
      $event->setController([$this->resolver->getInstanceFromDefinition(ModuleController::class), 'canonical']);
    }
  }
}