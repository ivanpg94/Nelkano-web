<?php
declare(strict_types=1);
namespace Drupal\nelkano_ai\Service;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Optional, ephemeral actions-v1 state. The web never executes a device command. */
final class AiActions {
  public static function decode(array $row): ?array {return empty($row['actions_data'])?NULL:json_decode($row['actions_data'],TRUE,32,JSON_THROW_ON_ERROR);}
  public static function initial(array $body): ?array {
    if(!isset($body['protocol'])){if(isset($body['context']))throw new HttpException(400,'invalid-context');return NULL;}
    $c=$body['context']??NULL;
    if($body['protocol']!=='actions-v1'||!is_array($c)||array_is_list($c)||strlen(json_encode($c,JSON_THROW_ON_ERROR))>16000)throw new HttpException(400,'invalid-context');
    if(array_diff(array_keys($c),['surface','game','actions'])||!is_string($c['surface']??NULL)||strlen($c['surface'])>64||!is_array($c['actions']??NULL)||!array_is_list($c['actions'])||count($c['actions'])>64)throw new HttpException(400,'invalid-context');
    $ids=[];foreach($c['actions']as$a){
      if(!is_array($a)||array_diff(array_keys($a),['id','label','domain','args','available','requiredCapabilities','reason'])||!is_string($a['id']??NULL)||!preg_match('/^[a-z][a-zA-Z0-9.-]{0,63}$/D',$a['id'])||isset($ids[$a['id']])||!is_string($a['label']??NULL)||mb_strlen($a['label'])>160||!is_string($a['domain']??NULL)||!is_array($a['args']??NULL))throw new HttpException(400,'invalid-context');
      if(isset($a['available'])&&!is_bool($a['available']))throw new HttpException(400,'invalid-context');
      if(isset($a['reason'])&&(!is_string($a['reason'])||strlen($a['reason'])>64))throw new HttpException(400,'invalid-context');
      if(isset($a['requiredCapabilities'])&&(!is_array($a['requiredCapabilities'])||!array_is_list($a['requiredCapabilities'])||count($a['requiredCapabilities'])>16))throw new HttpException(400,'invalid-context');
      $ids[$a['id']]=TRUE;
    }
    return ['protocol'=>'actions-v1','context'=>$c,'round'=>1,'turns'=>[],'calls'=>[],'worker_hash'=>''];
  }
  public static function view(array $row): array {
    $a=self::decode($row);if(!$a)return [];
    return ['protocol'=>'actions-v1','round'=>$a['round'],'tool_calls'=>$row['status']==='awaiting_tools'?array_map(self::wireCall(...),$a['calls']):[]];
  }
  private static function wireCall(array $c): array {$c['args']=(object)$c['args'];return $c;}
  public static function claimed(array $row): array {
    $a=self::decode($row);if($a){foreach($a['turns']as&$t)$t['calls']=array_map(self::wireCall(...),$t['calls']);unset($t);foreach($a['context']['actions']as&$action)$action['args']=(object)$action['args'];unset($action);}return $a?['protocol'=>'actions-v1','round'=>$a['round'],'context'=>$a['context'],'turns'=>$a['turns']]:[];
  }
  private static function store(string $id,array $fields): void {\Drupal::database()->update(AiQueue::TABLE)->fields($fields)->condition('id',$id)->execute();}
  public static function calls(array $row,array $body): ?array {
    if(!array_key_exists('tool_calls',$body))return NULL;
    $a=self::decode($row);$calls=$body['tool_calls'];
    if(!$a||($body['round']??NULL)!==$a['round']||!is_array($calls)||!array_is_list($calls)||count($calls)<1||count($calls)>4||trim($body['answer']??'')!==''||!empty($body['error']))throw new HttpException(400,'invalid-tool-calls');
    $names=array_column($a['context']['actions'],'id');$ids=[];
    foreach($calls as$c){if(!is_array($c)||array_diff(array_keys($c),['id','name','args'])||!is_string($c['id']??NULL)||!preg_match('/^[a-zA-Z0-9_-]{1,48}$/D',$c['id'])||isset($ids[$c['id']])||!in_array($c['name']??NULL,$names,TRUE)||!is_array($c['args']??NULL)||strlen(json_encode($c['args'],JSON_THROW_ON_ERROR))>4096)throw new HttpException(400,'invalid-tool-calls');$ids[$c['id']]=TRUE;}
    $hash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
    if($row['status']==='awaiting_tools'){if(!hash_equals($a['worker_hash'],$hash))throw new HttpException(409,'result-changed');return ['accepted'=>TRUE,'state'=>'awaiting_tools'];}
    if($row['status']!=='running')throw new HttpException(409,'invalid-tool-state');
    // Generation four must finish with a message; do not leave a device waiting for an impossible continuation.
    if($a['round']>=4)throw new HttpException(400,'tool-round-limit');
    $a['calls']=$calls;$a['worker_hash']=$hash;
    self::store($row['id'],['status'=>'awaiting_tools','actions_data'=>json_encode($a,JSON_THROW_ON_ERROR)]);
    return ['accepted'=>TRUE,'state'=>'awaiting_tools'];
  }
  public static function results(int $uid,string $id,array $body): array {
    $row=AiQueue::owned($uid,$id);$a=self::decode($row);
    if(!$a||array_diff(array_keys($body),['round','results'])||!is_int($body['round']??NULL)||!is_array($body['results']??NULL)||!array_is_list($body['results'])||strlen(json_encode($body,JSON_THROW_ON_ERROR))>16000)throw new HttpException(400,'invalid-tool-results');
    $hash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
    foreach($a['turns']as$t)if($t['round']===$body['round']){if(!hash_equals($t['hash'],$hash))throw new HttpException(409,'tool-results-changed');return AiQueue::view($row);}
    if($row['status']!=='awaiting_tools'||$a['round']!==$body['round'])throw new HttpException(409,'invalid-tool-state');
    if(count($body['results'])!==count($a['calls']))throw new HttpException(400,'invalid-tool-results');
    foreach($body['results']as$i=>$r)if(!is_array($r)||array_diff(array_keys($r),['id','result'])||($r['id']??NULL)!==$a['calls'][$i]['id']||!is_array($r['result']??NULL)||!in_array($r['result']['state']??NULL,['done','opened','failed','cancelled','unknown','blocked'],TRUE))throw new HttpException(400,'invalid-tool-results');
    $a['turns'][]=['round'=>$a['round'],'calls'=>$a['calls'],'results'=>$body['results'],'hash'=>$hash];$a['round']++;$a['calls']=[];$a['worker_hash']='';
    if(strlen(json_encode($a,JSON_THROW_ON_ERROR))>131072)throw new HttpException(413,'tool-history-too-large');
    self::store($id,['status'=>'queued','deadline'=>(int)$row['expires'],'lease_hash'=>'','actions_data'=>json_encode($a,JSON_THROW_ON_ERROR)]);
    return AiQueue::view(AiQueue::load($id));
  }
}
