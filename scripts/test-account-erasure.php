<?php
/** Fixture-only account deletion rehearsal; never targets an existing account. */
declare(strict_types=1);
use Drupal\nelkano_home\Controller\AccountErasureController;
use Drupal\nelkano_home\Service\AccountErasure;
use Drupal\nelkano_home\Service\AccountErasureLedger;
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

if (getenv('NELKANO_ERASURE_QA') !== '1' || getenv('DRUPAL_DB_HOST') !== 'database' || DRUPAL_ROOT !== '/opt/drupal/web') {
  throw new RuntimeException('Only run deliberately in the local Docker Drupal service.');
}
$db = \Drupal::database(); $seed = 'eraseqa_' . bin2hex(random_bytes(6)); $uids = []; $uuids = []; $nids = []; $count = 0;
$check = static function(bool $value, string $message) use (&$count): void { if (!$value) throw new RuntimeException($message); $count++; };
if (getenv('NELKANO_ERASURE_QA_CLEAN_STALE') === '1') {
  $old = $db->select('users_field_data','u')->fields('u',['uid','name','mail','created'])->condition('name',$db->escapeLike('eraseqa_').'%', 'LIKE')->condition('created',time()-7200,'>=')->execute()->fetchAll(PDO::FETCH_ASSOC);
  $owned = [];
  foreach ($old as $row) {
    if (!preg_match('/^(eraseqa_[a-f0-9]{12})_(erase|keep)$/D',$row['name'],$match) || $row['mail']!==$row['name'].'@example.invalid') continue;
    $marker=\Drupal::service('user.data')->get('nelkano_home',(int)$row['uid'],'qa_private_data');
    if ($marker==='erase-me' || $marker===$match[1]) $owned[$match[1]]=TRUE;
  }
  foreach ($old as $row) {
    if (!preg_match('/^(eraseqa_[a-f0-9]{12})_(erase|keep)$/D',$row['name'],$match) || !isset($owned[$match[1]]) || $row['mail']!==$row['name'].'@example.invalid') continue;
    $fixture=User::load((int)$row['uid']);
    if ($fixture && AccountErasure::selfServiceAllowed($fixture)) $fixture->delete();
  }
}
$existing_users = $db->select('users_field_data', 'u')->fields('u', ['uid', 'name', 'mail', 'status'])->orderBy('uid')->execute()->fetchAll(PDO::FETCH_ASSOC);
$tables = ['nelkano_profile','nelkano_friendship','nelkano_device','nelkano_library_item','nelkano_play_session','nelkano_stream_session','nelkano_stream_signal_event','nelkano_game_session','nelkano_game_signal_event','nelkano_app_session'];
$protected_digest = static function() use ($db, &$uids, $tables, $seed): array {
  $result = [];
  foreach ($tables as $table) {
    if (!$db->schema()->tableExists($table)) continue;
    $rows = $db->select($table, 't')->fields('t')->execute()->fetchAll(PDO::FETCH_ASSOC); $kept = [];
    foreach ($rows as $row) {
      $own = FALSE;
      foreach (['uid','host_uid','guest_uid','requester_uid','recipient_uid','sender_uid'] as $key) if (isset($row[$key]) && in_array((int) $row[$key], $uids, TRUE)) $own = TRUE;
      if (isset($row['session_id']) && str_starts_with((string) $row['session_id'], $seed)) $own = TRUE;
      if (!$own) $kept[] = serialize($row);
    }
    sort($kept); $result[$table] = hash('sha256', serialize($kept));
  }
  return $result;
};
// Every ledger write in this process, including user-delete hooks, uses fresh
// fixture-only paths. The persistent live register is never read or modified.
$previousSettings = \Drupal\Core\Site\Settings::getAll();
$fixtureUri = 'private://nelkano-account-erasures-qa-' . $seed;
$fixtureRegistry = '/opt/nelkano-erasure-registry/qa-' . $seed;
new \Drupal\Core\Site\Settings(array_replace($previousSettings, [
  'nelkano_erasure_ledger_uri'=>$fixtureUri,
  'nelkano_erasure_registry_directory'=>$fixtureRegistry,
]));
$fs = \Drupal::service('file_system'); $controller = new AccountErasureController();
$password = 'Fixture-only-' . bin2hex(random_bytes(12));
try {
  foreach (['erase','keep'] as $name) {
    $account = User::create(['name'=>$seed.'_'.$name,'mail'=>$seed.'_'.$name.'@example.invalid','pass'=>$password,'status'=>1]); $account->save();
    $uids[] = (int) $account->id(); $uuids[] = $account->uuid();
    \Drupal::service('user.data')->set('nelkano_home',(int)$account->id(),'qa_private_data',$seed);
  }
  [$uid,$peer] = $uids; $doomed = User::load($uid); $survivor = User::load($peer);
  $check(\Drupal::service('user.data')->get('nelkano_home', $uid, 'adult_account_attestation') === NULL, 'Fixture has no age declaration.');
  $switcher=\Drupal::service('account_switcher');
  $switcher->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
  try {
    $erase_form=new \Drupal\nelkano_home\Form\AccountErasureForm();
    $form_state=new \Drupal\Core\Form\FormState();
    $form_build=$erase_form->buildForm([],$form_state);
    $check(isset($form_build['email'],$form_build['password'],$form_build['confirmation']), 'Anonymous erasure must expose credential fields.');
    $check(($form_build['#token']??'')===$erase_form->getFormId(), 'Anonymous erasure must require its session CSRF token.');
    $form_state->setValues(['email'=>$doomed->getEmail(),'password'=>$password,'confirmation'=>1]);
    $erase_form->validateForm($form_build,$form_state);
    $check(!$form_state->hasAnyErrors(),'Anonymous unknown-age erasure credentials should validate without an adult declaration.');
    $check(\Drupal::service('user.data')->get('nelkano_home', $uid, 'adult_account_attestation') === NULL,'Erasure must not record an age declaration.');
  }
  finally { $switcher->switchBack(); }

  $check(!AccountErasure::selfServiceAllowed(User::load(1)), 'Principal account must be protected.');
  $token = AppSessions::issue($doomed, ['id'=>$seed,'name'=>'QA fixture','platform'=>'qa'])['access_token'];
  AppSessions::issue($survivor, ['id'=>$seed.'_keep','platform'=>'qa']);
  \Drupal::service('user.data')->set('nelkano_home',$uid,'qa_private_data','erase-me');
  $avatar = 'public://nelkano-avatars/app_'.$uid.'.png'; $keep_avatar = 'public://nelkano-avatars/app_'.$peer.'.png';
  $avatar_directory = 'public://nelkano-avatars'; $fs->prepareDirectory($avatar_directory, 3); file_put_contents($avatar,'fixture-avatar'); file_put_contents($keep_avatar,'keep-avatar');
  foreach ([$uid=>$avatar,$peer=>$keep_avatar] as $id=>$uri) $db->insert('nelkano_profile')->fields(['uid'=>$id,'display_name'=>$seed,'avatar_file_uri'=>$uri])->execute();
  $db->insert('nelkano_friendship')->fields(['requester_uid'=>$uid,'recipient_uid'=>$peer])->execute();
  $db->insert('nelkano_device')->fields(['uid'=>$uid,'device_id'=>$seed])->execute();
  $library = $db->insert('nelkano_library_item')->fields(['uid'=>$uid,'client_item_id'=>$seed,'system'=>'gba'])->execute();
  $db->insert('nelkano_play_session')->fields(['uid'=>$uid,'library_item_id'=>$library,'device_id'=>$seed,'session_id'=>$seed.'_play'])->execute();
  $db->insert('nelkano_stream_session')->fields(['uid'=>$uid,'device_id'=>$seed,'session_id'=>$seed.'_stream'])->execute();
  $db->insert('nelkano_stream_signal_event')->fields(['uid'=>$uid,'session_id'=>$seed.'_stream','target'=>'receiver','type'=>'ice','payload'=>'fixture'])->execute();
  $db->insert('nelkano_game_session')->fields(['session_id'=>$seed.'_game','host_uid'=>$uid,'guest_uid'=>$peer])->execute();
  $db->insert('nelkano_game_signal_event')->fields(['session_id'=>$seed.'_game','sender_uid'=>$peer,'target'=>'host','type'=>'ice','payload'=>'fixture'])->execute();
  foreach ([$uid,$peer] as $author) {
    $uuid = \Drupal::service('uuid')->generate(); $directory = 'private://nelkano-error-reports/'.$uuid; $fs->prepareDirectory($directory,3); file_put_contents($directory.'/state.bin','fixture-state');
    $node = Node::create(['type'=>'nelkano_error_report','uuid'=>$uuid,'uid'=>$author,'title'=>$seed,'status'=>0,'field_report_state_uri'=>$directory.'/state.bin']); $node->save(); $nids[$author]=(int)$node->id();
    if ($author===$uid) { $node->setNewRevision(TRUE); $node->set('field_report_state_uri',$directory.'/state2.bin'); file_put_contents($directory.'/state2.bin','old-fixture-state'); $node->save(); $doomed_directory=$directory; }
    else $keep_directory=$directory;
  }
  $protected = $protected_digest(); $peer_profile = $db->select('nelkano_profile','p')->fields('p')->condition('uid',$peer)->execute()->fetchAssoc();
  $request = static function(array $body, ?string $bearer) use ($controller): \Symfony\Component\HttpFoundation\JsonResponse {
    return $controller->api(Request::create('/api/nelkano/v2/account/delete','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>$bearer ? 'Bearer '.$bearer : '', 'REMOTE_ADDR'=>'127.0.0.249'],json_encode($body,JSON_THROW_ON_ERROR)));
  };
  $check($request(['password'=>$password,'confirmation'=>'DELETE'],NULL)->getStatusCode()===401,'Anonymous API must not erase.');
  $check($request(['password'=>$password,'confirmation'=>'DELETE','uid'=>$peer],$token)->getStatusCode()===400,'A client cannot select another UID.');
  $check($request(['password'=>'incorrect-fixture-password','confirmation'=>'DELETE'],$token)->getStatusCode()===403,'Wrong password must not erase.');
  $check((bool)User::load($uid),'Failure cases preserved the account.');
  $response=$request(['password'=>$password,'confirmation'=>'DELETE'],$token);
  $check($response->getStatusCode()===200,'Authenticated unknown-age account erasure failed: '.$response->getContent());
  \Drupal::entityTypeManager()->getStorage('user')->resetCache($uids);
  $check(!User::load($uid),'Account row remains.');
  $check((bool)User::load($peer),'Unrelated fixture account was removed.');
  $check(AppSessions::accountFromAccessToken($token)===NULL,'Deleted access token still works.');
  foreach (['nelkano_profile','nelkano_device','nelkano_library_item','nelkano_play_session','nelkano_stream_session','nelkano_stream_signal_event','nelkano_app_session'] as $table) $check(!(int)$db->select($table,'t')->condition('uid',$uid)->countQuery()->execute()->fetchField(),'Account data remains in '.$table);
  $check(!(int)$db->select('nelkano_friendship','f')->condition($db->condition('OR')->condition('requester_uid',$uid)->condition('recipient_uid',$uid))->countQuery()->execute()->fetchField(),'Friendship remains.');
  $check(!(int)$db->select('nelkano_game_session','s')->condition('session_id',$seed.'_game')->countQuery()->execute()->fetchField(),'Shared game session remains.');
  $check(!(int)$db->select('nelkano_game_signal_event','s')->condition('session_id',$seed.'_game')->countQuery()->execute()->fetchField(),'Other participant signaling remains.');
  $check(!file_exists($avatar) && !is_dir($doomed_directory),'Owned avatar or report attachment remains.');
  $check(file_exists($keep_avatar) && file_exists($keep_directory.'/state.bin'),'Unrelated avatar/report attachment removed.');
  $check(!\Drupal::entityTypeManager()->getStorage('node')->load($nids[$uid]),'Owned report/revisions remain.');
  $check((bool)\Drupal::entityTypeManager()->getStorage('node')->load($nids[$peer]),'Unrelated report removed.');
  $check(\Drupal::service('user.data')->get('nelkano_home',$uid)===[],'User metadata remains.');
  $check($db->select('nelkano_profile','p')->fields('p')->condition('uid',$peer)->execute()->fetchAssoc()===$peer_profile,'Unrelated profile changed.');
  $check($protected_digest()===$protected,'Pre-existing business data changed.');
  $check((bool)array_filter(AccountErasureLedger::entries(),static fn(array $row):bool=>$row['account_uuid']===$uuids[0]),'Suppression UUID not recorded.');
  $check(str_contains(file_get_contents(AccountErasureLedger::mirrorPath()),$uuids[0]),'Independent register did not retain the deletion.');
  // Simulate restoring an old site copy which predates this deletion. Only the
  // temporary primary ledger is replaced; the temporary independent copy stays.
  $primary = AccountErasureLedger::path();
  $rows = array_filter(file($primary, FILE_IGNORE_NEW_LINES), static fn(string $line):bool=>json_decode($line,TRUE,8,JSON_THROW_ON_ERROR)['account_uuid']!==$uuids[0]);
  file_put_contents($primary, $rows ? implode("\n",$rows)."\n" : '', LOCK_EX);
  $check((bool)array_filter(AccountErasureLedger::entries(),static fn(array $row):bool=>$row['account_uuid']===$uuids[0]),'Independent register lost deletion after an old site-copy restore.');
  $restored=User::create(['uuid'=>$uuids[0],'name'=>$seed.'_restored','mail'=>$seed.'_restored@example.invalid','pass'=>$password,'status'=>1]);$restored->save();$uids[]=(int)$restored->id();
  $gate = static function(): array {
    $previous=getenv('NELKANO_ERASURE_ACTION');putenv('NELKANO_ERASURE_ACTION=reapply');
    $run=static function():void{$extra=['--require-clean'];require __DIR__.'/account-erasure-ledger.php';};
    ob_start();try{$run();$output=ob_get_contents();return json_decode($output,TRUE,8,JSON_THROW_ON_ERROR);}
    finally{ob_end_clean();$previous===FALSE?putenv('NELKANO_ERASURE_ACTION'):putenv('NELKANO_ERASURE_ACTION='.$previous);}
  };
  $blocked=FALSE;try{$gate();}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'Restored accounts match suppression requests.');}
  $check($blocked && (bool)User::load((int)$restored->id()),'Restore gate must block reopening and must not erase accounts in check-only mode.');
  AccountErasure::erase($restored); // UUID/name above belong to this fresh fixture only.
  $check($gate()['restoredAccountsMatched']===0,'Restore gate should pass after reapplying the fixture deletion.');
  $check((bool)User::load($peer),'Restoration rehearsal removed the other fixture user.');

  $check(nelkano_home_file_download('private://nelkano-account-erasures/ledger.jsonl')===-1,'Ledger can be downloaded.');
  $check(nelkano_home_file_download('private://nelkano-stream/1/example.frame')===-1,'Private streaming cache can be downloaded.');
  echo json_encode(['passed'=>TRUE,'assertions'=>$count,'scope'=>'Fresh local fixtures only; unknown-age erasure, attachments/revisions, session revocation and preservation of other accounts; no physical concurrency or provider backup assertion'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
finally {
  $cleanup_errors=[];
  foreach ($uids as $id) { try { $account=User::load($id); if ($account && str_starts_with($account->getAccountName(),$seed)) $account->delete(); } catch (\Throwable $error) { $cleanup_errors[]=$error->getMessage(); } }
  if ($uuids) {
    foreach (AccountErasureLedger::paths() as $path) {
    $handle=fopen($path,'c+b');
    if ($handle && flock($handle,LOCK_EX)) {
      $rows=[];while(($line=fgets($handle))!==FALSE) { $row=json_decode($line,TRUE,8,JSON_THROW_ON_ERROR);if(!in_array($row['account_uuid']??'', $uuids,TRUE))$rows[]=$row; }
      ftruncate($handle,0);rewind($handle);foreach($rows as $row)fwrite($handle,json_encode($row,JSON_THROW_ON_ERROR)."\n");fflush($handle);flock($handle,LOCK_UN);fclose($handle);
    }
  }
  }
  \Drupal::flood()->clear('nelkano_home.app_login_ip','127.0.0.249');
  foreach($uids as $id)\Drupal::flood()->clear('nelkano_home.app_login_user',$id.'-127.0.0.249');
  $after=$db->select('users_field_data','u')->fields('u',['uid','name','mail','status'])->orderBy('uid')->execute()->fetchAll(PDO::FETCH_ASSOC);
  foreach(AccountErasureLedger::paths() as $fixturePath){
    if(!str_contains(dirname($fixturePath),$seed))throw new RuntimeException('Refusing fixture cleanup outside its temporary directory.');
    if(is_file($fixturePath))unlink($fixturePath);
    if(is_dir(dirname($fixturePath)))rmdir(dirname($fixturePath));
  }
  new \Drupal\Core\Site\Settings($previousSettings);
  if($cleanup_errors || $after!==$existing_users)throw new RuntimeException('Fixture cleanup incomplete: '.implode('; ',$cleanup_errors));
}
