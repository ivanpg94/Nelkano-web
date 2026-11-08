<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\nelkano_home\Service\AppSessions;
use Drupal\nelkano_home\Service\CompatibilityCommunity as Community;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CompatibilityCommunityController {
  private function body(Request $request): array {
    if (!str_starts_with(strtolower($request->headers->get('Content-Type','')),'application/json')) throw new HttpException(415,'json-required');
    if (strlen($request->getContent())>16384) throw new HttpException(413,'request-too-large');
    try { $body=json_decode($request->getContent(),FALSE,16,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new HttpException(400,'invalid-json'); }
    if (!$body instanceof \stdClass) throw new HttpException(400,'invalid-json');
    return (array)$body;
  }
  private function response(callable $work): JsonResponse {
    try { $data=['ok'=>TRUE]+$work();$status=200; }
    catch(HttpExceptionInterface $e){$data=['ok'=>FALSE,'code'=>$e->getMessage()];$status=$e->getStatusCode();}
    catch(\InvalidArgumentException $e){$data=['ok'=>FALSE,'code'=>'invalid-request'];$status=400;}
    catch(\Throwable $e){\Drupal::logger('nelkano_home')->error('Compatibility operation failed: @type',['@type'=>get_class($e)]);$data=['ok'=>FALSE,'code'=>'server-error'];$status=500;}
    return new JsonResponse(['api_version'=>1]+$data,$status,['Cache-Control'=>'private, no-store','Vary'=>'Authorization','X-Content-Type-Options'=>'nosniff']);
  }
  private function uid(Request $request): int {
    $header=trim($request->headers->get('Authorization',''));
    $user=preg_match('/^Bearer (nkat_[a-f0-9]{64})$/D',$header,$matches)?AppSessions::accountFromAccessToken($matches[1]):NULL;
    if (!$user || !$user->isActive()) throw new HttpException(401,'login-required');
    $uid=(int)$user->id();$flood=\Drupal::service('flood');
    if(!$flood->isAllowed('nelkano_compatibility',30,60,(string)$uid))throw new HttpException(429,'rate-limit');
    $flood->register('nelkano_compatibility',60,(string)$uid);
    return $uid;
  }
  public function resolve(Request $request): JsonResponse {return $this->response(fn()=>Community::resolve($this->uid($request),$this->body($request)));}
  public function rate(Request $request): JsonResponse {return $this->response(fn()=>Community::rate($this->uid($request),$this->body($request)));}
  public function moderate(Request $request,string $system): JsonResponse {
    return $this->response(function()use($request,$system){Community::moderate($system,$this->body($request));return ['saved'=>TRUE];});
  }
}
