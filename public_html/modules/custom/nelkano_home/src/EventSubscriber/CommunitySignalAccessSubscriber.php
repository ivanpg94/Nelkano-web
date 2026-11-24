<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\nelkano_home\Service\CommunityBlocks;
use Drupal\nelkano_home\Service\CommunityRules;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/** Enforces Community permissions before a write reaches its controller. */
final class CommunitySignalAccessSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly AccountInterface $account) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => 'checkSignal'];
  }

  public function checkSignal(ControllerEvent $event): void {
    $request = $event->getRequest();
    $path = rtrim(rawurldecode($request->getPathInfo()), '/');
    $route = (string) $request->attributes->get('_route');
    $method = $request->getMethod();
    $uid = (int) $this->account->id();
    if ($route === 'eca_endpoint.endpoint' && preg_match('~^/eca/community-signal/([1-9][0-9]{0,9})$~D', $path, $peer)) {
      if (CommunityBlocks::between($uid, (int) $peer[1])) {
        throw new AccessDeniedHttpException();
      }
      if ($method === 'POST') {
        CommunityRules::requireAcceptance($uid);
      }
    }
    if (in_array($method, ['POST', 'PATCH'], TRUE) &&
        (preg_match('~^/jsonapi/(?:node/community_post|comment/community_comment)(?:/|$)~D', $path) ||
        preg_match('/^jsonapi\.(?:node--community_post|comment--community_comment)\./D', $route))) {
      CommunityRules::requireAcceptance($uid);
    }
    if ($method !== 'POST') {
      return;
    }
    if ($path === '/api/nelkano/community/groups/mailbox') {
      $body = json_decode($request->getContent(), TRUE);
      if (in_array($body['action'] ?? NULL, ['send', 'direct-send', 'direct-keys'], TRUE)) {
        CommunityRules::requireAcceptance($uid);
      }
    }
    elseif ($path === '/api/nelkano/community/groups') {
      $body = json_decode($request->getContent(), TRUE);
      if (in_array($body['action'] ?? NULL, ['create', 'rename'], TRUE)) {
        CommunityRules::requireAcceptance($uid);
      }
    }
  }

}
