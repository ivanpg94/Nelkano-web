<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\nelkano_home\Service\CommunityBlocks;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/** Stops signalling for direct chat between blocked accounts. */
final class CommunitySignalAccessSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly AccountInterface $account) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => 'checkSignal'];
  }

  public function checkSignal(ControllerEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') === 'eca_endpoint.endpoint' &&
        preg_match('~^/eca/community-signal/([1-9][0-9]{0,9})$~D', rawurldecode($request->getPathInfo()), $peer) &&
        CommunityBlocks::between((int) $this->account->id(), (int) $peer[1])) {
      throw new AccessDeniedHttpException();
    }
  }

}
