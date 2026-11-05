<?php
declare(strict_types=1);
namespace Drupal\nelkano_ai\Controller;
use Drupal\nelkano_ai\Service\AiAccess;
use Drupal\nelkano_ai\Service\AiQueue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AiController {
  private function body(Request $r): array {
    if(!str_starts_with(strtolower((string)$r->headers->get('Content-Type','')),'application/json'))throw new HttpException(415,'json-required');
    $raw=$r->getContent();if(strlen($raw)>49152)throw new HttpException(413,'request-too-large');
    try{$v=json_decode($raw,FALSE,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpException(400,'invalid-json');}
    if(!$v instanceof \stdClass)throw new HttpException(400,'invalid-json');
    return json_decode($raw,TRUE,32,JSON_THROW_ON_ERROR);
  }
  private function handle(Request $r,bool $worker,callable $work): JsonResponse {
    try{
      $identity=$worker?AiAccess::worker($r):AiAccess::tester($r);
      $flood=\Drupal::service('flood');$name='nelkano_ai.'.($worker?'worker':'tester');$key=(string)$identity;
      if(!$flood->isAllowed($name,$worker?120:90,60,$key))throw new HttpException(429,'rate-limit');
      $flood->register($name,60,$key);
      $data=AiQueue::locked(fn()=>$work($identity));
      return $this->response(['ok'=>TRUE]+$data,200);
    }catch(HttpExceptionInterface $e){return $this->response(['ok'=>FALSE,'code'=>$e->getMessage()],$e->getStatusCode());}
    catch(\Throwable $e){\Drupal::logger('nelkano_ai')->error('Operación de Nelkan IA fallida: @type',['@type'=>get_class($e)]);return $this->response(['ok'=>FALSE,'code'=>'server-error'],500);}
  }
  private function response(array $data,int $status): JsonResponse {
    return new JsonResponse(['api_version'=>1]+$data,$status,['Cache-Control'=>'private, no-store','Vary'=>'Authorization','X-Content-Type-Options'=>'nosniff']);
  }
  public function status(Request $r): JsonResponse {return $this->handle($r,FALSE,fn($uid)=>AiQueue::status());}
  public function submit(Request $r): JsonResponse {return $this->handle($r,FALSE,fn($uid)=>['job'=>AiQueue::submit($uid,$this->body($r))]);}
  public function read(Request $r,string $id): JsonResponse {return $this->handle($r,FALSE,fn($uid)=>['job'=>AiQueue::view(AiQueue::owned($uid,$id))]);}
  public function cancel(Request $r,string $id): JsonResponse {return $this->handle($r,FALSE,function($uid)use($r,$id){if($this->body($r)!==[])throw new HttpException(400,'invalid-request');return ['job'=>AiQueue::cancel($uid,$id)];});}
  public function heartbeat(Request $r): JsonResponse {return $this->handle($r,TRUE,fn($w)=>AiQueue::heartbeat($w,$this->body($r)));}
  public function claim(Request $r): JsonResponse {return $this->handle($r,TRUE,fn($w)=>AiQueue::claim($w,$this->body($r)));}
  public function workerJob(Request $r,string $id): JsonResponse {return $this->handle($r,TRUE,fn($w)=>AiQueue::workerJob($w,$id));}
  public function result(Request $r,string $id): JsonResponse {return $this->handle($r,TRUE,fn($w)=>AiQueue::result($w,$id,$this->body($r)));}
}
