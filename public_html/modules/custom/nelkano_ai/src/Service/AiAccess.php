<?php
declare(strict_types=1);
namespace Drupal\nelkano_ai\Service;
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AiAccess {
  public static function tester(Request $request): int {
    $header=trim((string)$request->headers->get('Authorization',''));
    $account=preg_match('/^Bearer (nkat_[a-f0-9]{64})$/D',$header,$m)?AppSessions::accountFromAccessToken($m[1]):NULL;
    if (!$account instanceof User) throw new HttpException(401,'login-required');
    if (!self::eligible((int)$account->id())) throw new HttpException(403,'tester-required');
    return (int)$account->id();
  }
  public static function eligible(int $uid): bool {
    // Reload current roles even when several operations run in one process/test.
    \Drupal::entityTypeManager()->getStorage('user')->resetCache([$uid]);
    $u=User::load($uid);
    return $u instanceof User && $u->isActive() && in_array('tester',$u->getRoles(),TRUE);
  }
  public static function issueWorker(string $client='nelkan-pc'): string {
    if (!preg_match('/^[a-z0-9-]{1,64}$/D',$client)) throw new \InvalidArgumentException('worker-name');
    $token='nkaw_'.bin2hex(random_bytes(32));
    $store=\Drupal::keyValue('nelkano_ai_workers');
    foreach ($store->getAll() as $hash=>$record) if($record['client']===$client)$store->delete($hash);
    $store->set(hash('sha256',$token),['client'=>$client,'expires'=>time()+90*86400]);
    \Drupal::state()->delete('nelkano_ai.heartbeat');
    return $token;
  }
  public static function worker(Request $request): string {
    $header=trim((string)$request->headers->get('Authorization',''));
    $record=preg_match('/^Bearer (nkaw_[a-f0-9]{64})$/D',$header,$m)?\Drupal::keyValue('nelkano_ai_workers')->get(hash('sha256',$m[1])):NULL;
    if(!is_array($record)||$record['expires']<=time())throw new HttpException(401,'invalid-worker-token');
    return $record['client'];
  }
}
