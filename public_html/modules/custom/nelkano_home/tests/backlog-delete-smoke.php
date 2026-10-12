<?php
/** Local destructive-operation QA: deletes only its own synthetic entities. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_BACKLOG_DELETE_TEST') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') {
  throw new RuntimeException('Explicit local Docker opt-in required.');
}
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;

$checks=0; $ids=[]; $role=NULL; $user=NULL; $switched=FALSE;
$check=static function ($ok,$label) use (&$checks) { if (!$ok) {throw new RuntimeException('FAIL: '.$label);} $checks++; echo 'PASS: '.$label.PHP_EOL; };
$http=\Drupal::httpClient(); $jar=new CookieJar();
$call=static fn($method,$path,$options=[]) => $http->request($method,'http://127.0.0.1'.$path,$options+['cookies'=>$jar,'http_errors'=>FALSE,'allow_redirects'=>FALSE,'timeout'=>20]);
$read=static function ($id) { \Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]); return Node::load($id); };
$before=[];
foreach(\Drupal::database()->select('node','n')->fields('n')->execute()->fetchAll(PDO::FETCH_ASSOC) as $row) {$before[$row['nid']]=$row;}
try {
  $prefix='hudelete'.bin2hex(random_bytes(4));
  $role=Role::create(['id'=>$prefix,'label'=>$prefix,'permissions'=>[HuWorkflow::PERMISSION,'access content']]);$role->save();
  $user=User::create(['name'=>$prefix,'mail'=>$prefix.'@example.invalid','status'=>1,'roles'=>[$prefix]]);$user->save();
  $switcher=\Drupal::service('account_switcher');$switcher->switchTo($user);$switched=TRUE;
  $node=HuWorkflow::save(NULL,NULL,'new',$prefix,'Solo esta HU se elimina.',['sprint'=>0]);$ids[]=(int)$node->id();
  $revision=(int)$node->getRevisionId();
  $node=HuWorkflow::save((int)$node->id(),$revision,'blocked');
  $nid=(int)$node->id();$number=(int)$node->get('field_hu_number')->value;
  $other=Node::create(['type'=>'nelkano_module','title'=>$prefix.' other bundle','status'=>0,'uid'=>$user->id()]);$other->save();$ids[]=(int)$other->id();
  $switcher->switchBack();$switched=FALSE;
  Index::load('nelkano_backlog')->indexItems();
  $path='/admin/nelkano/backlog/'.$nid.'/delete';
  $check($call('POST',$path,['json'=>['revision'=>(int)$node->getRevisionId()]])->getStatusCode()===403,'anonymous deletion forbidden');
  $time=time();$check($call('GET','/user/reset/'.$user->id().'/'.$time.'/'.user_pass_rehash($user,$time).'/login')->getStatusCode()===302,'restricted backlog account authenticated');
  $check($call('GET',$path)->getStatusCode()===405,'GET cannot delete');
  $check($call('POST',$path,['json'=>['revision'=>(int)$node->getRevisionId()]])->getStatusCode()===403,'missing CSRF cannot delete');
  $check($read($nid)!==NULL,'denied requests preserve HU');
  $token=(string)$call('GET','/session/token')->getBody();$headers=['X-CSRF-Token'=>$token,'Accept'=>'application/json'];
  $check($call('POST',$path,['headers'=>['X-CSRF-Token'=>'invalid'],'json'=>['revision'=>(int)$node->getRevisionId()]])->getStatusCode()===403,'invalid CSRF cannot delete');
  foreach([[],['revision'=>'1'],['revision'=>0],['revision'=>-1],['revision'=>(int)$node->getRevisionId(),'status'=>'new']] as $body) {
    $check($call('POST',$path,['headers'=>$headers,'json'=>$body])->getStatusCode()===400,'invalid deletion body rejected');
  }
  $check($call('POST',$path,['headers'=>$headers,'body'=>'{'])->getStatusCode()===400,'malformed JSON rejected');
  $check($call('POST',$path,['headers'=>$headers,'body'=>str_repeat('x',2049)])->getStatusCode()===400,'oversized deletion rejected');
  $check($call('POST',$path,['headers'=>$headers,'json'=>['revision'=>$revision]])->getStatusCode()===409 && $read($nid)!==NULL,'stale revision cannot delete edited HU');
  $check($call('POST','/admin/nelkano/backlog/'.$other->id().'/delete',['headers'=>$headers,'json'=>['revision'=>(int)$other->getRevisionId()]])->getStatusCode()===404 && $read($other->id())!==NULL,'another node bundle cannot be deleted');
  $switcher->switchTo($user);$switched=TRUE;
  $check(\Drupal::lock()->acquire('nelkano_hu:'.$nid,60),'fixture reserves shared HU edit lock');
  try {
    $response=$call('POST',$path,['headers'=>$headers,'json'=>['revision'=>(int)$node->getRevisionId()]]);
    $check($response->getStatusCode()===409 && $read($nid)!==NULL,'another HTTP process respects concurrent write lock');
  }
  finally {\Drupal::lock()->release('nelkano_hu:'.$nid);}
  $switcher->switchBack();$switched=FALSE;
  $role->revokePermission(HuWorkflow::PERMISSION)->save();
  $check($call('POST',$path,['headers'=>$headers,'json'=>['revision'=>(int)$node->getRevisionId()]])->getStatusCode()===403,'revoked backlog permission cannot delete');
  $role->grantPermission(HuWorkflow::PERMISSION)->save();
  $response=$call('POST',$path,['headers'=>$headers,'json'=>['revision'=>(int)$node->getRevisionId()]]);
  $payload=json_decode((string)$response->getBody(),TRUE,512,JSON_THROW_ON_ERROR);
  $check($response->getStatusCode()===200 && $payload['id']===$nid && $payload['deleted'] && $payload['number']===sprintf('%05d',$number),'valid confirmed revision is deleted');
  $check($response->getHeaderLine('Cache-Control')==='no-store, private' || str_contains($response->getHeaderLine('Cache-Control'),'no-store'),'delete response never cached');
  $check($read($nid)===NULL,'HU physically removed');
  $remaining=0;
  foreach(\Drupal::database()->schema()->findTables('node%') as $table) {
    $key=str_starts_with($table,'node__') || str_starts_with($table,'node_revision__') ? 'entity_id' : 'nid';
    if(\Drupal::database()->schema()->fieldExists($table,$key)) {$remaining+=(int)\Drupal::database()->select($table,'t')->condition($key,$nid)->countQuery()->execute()->fetchField();}
  }
  $check($remaining===0,'all field rows and revisions removed');
  $html=(string)$call('GET','/admin/nelkano/backlog',['query'=>['q'=>$prefix,'sprint'=>'0']])->getBody();
  $check(!str_contains($html,'data-id="'.$nid.'"'),'removed HU no longer returned by Search API backlog');
  $check($call('POST',$path,['headers'=>$headers,'json'=>['revision'=>(int)$node->getRevisionId()]])->getStatusCode()===404,'repeated deletion reports missing HU safely');
  $switcher->switchTo($user);$switched=TRUE;
  $replacement=HuWorkflow::save(NULL,NULL,'new',$prefix.' replacement','Numeración monotónica.');$ids[]=(int)$replacement->id();
  $check((int)$replacement->get('field_hu_number')->value>$number,'deleted HU number never reused');
  $switcher->switchBack();$switched=FALSE;
  $preserved=TRUE;
  foreach(\Drupal::database()->select('node','n')->fields('n')->execute()->fetchAll(PDO::FETCH_ASSOC) as $row) {if(isset($before[$row['nid']])) {$preserved=$preserved && $before[$row['nid']]===$row;unset($before[$row['nid']]);}}
  $check($preserved && !$before,'every pre-existing node remains unchanged');
  echo "SUCCESS: $checks deletion checks.\n";
}
finally {
  if($switched) {\Drupal::service('account_switcher')->switchBack();}
  foreach($ids as $id) {$read($id)?->delete();}
  $user?->delete();$role?->delete();
  echo "Only synthetic HU/nodes/account removed; sequence retained.\n";
}