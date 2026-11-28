<?php

declare(strict_types=1);

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Session\AccountProxy;
use Drupal\nelkano_home\Controller\SpectatorInvitations;
use Drupal\nelkano_home\EventSubscriber\AccountMutationLockSubscriber;
use Drupal\nelkano_home\Service\AccountErasure;
use Drupal\user\Entity\User;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

if (getenv('NELKANO_SPECTATOR_QA') !== '1' || DRUPAL_ROOT !== '/opt/drupal/web' || getenv('DRUPAL_DB_HOST') !== 'database') {
  throw new RuntimeException('Run only in the local Nelkano Drupal test container.');
}

final class SpectatorAcceptanceFixture {
  use SpectatorInvitations;

  public function __construct(private readonly User $recipient, private readonly int $sender) {}

  private function accountFromRequest(Request $request): User {
    return $this->recipient;
  }

  private function denyWithout(User $account, string $permission): ?JsonResponse {
    return NULL;
  }

  private function areFriends(int $sender, int $recipient): bool {
    return $sender === $this->sender && $recipient === (int) $this->recipient->id();
  }

  private function activeStreamSessionRow(int $sender, string $session): ?array {
    return $sender === $this->sender && $session === 'ABC123DEF456' ? ['session_id' => $session] : NULL;
  }

  private function error(string $message, int $status): JsonResponse {
    return new JsonResponse(['ok' => FALSE, 'message' => $message], $status);
  }
}

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$db = \Drupal::database();
$transaction = $db->startTransaction();
try {
  $suffix = bin2hex(random_bytes(6));
  foreach (['sender', 'recipient'] as $role) {
    $users[$role] = User::create(['name' => "spectator-qa-$role-$suffix", 'mail' => "$role-$suffix@example.test", 'status' => 1]);
    $users[$role]->save();
  }
  $sender = (int) $users['sender']->id();
  $recipient = (int) $users['recipient']->id();
  $invite_id = bin2hex(random_bytes(24));
  $store = \Drupal::keyValueExpirable('nelkano_spectator');
  $store->setWithExpire("in:$recipient", [
    'id' => $invite_id,
    'senderUid' => $sender,
    'recipientUid' => $recipient,
    'sessionId' => 'ABC123DEF456',
    'state' => 'pending',
  ], 90);
  $store->setWithExpire("out:$sender", $recipient, 90);

  $request = static function (string $operation, ?string $id = NULL) use ($invite_id): Request {
    $request = Request::create('/api/nelkano/stream/invitations', 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/json',
    ], json_encode(['operation' => $operation, 'id' => $id ?? $invite_id], JSON_THROW_ON_ERROR));
    $request->attributes->set('_route', 'nelkano_home.api_spectator_invitations');
    return $request;
  };
  $controller = new SpectatorAcceptanceFixture($users['recipient'], $sender);
  $check($controller->spectatorInvitations($request('leave'))->getStatusCode() === 200, 'Cleanup request failed');
  $check($store->get("in:$recipient")['state'] === 'pending', 'Cleanup discarded a pending invitation');
  $check($controller->spectatorInvitations($request('accept', 'wrong-id'))->getStatusCode() === 410, 'A different invitation was accepted');

  $proxy = new AccountProxy(new EventDispatcher());
  $proxy->setAccount($users['recipient']);
  $kernel = \Drupal::service('http_kernel');
  $holder = new DatabaseLockBackend($db);
  $key = AccountErasure::lockKey($recipient);
  $check($holder->acquire($key, 25), 'Unable to hold fixture account lock');
  $release_at = microtime(TRUE) + 3.25;
  $waiting = new class ($db, $holder, $key, $release_at) extends DatabaseLockBackend {
    public function __construct(Connection $db, private readonly DatabaseLockBackend $holder, private readonly string $key, private readonly float $releaseAt) {
      parent::__construct($db);
    }
    public function acquire($name, $timeout = 30.0) {
      if ($name === $this->key && microtime(TRUE) >= $this->releaseAt) {
        $this->holder->release($name);
      }
      return parent::acquire($name, $timeout);
    }
  };
  $subscriber = new AccountMutationLockSubscriber($waiting, $proxy);
  $accept = $request('accept');
  $event = new ControllerEvent($kernel, [$controller, 'spectatorInvitations'], $accept, HttpKernelInterface::MAIN_REQUEST);
  $subscriber->lockMutations($event);
  try {
    $check($accept->attributes->get('_nelkano_mutation_locks') === [$key], 'Acceptance lost its account lock');
    $check($event->getController()($accept)->getStatusCode() === 200, 'Acceptance failed under brief contention');
  }
  finally {
    $subscriber->release(new FinishRequestEvent($kernel, $accept, HttpKernelInterface::MAIN_REQUEST));
  }
  $check($controller->spectatorInvitations($request('accept'))->getStatusCode() === 200, 'Repeating acceptance was not idempotent');
  $check($store->get("in:$recipient")['state'] === 'accepted', 'Accepted state was lost');
  $check($controller->spectatorInvitations($request('leave'))->getStatusCode() === 200, 'Viewer could not leave');
  $check($store->get("in:$recipient") === NULL && $store->get("out:$sender") === NULL, 'Accepted invitation was not cleaned up');

  $check($holder->acquire($key, 20), 'Unable to hold persistent fixture account lock');
  try {
    $subscriber = new AccountMutationLockSubscriber(new DatabaseLockBackend($db), $proxy);
    $accept = $request('accept');
    $event = new ControllerEvent($kernel, [$controller, 'spectatorInvitations'], $accept, HttpKernelInterface::MAIN_REQUEST);
    $subscriber->lockMutations($event);
    $response = $event->getController()($accept);
    $check($response instanceof JsonResponse && $response->getStatusCode() === 409, 'Persistent contention did not return JSON 409');
    $check(json_decode($response->getContent(), TRUE)['code'] === 'account_conflict', 'Persistent contention did not report account_conflict');
  }
  finally {
    $holder->release($key);
  }
  echo "PASS: deferred acceptance, persistent conflict, pending cleanup, retry and successful leave.\n";
}
finally {
  $transaction->rollBack();
}
