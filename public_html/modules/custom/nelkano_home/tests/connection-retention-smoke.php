<?php

/** Local-only fixture: explicit temporary UIDs, rollback DB, exact file cleanup. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_CONNECTION_RETENTION_TEST') !== '1') { throw new RuntimeException('Local Docker fixture only.'); }
if (getenv('NELKANO_CONNECTION_RETENTION_ENABLED') === '1') { throw new RuntimeException('Disable global scheduled retention for this fixture.'); }

use Drupal\Core\File\FileSystemInterface;
use Drupal\nelkano_home\Service\ConnectionPrivacyRetention;
use Drupal\user\Entity\User;

$db = \Drupal::database(); $fs = \Drupal::service('file_system');
$tx = $db->startTransaction(); $files = []; $directories = []; $checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  echo 'PASS: ' . $label . PHP_EOL; $checks++;
};
$now = \Drupal::time()->getCurrentTime(); $tag = 'retention-' . bin2hex(random_bytes(8));
$count = static fn(string $table, array $uids): int => (int) $db->select($table, 's')->condition('uid', $uids, 'NOT IN')->countQuery()->execute()->fetchField();
$suffixes = ['frame', 'frame.seq', 'frame.mime', 'audio', 'audio.seq', 'audio.rate', 'audio.channels', 'audio.duration'];
try {
  $uids = [];
  foreach (['owner', 'foreign'] as $name) {
    $user = User::create(['name' => $tag . '-' . $name, 'mail' => $tag . '-' . $name . '@example.invalid', 'status' => 1]);
    $user->save(); $uids[] = (int) $user->id();
  }
  [$owner, $foreign] = $uids;
  $foreignBaseline = $count('nelkano_stream_session', $uids);
  $makeFiles = static function (int $uid, string $session) use ($fs, &$files, &$directories, $suffixes): array {
    $created = [];
    foreach (['public', 'private'] as $scheme) {
      $dir = $scheme . '://nelkano-stream/' . $uid;
      if (!$fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY)) { throw new RuntimeException('Fixture storage unavailable.'); }
      $real = $fs->realpath($dir); $directories[$real] = $real;
      foreach ($suffixes as $suffix) {
        $path = $real . DIRECTORY_SEPARATOR . $session . '.' . $suffix;
        if (file_exists($path) || is_link($path)) { throw new RuntimeException('Fixture collision.'); }
        file_put_contents($path, 'synthetic fixture'); $files[$path] = $path; $created[] = $path;
      }
    }
    return $created;
  };
  $stream = static function (int $uid, string $sid, int $expires, string $status = 'active') use ($db, $now): int {
    return (int) $db->insert('nelkano_stream_session')->fields(['uid' => $uid, 'device_id' => 'fixture', 'session_id' => $sid, 'status' => $status, 'created' => $now - 100, 'updated' => $now - 100, 'expires' => $expires])->execute();
  };
  $expired = $tag . '-expired'; $active = $tag . '-active'; $other = $tag . '-other';
  $stream($owner, $expired, $now - 1);
  $stream($owner, $active, $now + 7200);
  $stream($owner, $active, $now - 100, 'closed'); // ID reuse must preserve active files/signals.
  $stream($foreign, $other, $now - 1);
  $expiredFiles = $makeFiles($owner, $expired); $activeFiles = $makeFiles($owner, $active); $foreignFiles = $makeFiles($foreign, $other);
  foreach ([[$owner, $expired], [$owner, $active], [$foreign, $other]] as [$uid, $sid]) {
    $db->insert('nelkano_stream_signal_event')->fields(['uid' => $uid, 'session_id' => $sid, 'type' => 'fixture', 'created' => $now, 'payload' => '{}'])->execute();
  }
  $old = (int) $db->insert('nelkano_stream_signal_event')->fields(['uid' => $owner, 'session_id' => $active, 'type' => 'old-fixture', 'created' => $now - 7201, 'payload' => '{}'])->execute();
  $game = $tag . '-game';
  $db->insert('nelkano_game_session')->fields(['session_id' => $game, 'host_uid' => $owner, 'guest_uid' => 0, 'status' => 'closed', 'expires' => $now - 1])->execute();
  $db->insert('nelkano_game_signal_event')->fields(['session_id' => $game, 'sender_uid' => $owner, 'type' => 'fixture', 'created' => $now, 'payload' => '{}'])->execute();
  ConnectionPrivacyRetention::cleanup([$owner]);
  $check(count(array_filter($expiredFiles, 'file_exists')) === 0, 'expired stream removes all public/private frame and audio sidecars');
  $check(count(array_filter($activeFiles, 'file_exists')) === 16, 'active stream and duplicate ID files survive');
  $check(count(array_filter($foreignFiles, 'file_exists')) === 16, 'foreign UID files survive scoped cleanup');
  $check((int) $db->select('nelkano_stream_session', 's')->condition('uid', $foreign)->countQuery()->execute()->fetchField() === 1, 'foreign UID session survives');
  $check((int) $db->select('nelkano_stream_signal_event', 'e')->condition('uid', $owner)->condition('session_id', $active)->countQuery()->execute()->fetchField() === 1, 'recent active signal survives while old signal expires');
  $check((int) $db->select('nelkano_game_session', 's')->condition('session_id', $game)->countQuery()->execute()->fetchField() === 0, 'closed game session removed');
  $check((int) $db->select('nelkano_game_signal_event', 'e')->condition('session_id', $game)->countQuery()->execute()->fetchField() === 0, 'closed game signals removed');
  $check($count('nelkano_stream_session', $uids) === $foreignBaseline, 'unrelated stream row count unchanged');
  // Unknown files must block account preflight rather than be removed blindly.
  $unknown = $fs->realpath('private://nelkano-stream/' . $owner) . '/unrecognized.txt';
  file_put_contents($unknown, 'fixture'); $files[$unknown] = $unknown; $rejected = FALSE;
  try { ConnectionPrivacyRetention::preflightAccountFiles($owner); } catch (RuntimeException $error) { $rejected = TRUE; }
  $check($rejected && file_exists($unknown), 'unrecognized account files are preserved for manual review');
  unlink($unknown);
  ConnectionPrivacyRetention::preflightAccountFiles($owner);
  ConnectionPrivacyRetention::purgeAccountFiles($owner);
  $check(count(array_filter($activeFiles, 'file_exists')) === 0, 'account file purge removes its remaining exact fallback files');
  $check(count(array_filter($foreignFiles, 'file_exists')) === 16, 'account file purge preserves foreign UID');
  foreach(['watchdog'=>['wid','timestamp'],'sessions'=>['sid','timestamp']] as $table=>[$id,$date]) {
    if(!$db->schema()->tableExists($table))continue;
    $keys=[];
    foreach([[$owner,31],[$owner,29],[$foreign,31]] as [$uid,$days]) {
      $fields=['uid'=>$uid,$date=>$now-$days*86400];
      if($table==='watchdog')$fields+=['type'=>'retention-fixture','message'=>'synthetic','variables'=>serialize([]),'severity'=>6,'link'=>'','location'=>'','referer'=>'','hostname'=>'127.0.0.249'];
      else $fields+=['sid'=>hash('sha256',$tag.'-'.count($keys)),'hostname'=>'127.0.0.249','session'=>'synthetic'];
      $created=$db->insert($table)->fields($fields)->execute();$keys[]=$table==='sessions'?$fields['sid']:$created;
    }
    ConnectionPrivacyRetention::cleanupTechnicalRows([$owner]);
    $exists=static fn($key):bool=>$db->select($table,'t')->condition($id,$key)->countQuery()->execute()->fetchField()>0;
    $check(!$exists($keys[0]) && $exists($keys[1]) && $exists($keys[2]),$table.': deletes only scoped rows older than 30 days');
  }
  $appIds=[];
  foreach([[$owner,2],[$owner,0],[$foreign,2]] as [$uid,$days]) {
    $unique=hash('sha256',$tag.'-token-'.count($appIds));
    $appIds[]=$db->insert('nelkano_app_session')->fields(['uid'=>$uid,'family_id'=>$unique,'access_hash'=>$unique,'refresh_hash'=>hash('sha256',$unique),'refresh_expires'=>$now-$days*86400])->execute();
  }
  \Drupal\nelkano_home\Service\AppSessions::cleanup([$owner]);
  $exists=static fn($key):bool=>$db->select('nelkano_app_session','t')->condition('id',$key)->countQuery()->execute()->fetchField()>0;
  $check(!$exists($appIds[0]) && $exists($appIds[1]) && $exists($appIds[2]),'expired app-token cleanup preserves recent and foreign rows');
  $check(\Drupal\nelkano_home\Service\AppSessions::cleanup([])===0,'empty token-cleanup scope cannot become global');
  echo 'PASS: ' . $checks . ' connection-retention checks' . PHP_EOL;
}
finally {
  foreach ($files as $path) { if (is_file($path) && !is_link($path)) { @unlink($path); } }
  foreach ($directories as $path) { if (is_dir($path) && !is_link($path)) { @rmdir($path); } }
  $tx->rollBack();
  \Drupal::entityTypeManager()->getStorage('user')->resetCache();
}
