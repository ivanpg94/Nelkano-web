<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\EventSubscriber;

use Drupal\nelkano_home\Service\ConnectionPrivacyRetention;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** An opt-in bounded maintenance opportunity after the response has been sent. */
final class ConnectionPrivacyRetentionSubscriber implements EventSubscriberInterface {
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => ['cleanup', -250]];
  }

  public function cleanup(TerminateEvent $event): void {
    if ($event->isMainRequest()) { ConnectionPrivacyRetention::runScheduled(); }
  }
}
