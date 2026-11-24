<?php

declare(strict_types=1);

use Drupal\comment\Entity\Comment;
use Drupal\comment\Entity\CommentType;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\nelkano_home\Service\AccountErasure;
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\nelkano_home\Service\CommunityModeration;
use Drupal\nelkano_home\Service\CommunityRules;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

if (getenv('DRUPAL_DB_HOST') !== 'database' || DRUPAL_ROOT !== '/opt/drupal/web') {
  throw new RuntimeException('Run only in Nelkano local Docker.');
}
$check = static function (bool $ok, string $label): void {
  if (!$ok) {
    throw new RuntimeException($label);
  }
  echo "PASS $label\n";
};
$marker = 'privacyqa_' . bin2hex(random_bytes(4));
$settings = Settings::getAll();
new Settings(array_replace($settings, [
  'nelkano_erasure_ledger_uri' => "private://nelkano-account-erasures-qa-$marker",
  'nelkano_erasure_registry_directory' => "/tmp/nelkano-account-erasure-$marker",
]));
$created = [];
$ensure = static function (?object $existing, callable $create) use (&$created): void {
  if (!$existing) {
    $entity = $create();
    $entity->save();
    $created[] = $entity;
  }
};
$field = static function (string $name, string $type, array $settings) use ($ensure): void {
  $ensure(FieldStorageConfig::loadByName('node', $name), static fn() => FieldStorageConfig::create([
    'entity_type' => 'node', 'field_name' => $name, 'type' => $type,
    'settings' => $type === 'image' ? ['uri_scheme' => 'private'] : [],
  ]));
  $ensure(FieldConfig::loadByName('node', 'community_post', $name), static fn() => FieldConfig::create([
    'entity_type' => 'node', 'bundle' => 'community_post', 'field_name' => $name, 'label' => $name,
    'settings' => $settings,
  ]));
};
$transaction = NULL;
$uris = [];
try {
  $ensure(NodeType::load('community_post'), static fn() => NodeType::create(['type' => 'community_post', 'name' => 'Community privacy fixture']));
  $ensure(NodeType::load($marker), static fn() => NodeType::create(['type' => $marker, 'name' => 'Editorial privacy fixture']));
  $ensure(CommentType::load('community_comment'), static fn() => CommentType::create([
    'id' => 'community_comment', 'label' => 'Community privacy fixture', 'target_entity_type_id' => 'node',
  ]));
  $field('field_community_author_uid', 'integer', []);
  $field('field_community_image', 'image', ['file_directory' => 'community']);
  $field('field_community_comments', 'comment', ['comment_type' => 'community_comment']);
  $db = \Drupal::database();
  $transaction = $db->startTransaction();
  $role = Role::create(['id' => $marker, 'label' => $marker, 'permissions' => ['access content', 'use nelkano app features']]);
  $role->save();
  $user = static function (string $suffix) use ($marker): User {
    $user = User::create(['name' => $marker . $suffix, 'mail' => "$marker$suffix@example.invalid", 'status' => 1, 'roles' => [$marker]]);
    $user->save();
    return $user;
  };
  $author = $user('a');
  $other = $user('b');
  $uid = (int) $author->id();
  $token = AppSessions::issue($author, [])['access_token'];
  $kernel = \Drupal::service('http_kernel');
  $api = static function (array $payload) use ($token): array {
    $request = Request::create('/api/nelkano/community/safety', 'POST', [], [], [],
      ['HTTP_AUTHORIZATION' => "Bearer $token", 'CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    $authentication = \Drupal::service('nelkano_home.community_safety_authentication');
    $account = $authentication->authenticate($request);
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($account);
    try {
      $response = (new \Drupal\nelkano_home\Controller\CommunitySafetyController())->action($request);
      return [$response->getStatusCode(), json_decode($response->getContent(), TRUE)];
    }
    finally {
      $switcher->switchBack();
    }
  };
  [$status, $result] = $api(['action' => 'rules-status']);
  $check($status === 200 && $result['accepted'] === FALSE && count($result['en']) === count($result['es']), 'existing accounts get the bilingual version before acceptance');
  $check($api(['action' => 'accept-rules', 'version' => 'obsolete'])[0] === 409, 'outdated rules cannot be accepted');
  $writes = [
    ['/jsonapi/node/community_post', 'POST', '', []],
    ['/jsonapi/node/community_post/field_community_image', 'POST', '', []],
    ['/jsonapi/comment/community_comment', 'POST', '', []],
    ['/eca/community-signal/' . $other->id(), 'POST', 'eca_endpoint.endpoint', []],
    ['/api/nelkano/community/groups/mailbox', 'POST', '', ['action' => 'send']],
    ['/api/nelkano/community/groups/mailbox', 'POST', '', ['action' => 'direct-send']],
    ['/api/nelkano/community/groups', 'POST', '', ['action' => 'create']],
  ];
  $subscriber = new \Drupal\nelkano_home\EventSubscriber\CommunitySignalAccessSubscriber($author);
  $gate = static function (array $entry) use ($kernel, $subscriber): bool {
    [$url, $method, $route, $payload] = $entry;
    $request = Request::create($url, $method, [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
    $request->attributes->set('_route', $route);
    try {
      $subscriber->checkSignal(new ControllerEvent($kernel, static fn() => NULL, $request, HttpKernelInterface::MAIN_REQUEST));
      return TRUE;
    }
    catch (AccessDeniedHttpException) {
      return FALSE;
    }
  };
  foreach ($writes as $write) {
    $check(!$gate($write), 'unaccepted write blocked at ' . $write[0]);
  }
  $check($gate(['/jsonapi/node/community_post', 'GET', '', []]), 'viewing needs no acceptance');
  $check($api(['action' => 'accept-rules', 'version' => CommunityRules::VERSION])[0] === 200, 'explicit acceptance succeeds');
  $check(CommunityRules::accepted($uid) && !CommunityRules::accepted((int) $other->id()), 'acceptance belongs only to the authenticated account');
  foreach ($writes as $write) {
    $check($gate($write), 'accepted write reaches controller at ' . $write[0]);
  }

  $directory = 'private://community';
  \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
  $image = static function (string $suffix) use ($directory, $marker, &$uris): int {
    $uri = "$directory/$marker$suffix.png";
    $uris[] = $uri;
    return (int) \Drupal::service('file.repository')->writeData(base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aFo0AAAAASUVORK5CYII='
    ), $uri)->id();
  };
  $exclusive = $image('exclusive');
  $shared = $image('shared');
  $post = Node::create(['type' => 'community_post', 'status' => 1, 'title' => 'Private fixture',
    'uid' => 0, 'field_community_author_uid' => $uid,
    'field_community_image' => ['target_id' => $exclusive, 'alt' => 'Fixture capture'],
    'field_community_comments' => ['status' => 2],
  ]);
  $post->save();
  $revision = $post->getRevisionId();
  $post->setNewRevision(TRUE);
  $post->set('field_community_image', ['target_id' => $shared, 'alt' => 'Shared fixture capture']);
  $post->save();
  $survivor = Node::create(['type' => 'community_post', 'status' => 1, 'title' => 'Other account fixture',
    'uid' => $other->id(), 'field_community_author_uid' => $other->id(),
    'field_community_image' => ['target_id' => $shared, 'alt' => 'Shared fixture capture'],
  ]);
  $survivor->save();
  $editorial = Node::create(['type' => $marker, 'uid' => $uid, 'title' => 'Editorial fixture']);
  $editorial->save();
  $editorial_id = $editorial->id();
  $comment = Comment::create(['comment_type' => 'community_comment', 'entity_type' => 'node',
    'field_name' => 'field_community_comments', 'uid' => $other->id(), 'entity_id' => $post->id(), 'subject' => 'Fixture reply', 'status' => 1,
  ]);
  $comment->save();
  $push = \Drupal::keyValueExpirable('nelkano_community_push');
  $device = 'device:' . $marker;
  $shared_device = $device . '_other';
  $device_binding = [$uid, 'fixture-binding'];
  $other_binding = [(int) $other->id(), 'other-binding'];
  $push->setWithExpire('devices:' . $uid, [
    $marker => ['binding' => 'fixture-binding'], $marker . '_other' => ['binding' => 'fixture-binding'],
  ], 120);
  $push->setWithExpire($device, $device_binding, 120);
  $push->setWithExpire($shared_device, $other_binding, 120);
  AccountErasure::erase($author);
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $check(User::load($uid) === NULL && !CommunityRules::accepted($uid), 'account and its acceptance are removed');
  $check($storage->loadUnchanged($post->id()) === NULL && $storage->loadRevision($revision) === NULL, 'post matched by auxiliary author and revisions removed');
  $check(\Drupal::entityTypeManager()->getStorage('comment')->loadUnchanged($comment->id()) === NULL, 'comment attached to deleted post removed');
  $check(!file_exists($uris[0]) && !\Drupal::entityTypeManager()->getStorage('file')->loadUnchanged($exclusive), 'exclusive historical capture removed from storage');
  $check(file_exists($uris[1]) && $storage->loadUnchanged($survivor->id())->isPublished(), 'other account post and shared capture retained');
  $editorial = $storage->loadUnchanged($editorial_id);
  $editorial_owner = $db->select('node_field_data', 'n')->fields('n', ['uid'])->condition('nid', $editorial_id)->execute()->fetchField();
  $check($editorial !== NULL && (int) $editorial_owner === 0 && (int) $editorial->getOwnerId() === 0, 'editorial content retained with stored and cached owner removed');
  $check($push->get('devices:' . $uid) === NULL && $push->get($device) === NULL, 'account notifications deregistered');
  $check($push->get($shared_device) === $other_binding, 'current notification registration belonging to a different account retained');

  $now = \Drupal::time()->getRequestTime();
  $reports = [];
  foreach (['hidden' => $now - 90 * 86400, 'dismissed' => $now - 89 * 86400, 'pending' => 0] as $status => $resolved) {
    $reports[$status] = $db->insert(CommunityModeration::TABLE)->fields([
      'reporter_uid' => $other->id(), 'target_id' => random_int(1_000_000_000, 2_000_000_000), 'owner_uid' => $other->id(),
      'kind' => 'post', 'reason' => 'spam', 'status' => $status, 'created' => $now - 100 * 86400, 'resolved' => $resolved,
    ])->execute();
  }
  CommunityModeration::cleanup();
  $remaining = $db->select(CommunityModeration::TABLE, 'r')->fields('r', ['status'])->condition('id', array_values($reports), 'IN')->execute()->fetchCol();
  $check(count($remaining) === 2 && in_array('dismissed', $remaining) && in_array('pending', $remaining), 'cleanup expires decisions at 90 days and retains unresolved and recent reports');
}
finally {
  $transaction?->rollBack();
  foreach ($uris as $uri) {
    if (file_exists($uri)) {
      \Drupal::service('file_system')->delete($uri);
    }
  }
  foreach (array_reverse($created) as $entity) {
    $storage = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId());
    $storage->loadUnchanged($entity->id())?->delete();
  }
  new Settings($settings);
}
