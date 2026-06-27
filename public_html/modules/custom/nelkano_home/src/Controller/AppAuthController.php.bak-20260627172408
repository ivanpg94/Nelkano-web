<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AppAuthController extends ControllerBase {

  private const TOKEN_TTL = 2592000;

  public function login(Request $request): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }

    $login = trim((string) ($payload['email'] ?? $payload['username'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    if ($login === '' || $password === '') {
      return $this->error('Indica email y contrasena.', 400);
    }

    $account = $this->loadAccountForLogin($login);
    if (!$account instanceof User || !$account->isActive()) {
      return $this->error('Cuenta no encontrada o pendiente de verificar.', 403);
    }

    $uid = \Drupal::service('user.auth')->authenticate($account->getAccountName(), $password);
    if (!$uid) {
      return $this->error('Credenciales incorrectas.', 403);
    }

    $secret = bin2hex(random_bytes(32));
    $expires = \Drupal::time()->getRequestTime() + self::TOKEN_TTL;
    $token_hash = hash('sha256', $secret);
    $user_data = \Drupal::service('user.data');
    $tokens = is_array($user_data->get('nelkano_home', (int) $account->id(), 'app_tokens'))
      ? $user_data->get('nelkano_home', (int) $account->id(), 'app_tokens')
      : [];
    $now = \Drupal::time()->getRequestTime();
    $tokens = array_filter($tokens, static fn($token_expires) => (int) $token_expires >= $now);
    $tokens[$token_hash] = $expires;
    $user_data->set('nelkano_home', (int) $account->id(), 'app_tokens', $tokens);
    \Drupal::service('user.data')->set('nelkano_home', (int) $account->id(), 'app_token_hash', hash('sha256', $secret));
    \Drupal::service('user.data')->set('nelkano_home', (int) $account->id(), 'app_token_expires', $expires);
    \Drupal::service('user.data')->set('nelkano_home', (int) $account->id(), 'app_token_last_used', \Drupal::time()->getRequestTime());

    return new JsonResponse([
      'ok' => TRUE,
      'token' => $account->id() . '.' . $secret,
      'expires_at' => $expires,
      'user' => $this->userPayload($account),
    ]);
  }

  public function register(Request $request): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }

    $name = trim((string) ($payload['name'] ?? ''));
    $mail = trim((string) ($payload['email'] ?? $payload['mail'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $confirm = (string) ($payload['password_confirm'] ?? $payload['confirm'] ?? '');
    $terms = !empty($payload['terms']);

    if (mb_strlen($name) < 2) {
      return $this->error('Escribe un nombre reconocible.', 400);
    }
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
      return $this->error('Indica un correo valido.', 400);
    }
    if (strlen($password) < 8) {
      return $this->error('Usa al menos 8 caracteres.', 400);
    }
    if ($password !== $confirm) {
      return $this->error('Las contrasenas no coinciden.', 400);
    }
    if (!$terms) {
      return $this->error('Acepta los terminos para crear la cuenta.', 400);
    }

    $storage = $this->entityTypeManager()->getStorage('user');
    if ($storage->loadByProperties(['mail' => $mail]) || $storage->loadByProperties(['name' => $mail])) {
      return $this->error('Ya existe una cuenta con ese correo.', 409);
    }

    $account = User::create([
      'name' => $mail,
      'mail' => $mail,
      'pass' => $password,
      'status' => 0,
    ]);
    $account->save();
    $this->createInitialProfile((int) $account->id(), $name);

    $token = bin2hex(random_bytes(32));
    $expires = \Drupal::time()->getRequestTime() + 86400;
    $user_data = \Drupal::service('user.data');
    $user_data->set('nelkano_home', (int) $account->id(), 'verification_hash', hash('sha256', $token));
    $user_data->set('nelkano_home', (int) $account->id(), 'verification_expires', $expires);

    $verify_url = Url::fromRoute('nelkano_home.verify', [
      'uid' => $account->id(),
      'token' => $token,
    ], ['absolute' => TRUE])->toString();

    $result = \Drupal::service('plugin.manager.mail')->mail(
      'nelkano_home',
      'account_verify',
      $mail,
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      [
        'name' => $name,
        'verify_url' => $verify_url,
      ],
      'contacto@nelkano.com',
      TRUE
    );

    return new JsonResponse([
      'ok' => TRUE,
      'message' => !empty($result['result'])
        ? 'Cuenta creada. Te hemos enviado un correo para verificarla.'
        : 'Cuenta creada, pero no se pudo enviar el correo de verificacion. Contacta con soporte.',
      'email_sent' => !empty($result['result']),
      'verify_url' => $this->isLocalHost($request) ? $verify_url : NULL,
    ], !empty($result['result']) ? 201 : 202);
  }

  public function me(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'user' => $this->userPayload($account),
    ]);
  }

  public function activity(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'activity' => $this->activityPayload((int) $account->id()),
    ]);
  }

  public function syncActivity(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }

    $uid = (int) $account->id();
    $now = \Drupal::time()->getRequestTime();
    $database = \Drupal::database();
    $schema = $database->schema();
    foreach (['nelkano_device', 'nelkano_library_item', 'nelkano_play_session'] as $table) {
      if (!$schema->tableExists($table)) {
        return $this->error('El almacenamiento de actividad no esta instalado.', 503);
      }
    }

    $device = is_array($payload['device'] ?? NULL) ? $payload['device'] : [];
    $device_id = $this->cleanId((string) ($device['id'] ?? 'unknown'));
    $database->merge('nelkano_device')
      ->keys(['uid' => $uid, 'device_id' => $device_id])
      ->fields([
        'platform' => $this->cleanText((string) ($device['platform'] ?? 'unknown'), 32),
        'name' => $this->cleanText((string) ($device['name'] ?? ''), 120),
        'last_seen' => $now,
        'created' => $now,
      ])
      ->execute();

    $items_by_client = [];
    $items = is_array($payload['items'] ?? NULL) ? $payload['items'] : [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $client_id = $this->cleanId((string) ($item['id'] ?? ''));
      if ($client_id === '') {
        continue;
      }
      $system = $this->cleanText((string) ($item['system'] ?? 'unknown'), 24);
      $row = $this->upsertLibraryItem($uid, $client_id, [
        'system' => $system,
        'display_name' => $this->activityDisplayName($system),
        'favorite' => !empty($item['favorite']) ? 1 : 0,
        'added_at' => $this->millisToSeconds((int) ($item['addedAt'] ?? 0)),
        'last_played_at' => $this->millisToSeconds((int) ($item['lastPlayedAt'] ?? 0)),
        'total_play_ms' => max(0, (int) ($item['playMillis'] ?? 0)),
        'save_count' => max(0, (int) ($item['saveCount'] ?? 0)),
        'updated_at' => $now,
      ]);
      if ($row) {
        $aliases = [];
        if (is_array($item['aliases'] ?? NULL)) {
          foreach ($item['aliases'] as $alias) {
            $alias_id = $this->cleanId((string) $alias);
            if ($alias_id !== '' && $alias_id !== $client_id) {
              $aliases[] = $alias_id;
            }
          }
        }
        if ($aliases) {
          $row = $this->mergeLibraryAliases($uid, $client_id, array_unique($aliases), $row, $now);
        }
        $items_by_client[$client_id] = $row;
        foreach ($aliases ?? [] as $alias_id) {
          $items_by_client[$alias_id] = $row;
        }
      }
    }

    $accepted_sessions = [];
    $sessions = is_array($payload['sessions'] ?? NULL) ? $payload['sessions'] : [];
    foreach ($sessions as $session) {
      if (!is_array($session)) {
        continue;
      }
      $session_id = $this->cleanId((string) ($session['id'] ?? ''));
      $client_id = $this->cleanId((string) ($session['itemId'] ?? ''));
      $duration_ms = max(0, (int) ($session['durationMs'] ?? 0));
      if ($session_id === '' || $client_id === '' || $duration_ms <= 0) {
        continue;
      }
      $item_row = $items_by_client[$client_id] ?? $this->libraryItemByClientId($uid, $client_id);
      if (!$item_row) {
        continue;
      }
      $exists = (bool) $database->select('nelkano_play_session', 's')
        ->fields('s', ['id'])
        ->condition('uid', $uid)
        ->condition('session_id', $session_id)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($exists) {
        $accepted_sessions[] = $session_id;
        continue;
      }
      $started = $this->millisToSeconds((int) ($session['startedAt'] ?? 0));
      $ended = $this->millisToSeconds((int) ($session['endedAt'] ?? 0));
      $database->insert('nelkano_play_session')
        ->fields([
          'uid' => $uid,
          'library_item_id' => (int) $item_row['id'],
          'device_id' => $device_id,
          'session_id' => $session_id,
          'started_at' => $started,
          'ended_at' => $ended,
          'duration_ms' => $duration_ms,
          'created' => $now,
        ])
        ->execute();
      $database->update('nelkano_library_item')
        ->expression('total_play_ms', 'total_play_ms + :duration', [':duration' => $duration_ms])
        ->expression('play_count', 'play_count + 1')
        ->fields([
          'last_played_at' => max((int) $item_row['last_played_at'], $ended),
          'updated_at' => $now,
        ])
        ->condition('id', (int) $item_row['id'])
        ->execute();
      $accepted_sessions[] = $session_id;
    }

    return new JsonResponse([
      'ok' => TRUE,
      'accepted_sessions' => $accepted_sessions,
      'activity' => $this->activityPayload($uid),
    ]);
  }

  public function friends(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    if (!\Drupal::database()->schema()->tableExists('nelkano_friendship')) {
      return $this->error('El almacenamiento de amigos no esta instalado.', 503);
    }

    $uid = (int) $account->id();
    return new JsonResponse([
      'ok' => TRUE,
      'friends' => $this->friendRows($uid, 'accepted'),
      'incoming' => $this->friendRows($uid, 'incoming'),
      'outgoing' => $this->friendRows($uid, 'outgoing'),
    ]);
  }

  public function profile(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    return new JsonResponse([
      'ok' => TRUE,
      'profile' => $this->appProfilePayload($account),
    ]);
  }

  public function updateProfile(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }
    $display_name = $this->cleanText(trim((string) ($payload['displayName'] ?? $payload['display_name'] ?? '')), 80);
    $status_text = $this->cleanText(trim((string) ($payload['statusText'] ?? $payload['status_text'] ?? '')), 140);
    $avatar_color = trim((string) ($payload['avatarColor'] ?? $payload['avatar_color'] ?? '#a414ff'));
    if (mb_strlen($display_name) < 2) {
      return $this->error('Usa al menos 2 caracteres para el usuario visible.', 400);
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $avatar_color)) {
      $avatar_color = '#a414ff';
    }

    $uid = (int) $account->id();
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('nelkano_profile')) {
      return $this->error('El perfil social no esta instalado.', 503);
    }
    $current = $database->select('nelkano_profile', 'p')
      ->fields('p', ['avatar_file_uri'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: [];
    $avatar_uri = (string) ($current['avatar_file_uri'] ?? '');
    $avatar_base64 = trim((string) ($payload['avatarBase64'] ?? $payload['avatar_base64'] ?? ''));
    if ($avatar_base64 !== '') {
      $avatar_mime = strtolower(trim((string) ($payload['avatarMime'] ?? $payload['avatar_mime'] ?? 'image/jpeg')));
      $extension = match ($avatar_mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
      };
      $binary = base64_decode($avatar_base64, TRUE);
      if ($binary === FALSE || strlen($binary) > 4 * 1024 * 1024) {
        return $this->error('Imagen de perfil no valida.', 400);
      }
      $directory = 'public://nelkano-avatars';
      $file_system = \Drupal::service('file_system');
      $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $avatar_uri = $file_system->saveData($binary, $directory . '/app_' . $uid . '.' . $extension, FileSystemInterface::EXISTS_REPLACE);
    }

    $now = \Drupal::time()->getRequestTime();
    $database->merge('nelkano_profile')
      ->key('uid', $uid)
      ->fields([
        'display_name' => $display_name,
        'status_text' => $status_text,
        'avatar_color' => $avatar_color,
        'avatar_file_uri' => $avatar_uri,
        'updated' => $now,
      ])
      ->insertFields([
        'uid' => $uid,
        'display_name' => $display_name,
        'status_text' => $status_text,
        'avatar_color' => $avatar_color,
        'avatar_file_uri' => $avatar_uri,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    return new JsonResponse([
      'ok' => TRUE,
      'message' => 'Perfil guardado.',
      'profile' => $this->appProfilePayload($account),
    ]);
  }

  public function searchFriends(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $q = trim((string) $request->query->get('q', ''));
    if (mb_strlen($q) < 2) {
      return $this->error('Busca al menos 2 caracteres.', 400);
    }
    $database = \Drupal::database();
    $query = $database->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->condition('u.status', 1)
      ->range(0, 10);
    $query->leftJoin('nelkano_profile', 'p', 'p.uid = u.uid');
    $or = $query->orConditionGroup()
      ->condition('u.mail', '%' . $q . '%', 'LIKE')
      ->condition('u.name', '%' . $q . '%', 'LIKE')
      ->condition('p.display_name', '%' . $q . '%', 'LIKE');
    $ids = $query->condition($or)->execute()->fetchCol();
    $storage = $this->entityTypeManager()->getStorage('user');
    $users = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      if ($user instanceof User && (int) $user->id() !== (int) $account->id()) {
        $users[] = $this->friendUserPayload($user, 0, 'search');
      }
    }
    return new JsonResponse(['ok' => TRUE, 'users' => $users]);
  }

  public function requestFriend(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    $friend_uid = (int) (($payload['uid'] ?? 0) ?: ($payload['id'] ?? 0));
    if ($friend_uid <= 0 || $friend_uid === (int) $account->id()) {
      return $this->error('Usuario no valido.', 400);
    }
    $friend = User::load($friend_uid);
    if (!$friend instanceof User || !$friend->isActive()) {
      return $this->error('Usuario no encontrado.', 404);
    }
    if (!\Drupal::database()->schema()->tableExists('nelkano_friendship')) {
      return $this->error('El almacenamiento de amigos no esta instalado.', 503);
    }
    $database = \Drupal::database();
    $uid = (int) $account->id();
    $existing = $this->friendshipBetween($uid, $friend_uid);
    if ($existing) {
      if ((string) $existing['status'] === 'accepted') {
        return new JsonResponse(['ok' => TRUE, 'message' => 'Ya sois amigos.']);
      }
      return new JsonResponse(['ok' => TRUE, 'message' => 'La solicitud ya existe.']);
    }
    $now = \Drupal::time()->getRequestTime();
    $database->insert('nelkano_friendship')->fields([
      'requester_uid' => $uid,
      'recipient_uid' => $friend_uid,
      'status' => 'pending',
      'created' => $now,
      'updated' => $now,
    ])->execute();
    return new JsonResponse(['ok' => TRUE, 'message' => 'Solicitud enviada.']);
  }

  public function acceptFriend(Request $request): JsonResponse {
    return $this->friendDecision($request, 'accept');
  }

  public function rejectFriend(Request $request): JsonResponse {
    return $this->friendDecision($request, 'reject');
  }

  public function removeFriend(Request $request): JsonResponse {
    return $this->friendDecision($request, 'remove');
  }

  public function connectGameSession(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $database = \Drupal::database();
    $schema = $database->schema();
    if (!$schema->tableExists('nelkano_game_session') || !$schema->tableExists('nelkano_game_signal_event')) {
      return $this->error('El almacenamiento multijugador no esta instalado.', 503);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }
    $uid = (int) $account->id();
    $friend_uid = (int) ($payload['friendUid'] ?? 0);
    $role = strtolower($this->cleanText((string) ($payload['role'] ?? 'host'), 16));
    $role = $role === 'guest' || $role === 'invitado' ? 'guest' : 'host';
    if ($friend_uid <= 0 || !$this->areFriends($uid, $friend_uid)) {
      return $this->error('Solo puedes conectar con amigos aceptados.', 403);
    }
    $system = strtoupper($this->cleanText((string) ($payload['system'] ?? 'GBA'), 24));
    $mode = strtoupper($this->cleanText((string) ($payload['mode'] ?? 'LINK_CABLE'), 32));
    $rom_hash = $this->cleanId((string) ($payload['romHash'] ?? ''));
    if ($system === 'GBA' && $mode === 'LINK_CABLE' && $rom_hash === '') {
      return $this->error('No se pudo verificar que ambos usan la misma ROM.', 400);
    }
    $device = is_array($payload['device'] ?? NULL) ? $payload['device'] : [];
    $device_id = $this->cleanId((string) ($device['id'] ?? 'android'));
    $now = \Drupal::time()->getRequestTime();
    $database->update('nelkano_game_session')
      ->fields(['status' => 'closed', 'closed' => $now, 'updated' => $now])
      ->condition('expires', $now, '<')
      ->condition('status', ['pending', 'active'], 'IN')
      ->execute();

    $host_uid = $role === 'host' ? $uid : $friend_uid;
    $guest_uid = $role === 'guest' ? $uid : $friend_uid;
    $row = $database->select('nelkano_game_session', 's')
      ->fields('s')
      ->condition('host_uid', $host_uid)
      ->condition('guest_uid', $guest_uid)
      ->condition('system', $system)
      ->condition('mode', $mode)
      ->condition('status', ['pending', 'active'], 'IN')
      ->condition('expires', $now, '>=')
      ->orderBy('updated', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      $session_id = strtoupper(substr(bin2hex(random_bytes(12)), 0, 18));
      $fields = [
        'session_id' => $session_id,
        'host_uid' => $host_uid,
        'guest_uid' => $guest_uid,
        'host_device_id' => $role === 'host' ? $device_id : '',
        'guest_device_id' => $role === 'guest' ? $device_id : '',
        'system' => $system,
        'mode' => $mode,
        'rom_hash' => $rom_hash,
        'status' => 'pending',
        'created' => $now,
        'updated' => $now,
        'expires' => $now + 300,
        'closed' => 0,
      ];
      $database->insert('nelkano_game_session')->fields($fields)->execute();
      $row = $fields;
    }
    else {
      $existing_rom_hash = (string) ($row['rom_hash'] ?? '');
      if ($rom_hash !== '' && $existing_rom_hash !== '' && !hash_equals($existing_rom_hash, $rom_hash)) {
        return $this->error('Ambos jugadores deben usar la misma ROM para cable link GBA.', 409);
      }
      $fields = [
        $role === 'host' ? 'host_device_id' : 'guest_device_id' => $device_id,
        'updated' => $now,
        'expires' => $now + 300,
      ];
      if ($rom_hash !== '' && (string) ($row['rom_hash'] ?? '') === '') {
        $fields['rom_hash'] = $rom_hash;
      }
      $has_host = $role === 'host' || (string) ($row['host_device_id'] ?? '') !== '';
      $has_guest = $role === 'guest' || (string) ($row['guest_device_id'] ?? '') !== '';
      if ($has_host && $has_guest) {
        $fields['status'] = 'active';
        $fields['expires'] = $now + 7200;
      }
      $database->update('nelkano_game_session')->fields($fields)->condition('session_id', (string) $row['session_id'])->execute();
      $row = $this->activeGameSessionRow((string) $row['session_id']) ?: ($row + $fields);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'ready' => (string) ($row['status'] ?? '') === 'active',
      'cursor' => 0,
      'session' => $this->gameSessionPayload($row, $role, $friend_uid),
    ]);
  }

  public function endGameSession(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    $session_id = $this->cleanId((string) ($payload['sessionId'] ?? ''));
    if ($session_id !== '' && \Drupal::database()->schema()->tableExists('nelkano_game_session')) {
      $now = \Drupal::time()->getRequestTime();
      \Drupal::database()->update('nelkano_game_session')
        ->fields(['status' => 'closed', 'closed' => $now, 'updated' => $now])
        ->condition('session_id', $session_id)
        ->condition('status', ['pending', 'active'], 'IN')
        ->execute();
    }
    return new JsonResponse(['ok' => TRUE]);
  }

  public function gameSignalingEvent(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }
    $session_id = $this->cleanId((string) ($payload['sessionId'] ?? ''));
    $target = $this->cleanText((string) ($payload['target'] ?? ''), 16);
    $type = $this->cleanText((string) ($payload['type'] ?? ''), 32);
    if ($session_id === '' || !in_array($target, ['host', 'guest'], TRUE) || $type === '') {
      return $this->error('Evento de senalizacion no valido.', 400);
    }
    $row = $this->activeGameSessionRow($session_id);
    if (!$row || !$this->gameSessionAllows((int) $account->id(), $row)) {
      return $this->error('Sesion de juego no encontrada.', 404);
    }
    \Drupal::database()->insert('nelkano_game_signal_event')->fields([
      'session_id' => $session_id,
      'sender_uid' => (int) $account->id(),
      'target' => $target,
      'type' => $type,
      'payload' => json_encode(is_array($payload['payload'] ?? NULL) ? $payload['payload'] : [], JSON_UNESCAPED_SLASHES),
      'created' => \Drupal::time()->getRequestTime(),
    ])->execute();
    return new JsonResponse(['ok' => TRUE]);
  }

  public function gameSignalingEvents(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $session_id = $this->cleanId((string) $request->query->get('sessionId', ''));
    $target = $this->cleanText((string) $request->query->get('target', ''), 16);
    $since = max(0, (int) $request->query->get('since', 0));
    if ($session_id === '' || !in_array($target, ['host', 'guest'], TRUE)) {
      return $this->error('Consulta de senalizacion no valida.', 400);
    }
    $row = $this->activeGameSessionRow($session_id);
    if (!$row || !$this->gameSessionAllows((int) $account->id(), $row)) {
      return $this->error('Sesion de juego no encontrada.', 404);
    }
    $rows = \Drupal::database()->select('nelkano_game_signal_event', 'e')
      ->fields('e')
      ->condition('session_id', $session_id)
      ->condition('target', $target)
      ->condition('id', $since, '>')
      ->orderBy('id', 'ASC')
      ->range(0, 50)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    $events = [];
    $cursor = $since;
    foreach ($rows as $event) {
      $cursor = max($cursor, (int) $event['id']);
      $decoded = json_decode((string) ($event['payload'] ?? ''), TRUE);
      $events[] = [
        'id' => (int) $event['id'],
        'type' => (string) $event['type'],
        'target' => (string) $event['target'],
        'payload' => is_array($decoded) ? $decoded : [],
      ];
    }
    return new JsonResponse(['ok' => TRUE, 'cursor' => $cursor, 'events' => $events]);
  }

  public function activeStreamSession(Request $request): JsonResponse {
    $account = $this->accountFromRequest($request);
    if (!$account instanceof User) {
      return $this->error('Inicia sesion para ver tu streaming.', 401);
    }

    $database = \Drupal::database();
    if (!$database->schema()->tableExists('nelkano_stream_session')) {
      return $this->error('El almacenamiento de streaming no esta instalado.', 503);
    }

    $now = \Drupal::time()->getRequestTime();
    $row = $database->select('nelkano_stream_session', 's')
      ->fields('s')
      ->condition('uid', (int) $account->id())
      ->condition('status', 'active')
      ->condition('expires', $now, '>=')
      ->orderBy('updated', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      return new JsonResponse([
        'ok' => TRUE,
        'active' => FALSE,
        'message' => 'Abre el emulador en Android y pulsa Streaming.',
      ]);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'active' => TRUE,
      'message' => $this->cleanText($account->getDisplayName(), 80) . ' esta transmitiendo desde Android.',
      'session' => $this->streamSessionPayload($row),
    ]);
  }

  public function registerStreamSession(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }

    $database = \Drupal::database();
    if (!$database->schema()->tableExists('nelkano_stream_session')) {
      return $this->error('El almacenamiento de streaming no esta instalado.', 503);
    }

    $device = is_array($payload['device'] ?? NULL) ? $payload['device'] : [];
    $session = is_array($payload['session'] ?? NULL) ? $payload['session'] : [];
    $uid = (int) $account->id();
    $now = \Drupal::time()->getRequestTime();
    $session_id = $this->cleanId((string) ($session['sessionId'] ?? ''));
    $pin = $this->cleanId((string) ($session['pin'] ?? ''));
    if ($session_id === '' || $pin === '') {
      return $this->error('La sesion de streaming no es valida.', 400);
    }

    $device_id = $this->cleanId((string) ($device['id'] ?? 'android'));
    $database->update('nelkano_stream_session')
      ->fields([
        'status' => 'closed',
        'closed' => $now,
        'updated' => $now,
      ])
      ->condition('uid', $uid)
      ->condition('device_id', $device_id)
      ->condition('status', 'active')
      ->execute();

    $fields = [
      'uid' => $uid,
      'device_id' => $device_id,
      'device_name' => $this->cleanText((string) ($device['name'] ?? 'Android'), 120),
      'platform' => $this->cleanText((string) ($device['platform'] ?? 'android'), 32),
      'session_id' => $session_id,
      'pin' => $pin,
      'join_uri' => $this->cleanText((string) ($session['joinUri'] ?? ''), 255),
      'signaling_url' => $this->cleanText((string) ($session['signalingUrl'] ?? ''), 255),
      'status' => 'active',
      'created' => $now,
      'updated' => $now,
      'expires' => $now + 7200,
      'closed' => 0,
    ];
    $database->insert('nelkano_stream_session')->fields($fields)->execute();

    return new JsonResponse([
      'ok' => TRUE,
      'active' => TRUE,
      'message' => $this->cleanText($account->getDisplayName(), 80) . ' esta transmitiendo desde Android.',
      'session' => $this->streamSessionPayload($fields),
    ]);
  }

  public function endStreamSession(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    $payload = is_array($payload) ? $payload : [];
    $session = is_array($payload['session'] ?? NULL) ? $payload['session'] : [];
    $session_id = $this->cleanId((string) (($payload['sessionId'] ?? '') ?: ($session['sessionId'] ?? '')));
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('nelkano_stream_session')) {
      return new JsonResponse(['ok' => TRUE]);
    }

    $now = \Drupal::time()->getRequestTime();
    $query = $database->update('nelkano_stream_session')
      ->fields([
        'status' => 'closed',
        'closed' => $now,
        'updated' => $now,
      ])
      ->condition('uid', (int) $account->id())
      ->condition('status', 'active');
    if ($session_id !== '') {
      $query->condition('session_id', $session_id);
    }
    $closed = $query->execute();

    return new JsonResponse([
      'ok' => TRUE,
      'closed' => $closed,
    ]);
  }

  public function createStreamSignalingSession(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }
    $database = \Drupal::database();
    $schema = $database->schema();
    if (!$schema->tableExists('nelkano_stream_session') || !$schema->tableExists('nelkano_stream_signal_event')) {
      return $this->error('El almacenamiento de streaming no esta instalado.', 503);
    }

    $uid = (int) $account->id();
    $device = is_array($payload['device'] ?? NULL) ? $payload['device'] : [];
    $device_id = $this->cleanId((string) ($device['id'] ?? 'android'));
    $device_name = $this->cleanText((string) ($device['name'] ?? 'Android'), 120);
    $platform = $this->cleanText((string) ($device['platform'] ?? 'android'), 32);
    $now = \Drupal::time()->getRequestTime();
    $config = is_array($payload['config'] ?? NULL) ? $payload['config'] : [];
    $kind = $this->cleanText((string) ($config['kind'] ?? 'stream'), 32);
    if ($kind === 'gba-link') {
      do {
        $session_id = (string) random_int(100000, 999999);
      } while ($this->activeGbaLinkSessionRow($session_id));
      $pin = $session_id;
    }
    else {
      $session_id = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
      $pin = (string) random_int(1000, 9999);
    }
    $signaling_url = $request->getSchemeAndHttpHost() . '/api/nelkano/stream/signaling';

    $database->update('nelkano_stream_session')
      ->fields([
        'status' => 'closed',
        'closed' => $now,
        'updated' => $now,
      ])
      ->condition('uid', $uid)
      ->condition('device_id', $device_id)
      ->condition('status', 'active')
      ->execute();

    $database->delete('nelkano_stream_signal_event')
      ->condition('uid', $uid)
      ->condition('created', $now - 7200, '<')
      ->execute();

    $fields = [
      'uid' => $uid,
      'device_id' => $device_id,
      'device_name' => $device_name,
      'platform' => $platform,
      'session_id' => $session_id,
      'pin' => $pin,
      'join_uri' => Url::fromUri('internal:/user/stream', ['absolute' => TRUE])->toString(),
      'signaling_url' => $signaling_url,
      'status' => 'active',
      'created' => $now,
      'updated' => $now,
      'expires' => $now + 7200,
      'closed' => 0,
    ];
    $database->insert('nelkano_stream_session')->fields($fields)->execute();

    return new JsonResponse([
      'ok' => TRUE,
      'cursor' => 0,
      'active' => TRUE,
      'message' => $this->cleanText($account->getDisplayName(), 80) . ' esta transmitiendo desde Android.',
      'session' => $this->streamSessionPayload($fields),
    ]);
  }

  public function streamSignalingEvent(Request $request): JsonResponse {
    $account = $this->accountFromRequest($request);
    if (!$account instanceof User) {
      return $this->error('Inicia sesion para usar el streaming.', 401);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }
    $database = \Drupal::database();
    $schema = $database->schema();
    if (!$schema->tableExists('nelkano_stream_session') || !$schema->tableExists('nelkano_stream_signal_event')) {
      return $this->error('El almacenamiento de streaming no esta instalado.', 503);
    }

    $session_id = $this->cleanId((string) ($payload['sessionId'] ?? ''));
    $type = $this->cleanText((string) ($payload['type'] ?? ''), 32);
    if ($session_id === '' || $type === '') {
      return $this->error('Evento de streaming no valido.', 400);
    }
    $row = $this->activeStreamSessionRowForSignaling((int) $account->id(), $session_id);
    if (!$row) {
      return $this->error('Sesion de streaming no encontrada.', 404);
    }

    $target = $this->cleanText((string) ($payload['target'] ?? ''), 16);
    if (!in_array($target, ['android', 'receiver'], TRUE)) {
      $target = $type === 'answer' ? 'android' : 'receiver';
    }
    $message_payload = is_array($payload['payload'] ?? NULL) ? $payload['payload'] : [];
    $now = \Drupal::time()->getRequestTime();
    $id = $database->insert('nelkano_stream_signal_event')->fields([
      'uid' => (int) $row['uid'],
      'session_id' => $session_id,
      'target' => $target,
      'type' => $type,
      'payload' => json_encode($message_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'created' => $now,
    ])->execute();
    $database->update('nelkano_stream_session')
      ->fields(['updated' => $now, 'expires' => $now + 7200])
      ->condition('uid', (int) $row['uid'])
      ->condition('session_id', $session_id)
      ->execute();

    return new JsonResponse(['ok' => TRUE, 'id' => (int) $id, 'cursor' => (int) $id]);
  }

  public function streamSignalingEvents(Request $request): JsonResponse {
    $account = $this->accountFromRequest($request);
    if (!$account instanceof User) {
      return $this->error('Inicia sesion para usar el streaming.', 401);
    }
    $database = \Drupal::database();
    $schema = $database->schema();
    if (!$schema->tableExists('nelkano_stream_session') || !$schema->tableExists('nelkano_stream_signal_event')) {
      return $this->error('El almacenamiento de streaming no esta instalado.', 503);
    }

    $session_id = $this->cleanId((string) $request->query->get('sessionId', ''));
    $target = $this->cleanText((string) $request->query->get('target', 'receiver'), 16);
    $since = max(0, (int) $request->query->get('since', 0));
    if ($session_id === '' || !in_array($target, ['android', 'receiver'], TRUE)) {
      return $this->error('Consulta de streaming no valida.', 400);
    }
    $row = $this->activeStreamSessionRowForSignaling((int) $account->id(), $session_id);
    if (!$row) {
      return $this->error('Sesion de streaming no encontrada.', 404);
    }

    $rows = $database->select('nelkano_stream_signal_event', 'e')
      ->fields('e')
      ->condition('uid', (int) $row['uid'])
      ->condition('session_id', $session_id)
      ->condition('target', $target)
      ->condition('id', $since, '>')
      ->orderBy('id', 'ASC')
      ->range(0, 40)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    $events = [];
    $cursor = $since;
    foreach ($rows as $row) {
      $cursor = max($cursor, (int) $row['id']);
      $decoded = json_decode((string) ($row['payload'] ?? ''), TRUE);
      $events[] = [
        'id' => (int) $row['id'],
        'type' => (string) $row['type'],
        'target' => (string) $row['target'],
        'payload' => is_array($decoded) ? $decoded : [],
      ];
    }

    return new JsonResponse([
      'ok' => TRUE,
      'cursor' => $cursor,
      'events' => $events,
    ]);
  }

  public function streamFramePost(Request $request): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->error('Peticion no valida.', 400);
    }
    $session_id = $this->cleanId((string) ($payload['sessionId'] ?? ''));
    if ($session_id === '' || !$this->activeStreamSessionRow((int) $account->id(), $session_id)) {
      return $this->error('Sesion de streaming no encontrada.', 404);
    }
    $binary = base64_decode((string) ($payload['image'] ?? ''), TRUE);
    if ($binary === FALSE || strlen($binary) < 64 || strlen($binary) > 1200000) {
      return $this->error('Frame de streaming no valido.', 400);
    }
    $mime = $this->streamFrameMime($binary, (string) ($payload['mime'] ?? ''));
    if ($mime === '') {
      return $this->error('El frame debe ser JPEG, PNG o WebP.', 400);
    }

    $path = $this->streamFramePath((int) $account->id(), $session_id);
    if ($path === '' || @file_put_contents($path, $binary, LOCK_EX) === FALSE) {
      return $this->error('No se pudo guardar el frame.', 500);
    }
    $sequence = (int) floor(microtime(TRUE) * 1000);
    @file_put_contents($path . '.seq', (string) $sequence, LOCK_EX);
    @file_put_contents($path . '.mime', $mime, LOCK_EX);
    $now = \Drupal::time()->getRequestTime();
    \Drupal::database()->update('nelkano_stream_session')
      ->fields(['updated' => $now, 'expires' => $now + 7200])
      ->condition('uid', (int) $account->id())
      ->condition('session_id', $session_id)
      ->execute();

    return new JsonResponse([
      'ok' => TRUE,
      'sequence' => $sequence,
    ]);
  }

  public function streamFrameGet(Request $request): JsonResponse {
    $account = $this->accountFromRequest($request);
    if (!$account instanceof User) {
      return $this->error('Inicia sesion para usar el streaming.', 401);
    }
    $session_id = $this->cleanId((string) $request->query->get('sessionId', ''));
    if ($session_id === '' || !$this->activeStreamSessionRow((int) $account->id(), $session_id)) {
      return $this->error('Sesion de streaming no encontrada.', 404);
    }
    $path = $this->streamFramePath((int) $account->id(), $session_id, FALSE);
    $since = max(0, (int) $request->query->get('since', 0));
    $wait_ms = min(800, max(0, (int) $request->query->get('wait', 0)));
    $deadline = microtime(TRUE) + ($wait_ms / 1000);
    $sequence_path = $path === '' ? '' : $path . '.seq';
    $sequence = 0;
    do {
      if ($path !== '' && is_file($path)) {
        $sequence = is_file($sequence_path)
          ? (int) trim((string) @file_get_contents($sequence_path))
          : ((int) (@filemtime($path) ?: 0) * 1000);
        if ($sequence <= 0 || $sequence > $since) {
          break;
        }
      }
      if (microtime(TRUE) >= $deadline) {
        break;
      }
      usleep(50000);
    } while (TRUE);
    if ($path === '' || !is_file($path)) {
      return new JsonResponse(['ok' => TRUE, 'frame' => FALSE]);
    }
    if ($sequence > 0 && $sequence <= $since) {
      return new JsonResponse(['ok' => TRUE, 'frame' => FALSE, 'sequence' => $sequence]);
    }
    $binary = @file_get_contents($path);
    if ($binary === FALSE || $binary === '') {
      return new JsonResponse(['ok' => TRUE, 'frame' => FALSE]);
    }
    $mime_path = $path . '.mime';
    $mime = is_file($mime_path) ? trim((string) @file_get_contents($mime_path)) : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], TRUE)) {
      $mime = $this->streamFrameMime($binary, '');
    }

    return new JsonResponse([
      'ok' => TRUE,
      'frame' => TRUE,
      'sequence' => $sequence,
      'mime' => $mime !== '' ? $mime : 'image/jpeg',
      'image' => base64_encode($binary),
    ]);
  }

  public function status(): JsonResponse {
    $account = $this->currentUser();
    return new JsonResponse([
      'authenticated' => $account->isAuthenticated(),
      'uid' => $account->isAuthenticated() ? (int) $account->id() : 0,
      'name' => $account->isAuthenticated() ? $account->getDisplayName() : '',
    ]);
  }

  private function friendRows(int $uid, string $kind): array {
    $query = \Drupal::database()->select('nelkano_friendship', 'f')->fields('f');
    if ($kind === 'accepted') {
      $or = $query->orConditionGroup()
        ->condition('requester_uid', $uid)
        ->condition('recipient_uid', $uid);
      $query->condition($or)->condition('status', 'accepted');
    }
    elseif ($kind === 'incoming') {
      $query->condition('recipient_uid', $uid)->condition('status', 'pending');
    }
    else {
      $query->condition('requester_uid', $uid)->condition('status', 'pending');
    }
    $rows = $query->orderBy('updated', 'DESC')->range(0, 100)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
      $other_uid = (int) $row['requester_uid'] === $uid ? (int) $row['recipient_uid'] : (int) $row['requester_uid'];
      $user = User::load($other_uid);
      if ($user instanceof User) {
        $out[] = $this->friendUserPayload($user, (int) $row['id'], (string) $row['status']);
      }
    }
    return $out;
  }

  private function friendUserPayload(User $user, int $friendship_id, string $status): array {
    $profile = $this->appProfilePayload($user);
    return [
      'uid' => (int) $user->id(),
      'friendshipId' => $friendship_id,
      'name' => $profile['displayName'],
      'email' => (string) $user->getEmail(),
      'status' => $status,
      'displayName' => $profile['displayName'],
      'avatarUrl' => $profile['avatarUrl'],
      'avatarColor' => $profile['avatarColor'],
      'initial' => $profile['initial'],
      'statusText' => $profile['statusText'],
    ];
  }

  private function appProfilePayload(User $user): array {
    $uid = (int) $user->id();
    $row = [];
    $database = \Drupal::database();
    if ($database->schema()->tableExists('nelkano_profile')) {
      $row = $database->select('nelkano_profile', 'p')
        ->fields('p', ['display_name', 'status_text', 'avatar_color', 'avatar_file_uri'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchAssoc() ?: [];
    }
    $display_name = trim((string) ($row['display_name'] ?? '')) ?: $user->getDisplayName();
    $avatar_uri = trim((string) ($row['avatar_file_uri'] ?? ''));
    return [
      'displayName' => $this->cleanText($display_name, 80),
      'statusText' => $this->cleanText(trim((string) ($row['status_text'] ?? '')), 140),
      'avatarColor' => (string) ($row['avatar_color'] ?? '#a414ff'),
      'avatarUrl' => $avatar_uri !== '' ? \Drupal::service('file_url_generator')->generateString($avatar_uri) : '',
      'initial' => mb_strtoupper(mb_substr($display_name, 0, 1)),
    ];
  }

  private function friendshipBetween(int $uid_a, int $uid_b): ?array {
    if (!\Drupal::database()->schema()->tableExists('nelkano_friendship')) {
      return NULL;
    }
    $query = \Drupal::database()->select('nelkano_friendship', 'f')->fields('f')->range(0, 1);
    $pair_a = $query->andConditionGroup()->condition('requester_uid', $uid_a)->condition('recipient_uid', $uid_b);
    $pair_b = $query->andConditionGroup()->condition('requester_uid', $uid_b)->condition('recipient_uid', $uid_a);
    $query->condition($query->orConditionGroup()->condition($pair_a)->condition($pair_b));
    $row = $query->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  private function areFriends(int $uid_a, int $uid_b): bool {
    $row = $this->friendshipBetween($uid_a, $uid_b);
    return $row && (string) ($row['status'] ?? '') === 'accepted';
  }

  private function friendDecision(Request $request, string $action): JsonResponse {
    $account = $this->accountFromBearer($request);
    if (!$account instanceof User) {
      return $this->error('Token no valido o caducado.', 401);
    }
    $payload = json_decode((string) $request->getContent(), TRUE);
    $id = (int) (($payload['friendshipId'] ?? 0) ?: ($payload['id'] ?? 0));
    if ($id <= 0 || !\Drupal::database()->schema()->tableExists('nelkano_friendship')) {
      return $this->error('Solicitud no valida.', 400);
    }
    $database = \Drupal::database();
    $row = $database->select('nelkano_friendship', 'f')->fields('f')->condition('id', $id)->range(0, 1)->execute()->fetchAssoc();
    if (!$row) {
      return $this->error('Solicitud no encontrada.', 404);
    }
    $uid = (int) $account->id();
    $now = \Drupal::time()->getRequestTime();
    if ($action === 'accept') {
      if ((int) $row['recipient_uid'] !== $uid || (string) $row['status'] !== 'pending') {
        return $this->error('No puedes aceptar esta solicitud.', 403);
      }
      $database->update('nelkano_friendship')->fields(['status' => 'accepted', 'updated' => $now])->condition('id', $id)->execute();
      return new JsonResponse(['ok' => TRUE]);
    }
    if ((int) $row['requester_uid'] !== $uid && (int) $row['recipient_uid'] !== $uid) {
      return $this->error('No puedes modificar esta amistad.', 403);
    }
    $database->delete('nelkano_friendship')->condition('id', $id)->execute();
    return new JsonResponse(['ok' => TRUE]);
  }

  private function activeGameSessionRow(string $session_id): ?array {
    if ($session_id === '' || !\Drupal::database()->schema()->tableExists('nelkano_game_session')) {
      return NULL;
    }
    $now = \Drupal::time()->getRequestTime();
    $row = \Drupal::database()->select('nelkano_game_session', 's')
      ->fields('s')
      ->condition('session_id', $session_id)
      ->condition('status', ['pending', 'active'], 'IN')
      ->condition('expires', $now, '>=')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  private function gameSessionAllows(int $uid, array $row): bool {
    return $uid > 0 && ((int) ($row['host_uid'] ?? 0) === $uid || (int) ($row['guest_uid'] ?? 0) === $uid);
  }

  private function gameSessionPayload(array $row, string $role, int $friend_uid): array {
    return [
      'sessionId' => (string) ($row['session_id'] ?? ''),
      'status' => (string) ($row['status'] ?? ''),
      'role' => $role,
      'peerRole' => $role === 'host' ? 'guest' : 'host',
      'friendUid' => $friend_uid,
      'system' => (string) ($row['system'] ?? ''),
      'mode' => (string) ($row['mode'] ?? ''),
      'expiresAt' => (int) ($row['expires'] ?? 0),
    ];
  }

  private function accountFromRequest(Request $request): ?User {
    $account = $this->accountFromBearer($request);
    if ($account instanceof User) {
      return $account;
    }
    $current = \Drupal::currentUser();
    if (!$current->isAuthenticated()) {
      return NULL;
    }
    $loaded = User::load((int) $current->id());
    return $loaded instanceof User && $loaded->isActive() ? $loaded : NULL;
  }

  private function loadAccountForLogin(string $login): ?User {
    $storage = $this->entityTypeManager()->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('mail', $login)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('name', $login)
        ->range(0, 1)
        ->execute();
    }
    $uid = $ids ? (int) reset($ids) : 0;
    return $uid > 0 ? $storage->load($uid) : NULL;
  }

  private function createInitialProfile(int $uid, string $display_name): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('nelkano_profile')) {
      return;
    }

    $now = \Drupal::time()->getRequestTime();
    $database->merge('nelkano_profile')
      ->key('uid', $uid)
      ->fields([
        'display_name' => $display_name,
        'status_text' => '',
        'avatar_color' => '#a414ff',
        'avatar_file_uri' => '',
        'updated' => $now,
      ])
      ->insertFields([
        'uid' => $uid,
        'display_name' => $display_name,
        'status_text' => '',
        'avatar_color' => '#a414ff',
        'avatar_file_uri' => '',
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
  }

  private function isLocalHost(Request $request): bool {
    $host = strtolower($request->getHttpHost());
    return str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
  }

  private function accountFromBearer(Request $request): ?User {
    $header = trim((string) $request->headers->get('Authorization', ''));
    if (!preg_match('/^Bearer\s+(\d+)\.([a-f0-9]{64})$/i', $header, $matches)) {
      return NULL;
    }

    $uid = (int) $matches[1];
    $secret = $matches[2];
    $account = User::load($uid);
    if (!$account instanceof User || !$account->isActive()) {
      return NULL;
    }

    $user_data = \Drupal::service('user.data');
    $expires = (int) ($user_data->get('nelkano_home', $uid, 'app_token_expires') ?? 0);
    $expected = (string) ($user_data->get('nelkano_home', $uid, 'app_token_hash') ?? '');
    $hash = hash('sha256', $secret);
    $tokens = is_array($user_data->get('nelkano_home', $uid, 'app_tokens'))
      ? $user_data->get('nelkano_home', $uid, 'app_tokens')
      : [];
    $now = \Drupal::time()->getRequestTime();
    $valid = isset($tokens[$hash]) && (int) $tokens[$hash] >= $now;
    if (!$valid && $expected !== '' && $expires >= $now && hash_equals($expected, $hash)) {
      $tokens[$hash] = $expires;
      $valid = TRUE;
    }
    if (!$valid) {
      return NULL;
    }

    $tokens = array_filter($tokens, static fn($token_expires) => (int) $token_expires >= $now);
    $tokens[$hash] = $now + self::TOKEN_TTL;
    $user_data->set('nelkano_home', $uid, 'app_tokens', $tokens);
    $user_data->set('nelkano_home', $uid, 'app_token_last_used', \Drupal::time()->getRequestTime());
    return $account;
  }

  private function userPayload(User $account): array {
    return [
      'uid' => (int) $account->id(),
      'name' => $account->getDisplayName(),
      'email' => (string) $account->getEmail(),
    ];
  }

  private function upsertLibraryItem(int $uid, string $client_id, array $data): array {
    $database = \Drupal::database();
    $existing = $this->libraryItemByClientId($uid, $client_id);
    if ($existing) {
      $database->update('nelkano_library_item')
        ->fields([
          'system' => $data['system'],
          'display_name' => $data['display_name'],
          'favorite' => $data['favorite'],
          'added_at' => max((int) $existing['added_at'], (int) $data['added_at']),
          'last_played_at' => max((int) $existing['last_played_at'], (int) $data['last_played_at']),
          'total_play_ms' => max((int) $existing['total_play_ms'], (int) $data['total_play_ms']),
          'save_count' => max((int) $existing['save_count'], (int) $data['save_count']),
          'updated_at' => $data['updated_at'],
        ])
        ->condition('id', (int) $existing['id'])
        ->execute();
      return $this->libraryItemByClientId($uid, $client_id) ?: $existing;
    }

    $database->insert('nelkano_library_item')
      ->fields([
        'uid' => $uid,
        'client_item_id' => $client_id,
      ] + $data)
      ->execute();
    return $this->libraryItemByClientId($uid, $client_id) ?: [];
  }

  private function mergeLibraryAliases(int $uid, string $canonical_client_id, array $aliases, array $canonical_row, int $now): array {
    $database = \Drupal::database();
    $canonical = $canonical_row;
    foreach ($aliases as $alias_id) {
      $alias = $this->libraryItemByClientId($uid, $alias_id);
      if (!$alias || (int) $alias['id'] === (int) $canonical['id']) {
        continue;
      }

      $database->update('nelkano_play_session')
        ->fields(['library_item_id' => (int) $canonical['id']])
        ->condition('uid', $uid)
        ->condition('library_item_id', (int) $alias['id'])
        ->execute();

      $database->update('nelkano_library_item')
        ->fields([
          'favorite' => !empty($canonical['favorite']) || !empty($alias['favorite']) ? 1 : 0,
          'added_at' => max((int) $canonical['added_at'], (int) $alias['added_at']),
          'last_played_at' => max((int) $canonical['last_played_at'], (int) $alias['last_played_at']),
          'total_play_ms' => max(0, (int) $canonical['total_play_ms']) + max(0, (int) $alias['total_play_ms']),
          'play_count' => max(0, (int) $canonical['play_count']) + max(0, (int) $alias['play_count']),
          'save_count' => max((int) $canonical['save_count'], (int) $alias['save_count']),
          'updated_at' => $now,
        ])
        ->condition('id', (int) $canonical['id'])
        ->execute();

      $database->update('nelkano_library_item')
        ->fields([
          'deleted_at' => $now,
          'updated_at' => $now,
        ])
        ->condition('id', (int) $alias['id'])
        ->execute();

      $canonical = $this->libraryItemByClientId($uid, $canonical_client_id) ?: $canonical;
    }
    return $canonical;
  }

  private function libraryItemByClientId(int $uid, string $client_id): ?array {
    $row = \Drupal::database()->select('nelkano_library_item', 'i')
      ->fields('i')
      ->condition('uid', $uid)
      ->condition('client_item_id', $client_id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  private function activityDisplayName(string $system): string {
    $labels = [
      'CHIP8' => 'Archivo compatible CHIP-8',
      'GAME_BOY' => 'Archivo compatible Game Boy',
      'GBC' => 'Archivo compatible Game Boy Color',
      'GBA' => 'Archivo compatible Game Boy Advance',
      'NES' => 'Archivo compatible NES',
      'NDS' => 'Archivo compatible Nintendo DS',
    ];
    $key = strtoupper($system);
    return $labels[$key] ?? 'Archivo compatible';
  }

  private function activityPayload(int $uid): array {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('nelkano_library_item')) {
      return ['stats' => ['recent' => 0, 'totalPlayMs' => 0, 'platforms' => 0], 'items' => [], 'recent' => [], 'favorites' => []];
    }
    $summary_query = $database->select('nelkano_library_item', 'i')
      ->condition('uid', $uid)
      ->condition('deleted_at', 0);
    $summary_query->addExpression('COALESCE(SUM(total_play_ms), 0)', 'total_play_ms');
    $summary_query->addExpression('COUNT(DISTINCT system)', 'platform_count');
    $summary_query->addExpression('SUM(CASE WHEN last_played_at > 0 THEN 1 ELSE 0 END)', 'recent_count');
    $summary = $summary_query->execute()->fetchAssoc() ?: [];

    $items = $database->select('nelkano_library_item', 'i')
      ->fields('i')
      ->condition('uid', $uid)
      ->condition('deleted_at', 0)
      ->condition('last_played_at', 0, '>')
      ->orderBy('last_played_at', 'DESC')
      ->range(0, 8)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $favorite_items = $database->select('nelkano_library_item', 'i')
      ->fields('i')
      ->condition('uid', $uid)
      ->condition('deleted_at', 0)
      ->condition('favorite', 1)
      ->orderBy('last_played_at', 'DESC')
      ->range(0, 8)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $all_items = $database->select('nelkano_library_item', 'i')
      ->fields('i')
      ->condition('uid', $uid)
      ->condition('deleted_at', 0)
      ->orderBy('last_played_at', 'DESC')
      ->range(0, 250)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $recent = [];
    foreach ($items as $row) {
      $recent[] = [
        'id' => (string) $row['client_item_id'],
        'system' => (string) $row['system'],
        'displayName' => (string) $row['display_name'],
        'favorite' => (bool) $row['favorite'],
        'lastPlayedAt' => ((int) $row['last_played_at']) * 1000,
        'playMillis' => (int) $row['total_play_ms'],
        'playCount' => (int) $row['play_count'],
        'saveCount' => (int) $row['save_count'],
      ];
    }

    $favorites = [];
    foreach ($favorite_items as $row) {
      $favorites[] = [
        'id' => (string) $row['client_item_id'],
        'system' => (string) $row['system'],
        'displayName' => (string) $row['display_name'],
        'favorite' => (bool) $row['favorite'],
        'lastPlayedAt' => ((int) $row['last_played_at']) * 1000,
        'playMillis' => (int) $row['total_play_ms'],
        'playCount' => (int) $row['play_count'],
        'saveCount' => (int) $row['save_count'],
      ];
    }

    $all = [];
    foreach ($all_items as $row) {
      $all[] = [
        'id' => (string) $row['client_item_id'],
        'system' => (string) $row['system'],
        'displayName' => (string) $row['display_name'],
        'favorite' => (bool) $row['favorite'],
        'lastPlayedAt' => ((int) $row['last_played_at']) * 1000,
        'playMillis' => (int) $row['total_play_ms'],
        'playCount' => (int) $row['play_count'],
        'saveCount' => (int) $row['save_count'],
      ];
    }
    return [
      'stats' => [
        'recent' => (int) ($summary['recent_count'] ?? 0),
        'totalPlayMs' => (int) ($summary['total_play_ms'] ?? 0),
        'platforms' => (int) ($summary['platform_count'] ?? 0),
      ],
      'items' => $all,
      'recent' => $recent,
      'favorites' => $favorites,
    ];
  }

  private function streamSessionPayload(array $row): array {
    return [
      'sessionId' => (string) ($row['session_id'] ?? ''),
      'pin' => (string) ($row['pin'] ?? ''),
      'joinUri' => (string) ($row['join_uri'] ?? ''),
      'signalingUrl' => (string) ($row['signaling_url'] ?? ''),
      'deviceName' => (string) ($row['device_name'] ?? 'Android'),
      'platform' => (string) ($row['platform'] ?? 'android'),
      'updatedAt' => (int) ($row['updated'] ?? 0),
      'expiresAt' => (int) ($row['expires'] ?? 0),
    ];
  }

  private function activeStreamSessionRow(int $uid, string $session_id): ?array {
    if ($uid <= 0 || $session_id === '') {
      return NULL;
    }
    $now = \Drupal::time()->getRequestTime();
    $row = \Drupal::database()->select('nelkano_stream_session', 's')
      ->fields('s')
      ->condition('uid', $uid)
      ->condition('session_id', $session_id)
      ->condition('status', 'active')
      ->condition('expires', $now, '>=')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  private function activeStreamSessionRowForSignaling(int $uid, string $session_id): ?array {
    $row = $this->activeStreamSessionRow($uid, $session_id);
    if ($row) {
      return $row;
    }
    return $this->activeGbaLinkSessionRow($session_id);
  }

  private function activeGbaLinkSessionRow(string $session_id): ?array {
    if (!preg_match('/^\d{6}$/', $session_id)) {
      return NULL;
    }
    $now = \Drupal::time()->getRequestTime();
    $row = \Drupal::database()->select('nelkano_stream_session', 's')
      ->fields('s')
      ->condition('session_id', $session_id)
      ->condition('pin', $session_id)
      ->condition('status', 'active')
      ->condition('expires', $now, '>=')
      ->orderBy('updated', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  private function streamFramePath(int $uid, string $session_id, bool $create = TRUE): string {
    if ($uid <= 0 || $session_id === '') {
      return '';
    }
    $file_system = \Drupal::service('file_system');
    if (!$file_system instanceof FileSystemInterface) {
      return '';
    }
    $directory = 'public://nelkano-stream/' . $uid;
    if ($create) {
      $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    $real_directory = $file_system->realpath($directory);
    if (!is_string($real_directory) || $real_directory === '') {
      return '';
    }
    return $real_directory . DIRECTORY_SEPARATOR . $session_id . '.frame';
  }

  private function streamFrameMime(string $binary, string $requested): string {
    $requested = strtolower(trim($requested));
    if ($requested === 'image/jpeg' && substr($binary, 0, 2) === "\xFF\xD8") {
      return 'image/jpeg';
    }
    if ($requested === 'image/png' && substr($binary, 0, 8) === "\x89PNG\r\n\x1A\n") {
      return 'image/png';
    }
    if ($requested === 'image/webp' && substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP') {
      return 'image/webp';
    }
    if (substr($binary, 0, 2) === "\xFF\xD8") {
      return 'image/jpeg';
    }
    if (substr($binary, 0, 8) === "\x89PNG\r\n\x1A\n") {
      return 'image/png';
    }
    if (substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP') {
      return 'image/webp';
    }
    return '';
  }

  private function cleanId(string $value): string {
    return substr(preg_replace('/[^a-zA-Z0-9._:-]/', '', $value) ?? '', 0, 128);
  }

  private function cleanText(string $value, int $length): string {
    return mb_substr(trim(strip_tags($value)), 0, $length);
  }

  private function millisToSeconds(int $millis): int {
    return $millis > 0 ? (int) floor($millis / 1000) : 0;
  }

  private function error(string $message, int $status): JsonResponse {
    return new JsonResponse([
      'ok' => FALSE,
      'message' => $message,
    ], $status);
  }

}
