<?php

declare(strict_types=1);

use Drupal\nelkano_home\Service\AccountErasure;
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

if (getenv('NELKANO_FRIENDS_QA') !== '1' || DRUPAL_ROOT !== '/opt/drupal/web' || getenv('DRUPAL_DB_HOST') !== 'database') {
  throw new RuntimeException('Run only in the local Nelkano Drupal test container.');
}

$db = \Drupal::database();
$transaction = $db->startTransaction();
try {
  $seed = bin2hex(random_bytes(6));
  foreach (['requester', 'recipient', 'other'] as $role) {
    $account = User::create(['name' => "friends-qa-$role-$seed", 'mail' => "$role-$seed@example.test", 'status' => 1]);
    $account->save();
    $uids[$role] = (int) $account->id();
    $tokens[$role] = AppSessions::issue($account, ['id' => "friends-qa-$seed"])['access_token'];
  }

  // Explicitly pick a friendship ID above every account and friendship ID.
  $id = 1 + max((int) $db->query('SELECT MAX(uid) FROM {users_field_data}')->fetchField(), (int) $db->query('SELECT MAX(id) FROM {nelkano_friendship}')->fetchField());
  $fixture = static function () use ($db, $id, $uids): void {
    $now = \Drupal::time()->getRequestTime();
    $db->insert('nelkano_friendship')->fields([
      'id' => $id, 'requester_uid' => $uids['requester'], 'recipient_uid' => $uids['recipient'],
      'status' => 'pending', 'created' => $now, 'updated' => $now,
    ])->execute();
  };

  $kernel = \Drupal::service('http_kernel');
  $subscriber = \Drupal::service('nelkano_home.account_mutation_locks');
  $controller = new \Drupal\nelkano_home\Controller\AppAuthController();
  $check = static function (string $action, string $role, array $locked, int $status) use ($id, $uids, $tokens, $kernel, $subscriber, $controller): void {
    $request = Request::create('/api/nelkano/friends/' . $action, 'POST', [], [], [], [
      'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens[$role], 'CONTENT_TYPE' => 'application/json',
    ], json_encode(['id' => $id, 'uid' => $id, 'friendshipId' => $id], JSON_THROW_ON_ERROR));
    $request->attributes->set('_route', 'nelkano_home.api_friends_' . $action);
    $handler = [$controller, $action . 'Friend'];
    $subscriber->lockMutations(new ControllerEvent($kernel, $handler, $request, HttpKernelInterface::MAIN_REQUEST));
    try {
      $expected = array_map(static fn(string $key): int => $uids[$key], $locked);
      sort($expected, SORT_NUMERIC);
      $expected = array_map(AccountErasure::lockKey(...), $expected);
      if ($request->attributes->get('_nelkano_mutation_locks') !== $expected || $handler($request)->getStatusCode() !== $status) {
        throw new RuntimeException("Unexpected locks or status for $role / $action.");
      }
    }
    finally {
      $subscriber->release(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    }
  };
  $fixture();
  $check('accept', 'other', ['other'], 403);
  $check('accept', 'requester', ['requester'], 403);
  $check('accept', 'recipient', ['recipient', 'requester'], 200);
  if ($db->query('SELECT status FROM {nelkano_friendship} WHERE id = :id', [':id' => $id])->fetchField() !== 'accepted') {
    throw new RuntimeException('The pending request was not accepted.');
  }
  $check('remove', 'requester', ['recipient', 'requester'], 200);
  $check('accept', 'recipient', ['recipient'], 404);
  $fixture();
  $check('reject', 'requester', ['recipient', 'requester'], 200);
  echo "PASS: acceptance, cancellation, removal, missing request and ownership.\n";
}
finally {
  $transaction->rollBack();
}
