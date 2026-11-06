<?php
declare(strict_types=1);
namespace Drupal\nelkano_ai\Service;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AiQueue {
  public const TABLE='nelkano_ai_job';
  public static function locked(callable $work): mixed {
    $lock=\Drupal::lock();
    if(!$lock->acquire('nelkano_ai.queue',10.0))throw new HttpException(409,'queue-busy');
    try {self::cleanup();return $work();} finally {$lock->release('nelkano_ai.queue');}
  }
  public static function cleanup(): void {
    $db=\Drupal::database();$now=time();
    $db->delete(self::TABLE)->condition('expires',$now,'<=')->execute();
    $db->update(self::TABLE)->fields(['status'=>'failed','error'=>'timeout','question'=>'','history'=>'[]','actions_data'=>NULL])
      ->condition('status',['queued','running'],'IN')->condition('deadline',$now,'<=')->execute();
  }
  public static function online(): bool {
    $h=\Drupal::state()->get('nelkano_ai.heartbeat',[]);
    return !empty($h['ready']) && (int)($h['time']??0)>time()-30;
  }
  public static function status(): array {
    $h=\Drupal::state()->get('nelkano_ai.heartbeat',[]);
    return ['protocols'=>$h['protocols']??[],'online'=>self::online(),'model'=>$h['model']??'','model_revision'=>$h['model_revision']??''];
  }
  public static function validate(array $body): array {
    if(array_diff(array_keys($body),['request_id','question','history','protocol','context']))throw new HttpException(400,'invalid-request');
    $key=$body['request_id']??NULL;$q=$body['question']??NULL;$history=$body['history']??[];
    if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$key)||!is_string($q)||trim($q)===''||mb_strlen($q)>4000)throw new HttpException(400,'invalid-question');
    if(!is_array($history)||!array_is_list($history)||count($history)>8)throw new HttpException(400,'invalid-history');
    $length=0;
    foreach($history as $i=>$m){
      if(!is_array($m)||array_diff(array_keys($m),['role','content'])||($m['role']??'')!==($i%2===0?'user':'assistant')||!is_string($m['content']??NULL)||mb_strlen($m['content'])>4000)throw new HttpException(400,'invalid-history');
      $length+=mb_strlen($m['content']);
    }
    if(count($history)%2!==0||$length>8000)throw new HttpException(400,'invalid-history');
    return ['request_id'=>$key,'question'=>trim($q),'history'=>$history,'actions'=>AiActions::initial($body)];
  }
  public static function submit(int $uid,array $body): array {
    $v=self::validate($body);$db=\Drupal::database();
    $hashInput=[$v['question'],$v['history']];if($v['actions'])$hashInput[]=$v['actions'];
    $hash=hash('sha256',json_encode($hashInput,JSON_THROW_ON_ERROR));
    $old=$db->select(self::TABLE,'j')->fields('j')->condition('uid',$uid)->condition('request_key',$v['request_id'])->execute()->fetchAssoc();
    if($old){if(!hash_equals($old['request_hash'],$hash))throw new HttpException(409,'request-id-reused');return self::view($old);}
    if(!self::online())throw new HttpException(503,'pc-offline');
    if($v['actions']&&!in_array('actions-v1',self::status()['protocols'],TRUE))throw new HttpException(409,'actions-unavailable');
    $pending=$db->select(self::TABLE,'j')->fields('j',['uid'])->condition('status',['queued','running','awaiting_tools'],'IN')->execute()->fetchCol();
    if(count($pending)>=8||in_array((string)$uid,array_map('strval',$pending),TRUE))throw new HttpException(429,'queue-full');
    $id=\Drupal::service('uuid')->generate();$now=time();
    $row=['actions_data'=>$v['actions']?json_encode($v['actions'],JSON_THROW_ON_ERROR):NULL,'id'=>$id,'uid'=>$uid,'request_key'=>$v['request_id'],'request_hash'=>$hash,'status'=>'queued','question'=>$v['question'],'history'=>json_encode($v['history'],JSON_THROW_ON_ERROR),'answer'=>'','error'=>'','created'=>$now,'expires'=>$now+600,'deadline'=>$now+($v['actions']?600:180),'worker'=>'','lease_hash'=>'','model_revision'=>'','model_label'=>''];
    $db->insert(self::TABLE)->fields($row)->execute();return self::view($row);
  }
  public static function load(string $id): array {
    $row=\Drupal::database()->select(self::TABLE,'j')->fields('j')->condition('id',$id)->execute()->fetchAssoc();
    if(!$row)throw new HttpException(404,'question-not-found');return $row;
  }
  public static function owned(int $uid,string $id): array {
    $row=self::load($id);if((int)$row['uid']!==$uid)throw new HttpException(404,'question-not-found');return $row;
  }
  public static function view(array $row): array {
    return ['id'=>$row['id'],'state'=>$row['status'],'model'=>$row['model_label'],'model_revision'=>$row['model_revision'],
      'answer'=>$row['status']==='complete'?$row['answer']:'','error'=>$row['error'],'expires_at'=>(int)$row['expires']]+AiActions::view($row);
  }
  public static function cancel(int $uid,string $id): array {
    $r=self::owned($uid,$id);
    if(in_array($r['status'],['queued','running','awaiting_tools'],TRUE)){
      \Drupal::database()->update(self::TABLE)->fields(['status'=>'cancelled','question'=>'','history'=>'[]','actions_data'=>NULL])->condition('id',$id)->execute();$r=self::load($id);
    }
    return self::view($r);
  }
  public static function heartbeat(string $worker,array $body): array {
    if(array_diff(array_keys($body),['ready','model','model_revision','protocols'])||!is_bool($body['ready']??NULL))throw new HttpException(400,'invalid-heartbeat');
    if(isset($body['protocols'])&&$body['protocols']!==[]&&$body['protocols']!==['actions-v1'])throw new HttpException(400,'invalid-protocols');
    foreach(['model','model_revision']as$key)if(!is_string($body[$key]??NULL)||$body[$key]===''||mb_strlen($body[$key])>128)throw new HttpException(400,'invalid-model');
    \Drupal::state()->set('nelkano_ai.heartbeat',['worker'=>$worker,'time'=>time()]+$body);
    return ['accepted'=>TRUE];
  }
  public static function claim(string $worker,array $body): array {
    if($body!==[])throw new HttpException(400,'invalid-request');
    if(!self::online())return ['job'=>NULL];
    $db=\Drupal::database();
    if($db->select(self::TABLE,'j')->condition('status','running')->countQuery()->execute()->fetchField())return ['job'=>NULL];
    $ids=$db->select(self::TABLE,'j')->fields('j',['id'])->condition('status','queued')->orderBy('created')->orderBy('id')->range(0,8)->execute()->fetchCol();
    foreach($ids as$id){
      $r=self::load($id);
      if(!AiAccess::eligible((int)$r['uid'])){$db->delete(self::TABLE)->condition('id',$id)->execute();continue;}
      $lease=bin2hex(random_bytes(32));$h=\Drupal::state()->get('nelkano_ai.heartbeat',[]);
      $pinned=!empty($r['actions_data'])&&$r['model_revision']!=='';$revision=$pinned?$r['model_revision']:$h['model_revision'];$label=$pinned?$r['model_label']:$h['model'];
      $db->update(self::TABLE)->fields(['status'=>'running','deadline'=>min(time()+180,(int)$r['expires']),'worker'=>$worker,'lease_hash'=>hash('sha256',$lease),'model_revision'=>$revision,'model_label'=>$label])->condition('id',$id)->execute();
      return ['job'=>['id'=>$id,'lease'=>$lease,'question'=>$r['question'],'history'=>json_decode($r['history'],TRUE,512,JSON_THROW_ON_ERROR),'model_revision'=>$revision,'expires_at'=>(int)$r['expires'],'deadline'=>min(time()+180,(int)$r['expires'])]+AiActions::claimed($r)];
    }
    return ['job'=>NULL];
  }
  public static function workerJob(string $worker,string $id): array {
    $r=self::load($id);if($r['worker']!==$worker)throw new HttpException(404,'question-not-found');
    if(!AiAccess::eligible((int)$r['uid'])){self::cancel((int)$r['uid'],$id);$r=self::load($id);}
    return ['id'=>$id,'state'=>$r['status']];
  }
  public static function result(string $worker,string $id,array $body): array {
    if(array_diff(array_keys($body),['lease','answer','error','model_revision','tool_calls','round']))throw new HttpException(400,'invalid-result');
    $r=self::load($id);$lease=$body['lease']??'';$answer=$body['answer']??'';$error=$body['error']??'';
    if($r['worker']!==$worker||!is_string($lease)||!preg_match('/^[a-f0-9]{64}$/D',$lease)||!hash_equals($r['lease_hash'],hash('sha256',$lease)))throw new HttpException(403,'invalid-lease');
    if(!is_string($answer)||mb_strlen($answer)>12000||!is_string($error)||!in_array($error,['','model-unavailable','compute-busy','actions-unavailable','timeout'],TRUE)||($body['model_revision']??'')!==$r['model_revision'])throw new HttpException(400,'invalid-result');
    if($r['status']==='cancelled'||$r['status']==='failed')return ['accepted'=>FALSE,'state'=>$r['status']];
    if(!AiAccess::eligible((int)$r['uid'])){self::cancel((int)$r['uid'],$id);return ['accepted'=>FALSE,'state'=>'cancelled'];}
    $calls=AiActions::calls($r,$body);if($calls!==NULL)return $calls;
    if($error===''&&trim($answer)==='')throw new HttpException(400,'empty-answer');
    if($r['status']==='complete'){
      if($r['answer']!==$answer||$error!=='')throw new HttpException(409,'result-changed');
      return ['accepted'=>TRUE,'state'=>'complete'];
    }
    \Drupal::database()->update(self::TABLE)->fields(['status'=>$error===''?'complete':'failed','answer'=>$error===''?$answer:'','error'=>$error,'question'=>'','history'=>'[]','actions_data'=>NULL])->condition('id',$id)->execute();
    return ['accepted'=>TRUE,'state'=>$error===''?'complete':'failed'];
  }
}
