<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** One explicitly invited, accepted friend per stream. No public stream directory. */
trait SpectatorInvitations {
  private function spectatorStore() {
    return \Drupal::keyValueExpirable('nelkano_spectator');
  }

  private function spectatorLive(array $invite): bool {
    return $this->areFriends((int) $invite['senderUid'], (int) $invite['recipientUid'])
      && $this->activeStreamSessionRow((int) $invite['senderUid'], $invite['sessionId']) !== NULL;
  }

  private function spectatorGrant(int $uid, string $session_id): ?array {
    $invite = $this->spectatorStore()->get('in:' . $uid);
    if (!is_array($invite) || $invite['state'] !== 'accepted' || $invite['sessionId'] !== $session_id || !$this->spectatorLive($invite)) {
      return NULL;
    }
    return $this->activeStreamSessionRow((int) $invite['senderUid'], $session_id);
  }

  private function spectatorOutgoing(int $uid): ?array {
    $store = $this->spectatorStore();
    $friend = $store->get('out:' . $uid);
    $invite = $friend ? $store->get('in:' . $friend) : NULL;
    if (!is_array($invite) || (int) $invite['senderUid'] !== $uid) {
      $store->delete('out:' . $uid);
      return NULL;
    }
    return $invite;
  }

  public function spectatorInvitations(Request $request): JsonResponse {
    $account = $this->accountFromRequest($request);
    if (!$account instanceof User) {
      return $this->error('Inicia sesión para usar el modo espectador.', 401);
    }
    if ($denied = $this->denyWithout($account, 'use nelkano app features')) {
      return $denied;
    }
    $uid = (int) $account->id();
    $store = $this->spectatorStore();
    $lock = \Drupal::lock();
    if (!$lock->acquire('nelkano_spectator_mutation', 10)) {
      return $this->error('Prueba de nuevo en un momento.', 409);
    }
    try {
      $body = json_decode($request->getContent(), TRUE) ?: [];
      $operation = $request->isMethod('GET') ? 'status' : ($body['operation'] ?? '');
      if ($operation === 'invite') {
        $friend = (int) ($body['friendUid'] ?? 0);
        $session = (string) ($body['sessionId'] ?? '');
        if ($friend <= 0 || $friend === $uid || !$this->areFriends($uid, $friend) || !$this->activeStreamSessionRow($uid, $session)) {
          return $this->error('Selecciona un amigo aceptado y una emisión activa.', 403);
        }
        if ($store->get('out:' . $uid) || $store->get('in:' . $friend) || $store->get('in:' . $uid) || $store->get('out:' . $friend)) {
          return $this->error('Ya hay una invitación o un espectador pendiente.', 409);
        }
        $invite = [
          'id' => bin2hex(random_bytes(24)), 'senderUid' => $uid, 'recipientUid' => $friend,
          'senderName' => $this->cleanText($account->getDisplayName(), 80),
          'recipientName' => $this->cleanText(User::load($friend)?->getDisplayName() ?? '', 80),
          'sessionId' => $session, 'gameTitle' => $this->cleanText((string) ($body['gameTitle'] ?? ''), 160),
          'systemName' => $this->cleanText((string) ($body['systemName'] ?? ''), 80),
          'state' => 'pending', 'expiresAt' => \Drupal::time()->getRequestTime() + 90,
        ];
        $store->setWithExpire('in:' . $friend, $invite, 90);
        $store->setWithExpire('out:' . $uid, $friend, 90);
      }
      elseif (in_array($operation, ['accept', 'reject', 'leave'], TRUE)) {
        $invite = $store->get('in:' . $uid);
        if (!is_array($invite) || !hash_equals($invite['id'], (string) ($body['id'] ?? ''))) {
          return $this->error('La invitación ha caducado.', 410);
        }
        if ($operation === 'accept') {
          if ($invite['state'] !== 'pending' || !$this->spectatorLive($invite)) {
            return $this->error('La partida ya no está disponible.', 410);
          }
          $invite['state'] = 'accepted';
          $invite['expiresAt'] = \Drupal::time()->getRequestTime() + 30;
          $store->setWithExpire('in:' . $uid, $invite, 30);
          $store->setWithExpire('out:' . $invite['senderUid'], $uid, 30);
        }
        else {
          $store->delete('in:' . $uid);
          $store->delete('out:' . $invite['senderUid']);
        }
      }
      elseif ($operation === 'cancel') {
        $friend = $store->get('out:' . $uid);
        $invite = $friend ? $store->get('in:' . $friend) : NULL;
        if (is_array($invite) && (int) $invite['senderUid'] === $uid) {
          if (!hash_equals($invite['id'], (string) ($body['id'] ?? ''))) {
            return $this->error('La invitación ha cambiado.', 410);
          }
          $store->delete('in:' . $friend);
        }
        $store->delete('out:' . $uid);
      }
      elseif ($operation !== 'status') {
        return $this->error('Operación no válida.', 400);
      }
      $incoming = $store->get('in:' . $uid);
      $outgoing = $this->spectatorOutgoing($uid);
      foreach ([$incoming, $outgoing] as $invite) {
        if (is_array($invite) && !$this->spectatorLive($invite)) {
          $store->delete('in:' . $invite['recipientUid']);
          $store->delete('out:' . $invite['senderUid']);
        }
      }
      $incoming = $store->get('in:' . $uid);
      $outgoing = $this->spectatorOutgoing($uid);
      // Only the receiving app renews its viewing lease; a lost receiver expires.
      if (is_array($incoming) && $incoming['state'] === 'accepted') {
        $incoming['expiresAt'] = \Drupal::time()->getRequestTime() + 30;
        $store->setWithExpire('in:' . $uid, $incoming, 30);
        $store->setWithExpire('out:' . $incoming['senderUid'], $uid, 30);
      }
      return new JsonResponse(['ok' => TRUE, 'incoming' => $incoming, 'outgoing' => $outgoing], 200, ['Cache-Control' => 'no-store']);
    }
    finally {
      $lock->release('nelkano_spectator_mutation');
    }
  }
}
