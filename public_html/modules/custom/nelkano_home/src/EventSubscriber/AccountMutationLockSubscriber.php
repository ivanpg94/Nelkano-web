<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\EventSubscriber;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\nelkano_home\Service\AccountErasure;
use Drupal\nelkano_home\Service\AppSessions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/** Per-account mutation locks; never serializes all users or read-only frames. */
final class AccountMutationLockSubscriber implements EventSubscriberInterface {
  public function __construct(private readonly LockBackendInterface $lock, private readonly AccountProxyInterface $currentUser) {}
  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => ['lockMutations', 50], KernelEvents::FINISH_REQUEST => ['release', -250]];
  }

  public function lockMutations(ControllerEvent $event): void {
    if (!$event->isMainRequest()) { return; }
    $request = $event->getRequest();
    if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], TRUE)) { return; }
    $route = (string) $request->attributes->get('_route', '');
    if (!str_starts_with($route, 'nelkano_home.') && !$this->currentUser->isAuthenticated()) { return; }
    $db = \Drupal::database();
    $body = json_decode($request->getContent(), TRUE);
    $body = is_array($body) ? $body : $request->request->all();
    $uids = []; $actor = (int) $this->currentUser->id();
    $header = trim((string) $request->headers->get('Authorization', ''));
    if (preg_match('/^Bearer\s+(nkat_[a-f0-9]{64})$/D', $header, $match) && $db->schema()->tableExists('nelkano_app_session')) {
      $actor = (int) (AppSessions::accountFromAccessToken($match[1])?->id() ?? 0);
    }
    elseif (preg_match('/^Bearer\s+(\d+)\.([a-f0-9]{64})$/D', $header, $match)) {
      // This identifies only valid legacy credentials for locking; the controller
      // still performs its normal authentication and permission checks.
      $candidate = (int) $match[1]; $data = \Drupal::service('user.data');
      $hash = hash('sha256', $match[2]); $now = \Drupal::time()->getRequestTime();
      $tokens = $data->get('nelkano_home', $candidate, 'app_tokens');
      $valid = is_array($tokens) && isset($tokens[$hash]) && (int) $tokens[$hash] >= $now;
      $expected = (string) $data->get('nelkano_home', $candidate, 'app_token_hash');
      $valid = $valid || ($expected !== '' && hash_equals($expected, $hash) && (int) $data->get('nelkano_home', $candidate, 'app_token_expires') >= $now);
      $actor = $valid ? $candidate : 0;
    }
    elseif ($route === 'nelkano_home.api_v2_auth_refresh' && is_string($body['refresh_token'] ?? NULL) && $db->schema()->tableExists('nelkano_app_session')) {
      $actor = (int) $db->select('nelkano_app_session', 's')->fields('s', ['uid'])->condition('refresh_hash', hash('sha256', $body['refresh_token']))->execute()->fetchField();
    }
    if ($actor > 0) { $uids[$actor] = $actor; }
    $foreign_participants = $actor > 0;
    // Credential-only erasure and login do not need to establish a session first.
    if (!$actor && (str_contains($route, 'account_erasure') || str_contains($route, 'login'))) {
      $login = trim((string) ($body['email'] ?? $body['mail'] ?? $body['name'] ?? ''));
      if ($login !== '') {
        $query = $db->select('users_field_data', 'u')->fields('u', ['uid']);
        $row = $query->condition($query->orConditionGroup()->condition('mail', $login)->condition('name', $login))->range(0, 1)->execute()->fetchField();
        if ($row) { $uids[(int) $row] = (int) $row; }
      }
    }
    foreach ($foreign_participants ? ['friendUid', 'friend_uid'] : [] as $key) {
      if (isset($body[$key]) && is_scalar($body[$key]) && (int) $body[$key] > 0) { $uids[(int) $body[$key]] = (int) $body[$key]; }
    }
    if ($foreign_participants && str_starts_with($route, 'nelkano_home.api_friends_')) {
      $friend = (int) ($body['uid'] ?? $body['id'] ?? 0);
      if ($friend > 0) { $uids[$friend] = $friend; }
    }
    $game_participants = [];
    $session = (string) ($body['sessionId'] ?? $body['session_id'] ?? '');
    if ($foreign_participants && $session !== '' && $db->schema()->tableExists('nelkano_game_session')) {
      $row = $db->select('nelkano_game_session', 's')->fields('s', ['host_uid', 'guest_uid'])->condition('session_id', $session)->execute()->fetchAssoc();
      if ($row) {
        $game_participants = array_values(array_filter(array_map('intval', $row)));
        foreach ($game_participants as $uid) { $uids[$uid] = $uid; }
      }
    }
    if ($foreign_participants && $session !== '' && $db->schema()->tableExists('nelkano_stream_session')) {
      foreach ($db->select('nelkano_stream_session', 's')->fields('s', ['uid'])->condition('session_id', $session)->execute()->fetchCol() as $uid) { $uids[(int) $uid] = (int) $uid; }
    }
    $subject = $request->attributes->get('user');
    if ($subject instanceof \Drupal\user\UserInterface && (int) $subject->id() > 0) { $uids[(int) $subject->id()] = (int) $subject->id(); }
    sort($uids, SORT_NUMERIC); $held = []; $deadline = microtime(TRUE) + 3;
    try {
      foreach ($uids as $uid) {
        $key = AccountErasure::lockKey($uid);
        while (!$this->lock->acquire($key, 300)) {
          if (microtime(TRUE) >= $deadline) { throw new HttpException(409, 'La cuenta está siendo actualizada. Vuelve a intentarlo.'); }
          usleep(50000);
        }
        $held[] = $key;
      }
      // Identity loaded by an earlier event must not survive a completed deletion.
      \Drupal::entityTypeManager()->getStorage('user')->resetCache($uids);
      foreach ($uids as $uid) {
        // Credential-only anonymous forms must retain the same failure response
        // for unknown/blocked/wrong-password cases; their password guard decides.
        if (!$actor) { continue; }
        $active = $db->select('users_field_data', 'u')->fields('u', ['status'])->condition('uid', $uid)->execute()->fetchField();
        if ($active === FALSE || !(int) $active) { throw new HttpException(409, 'La cuenta ya no está disponible.'); }
      }
      if ($actor && $game_participants && str_starts_with($route, 'nelkano_home.api_game_') && !in_array($actor, $game_participants, TRUE)) {
        throw new HttpException(403, 'La sesión no pertenece a tu cuenta.');
      }
      $request->attributes->set('_nelkano_mutation_locks', $held);
    }
    catch (\Throwable $error) {
      foreach (array_reverse($held) as $key) { $this->lock->release($key); }
      throw $error;
    }
  }

  public function release(FinishRequestEvent $event): void {
    if (!$event->isMainRequest()) { return; }
    foreach (array_reverse($event->getRequest()->attributes->get('_nelkano_mutation_locks', [])) as $key) { $this->lock->release($key); }
  }
}
