<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\CommunityBlocks;
use Drupal\nelkano_home\Service\CommunityModeration;
use Drupal\nelkano_home\Service\CommunityRules;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CommunitySafetyController extends ControllerBase {

  public function posts(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $account = $this->currentUser();
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'community_post')->condition('status', 1)
      ->sort('created', 'DESC')->sort('nid', 'DESC')->pager(25)->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $post) {
      if ($post->access('view', $account)) {
        $items[] = Link::fromTextAndUrl($post->label(), $post->toUrl())->toRenderable();
      }
    }
    return [
      '#cache' => ['max-age' => 0],
      'rules' => Link::fromTextAndUrl($this->t('Normas de Comunidad'), Url::fromRoute('nelkano_home.community_rules_es'))->toRenderable(),
      'manage_rules' => CommunityRules::accepted((int) $account->id()) ? [] : Link::fromTextAndUrl(
        $this->t('Gestionar la aceptación en Perfil social'), Url::fromUserInput('/user'))->toRenderable(),
      'blocks' => Link::fromTextAndUrl($this->t('Usuarios bloqueados'), Url::fromRoute('nelkano_home.community_blocked_users'))->toRenderable(),
      'posts' => ['#theme' => 'item_list', '#items' => $items, '#empty' => $this->t('No hay publicaciones visibles en esta página.')],
      'pager' => ['#type' => 'pager'],
    ];
  }

  public function blockedUsers(): array {
    $items = [];
    foreach (CommunityBlocks::blockedUsers((int) $this->currentUser()->id()) as $user) {
      $items[] = Link::fromTextAndUrl($user['name'], Url::fromRoute('nelkano_home.community_block', ['target' => $user['uid']],
        ['query' => ['destination' => Url::fromRoute('nelkano_home.community_blocked_users')->toString()]]))->toRenderable();
    }
    return [
      '#cache' => ['max-age' => 0],
      'community' => Link::fromTextAndUrl($this->t('Volver a Comunidad'), Url::fromRoute('nelkano_home.community_posts'))->toRenderable(),
      'users' => ['#theme' => 'item_list', '#items' => $items, '#empty' => $this->t('No tienes usuarios bloqueados.')],
    ];
  }

  public function action(Request $request): JsonResponse {
    $headers = ['Cache-Control' => 'private, no-store', 'Vary' => 'Authorization', 'X-Content-Type-Options' => 'nosniff'];
    try {
      $account = \Drupal::currentUser();
      if (!$account->isAuthenticated() || !\Drupal::service('nelkano_home.community_safety_authentication')->applies($request)) {
        throw new HttpException(401, 'Inicia sesión en Nelkano.');
      }
      if (!$account->hasPermission('use nelkano app features')) {
        throw new HttpException(403, 'Esta cuenta no tiene acceso a Comunidad.');
      }
      if (strlen($request->getContent()) > 2048) {
        throw new HttpException(413, 'Petición demasiado grande.');
      }
      $body = json_decode($request->getContent(), TRUE, 8);
      if (!is_array($body)) {
        throw new HttpException(400, 'Petición no válida.');
      }
      $action = $body['action'] ?? NULL;
      if ($action === 'rules-status' || $action === 'accept-rules') {
        $uid = (int) $account->id();
        if ($action === 'accept-rules' && (!is_string($body['version'] ?? NULL) || !CommunityRules::accept($uid, $body['version']))) {
          throw new HttpException(409, 'La versión de las normas ha cambiado. Vuelve a abrirlas.');
        }
        return new JsonResponse([
          'version' => CommunityRules::VERSION, 'accepted' => CommunityRules::accepted($uid),
          'es' => CommunityRules::copy(FALSE), 'en' => CommunityRules::copy(TRUE),
        ], 200, $headers);
      }
      if ($action === 'blocked-users') {
        return new JsonResponse(['users' => CommunityBlocks::blockedUsers((int) $account->id())], 200, $headers);
      }
      if (!is_string($body['target'] ?? NULL) || !is_string($body['kind'] ?? NULL)) {
        throw new HttpException(400, 'Petición no válida.');
      }
      $target = $body['target'];
      if ($action === 'report') {
        if (!is_string($body['reason'] ?? NULL)) {
          throw new HttpException(400, 'Elige un motivo.');
        }
        CommunityModeration::submit($account, $body['kind'], $target, $body['reason']);
        return new JsonResponse(['ok' => TRUE], 200, $headers);
      }
      $uid = (int) $account->id();
      if ($body['kind'] !== 'user' || !preg_match('/^[1-9][0-9]{0,9}$/D', $target) || (int) $target === $uid ||
          !CommunityModeration::target('user', $target)) {
        throw new HttpException(404, 'El perfil no está disponible.');
      }
      $peer = (int) $target;
      if ($action === 'block-status') {
        $settings = CommunityBlocks::preferences($uid, $peer);
      }
      elseif ($action === 'set-block' && is_bool($body['blocked'] ?? NULL)) {
        $settings = CommunityBlocks::change(\Drupal::database(), $uid, $peer, ['blocked' => $body['blocked']]);
      }
      else {
        throw new HttpException(400, 'Acción no válida.');
      }
      return new JsonResponse(['ok' => TRUE, 'blocked' => $settings['blocked']], 200, $headers);
    }
    catch (HttpExceptionInterface $error) {
      return new JsonResponse(['ok' => FALSE, 'message' => $error->getMessage()], $error->getStatusCode(), $headers);
    }
  }

}
