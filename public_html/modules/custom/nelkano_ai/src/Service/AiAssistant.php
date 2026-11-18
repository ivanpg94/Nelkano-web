<?php
declare(strict_types=1);
namespace Drupal\nelkano_ai\Service;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Optional bounded game context; never grants permissions or executes instructions. */
final class AiAssistant {
  public const CONTEXT_BYTES = 230000;
  public const STATE_BYTES = 524288;
  public static function validate(mixed $value, mixed $game): void {
    $bad = static fn() => throw new HttpException(400, 'invalid-assistant-context');
    if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['identity','memory','screen']) || !is_array($game)) $bad();
    $id = $value['identity'] ?? NULL; $memory = $value['memory'] ?? NULL;
    if (!is_array($id) || array_diff(array_keys($id), ['gameId','title','systemId','romSha256','platform','device','appVersion']) || ($id['gameId'] ?? NULL) !== ($game['id'] ?? NULL)) $bad();
    foreach (['gameId'=>256,'title'=>256,'systemId'=>64,'platform'=>32,'device'=>128,'appVersion'=>32] as $key=>$limit) if (!is_string($id[$key] ?? NULL) || mb_strlen($id[$key])>$limit) $bad();
    if (($id['romSha256'] ?? NULL) !== NULL && (!is_string($id['romSha256']) || !preg_match('/^[a-f0-9]{64}$/Di',$id['romSha256']))) $bad();
    if (!is_array($memory) || array_diff(array_keys($memory), ['edition','location','goal','spoilers'])) $bad();
    foreach (['edition'=>160,'location'=>320,'goal'=>320] as $key=>$limit) if (!is_string($memory[$key] ?? NULL) || mb_strlen($memory[$key])>$limit) $bad();
    if (!in_array($memory['spoilers'] ?? NULL,['none','hints','full'],TRUE)) $bad();
    if (!isset($value['screen'])) return;
    $s=$value['screen'];
    if (!is_array($s) || array_diff(array_keys($s),['mimeType','width','height','dataBase64','capturedAt']) || !in_array($s['mimeType']??NULL,['image/jpeg','image/png'],TRUE) || !is_string($s['dataBase64']??NULL) || strlen($s['dataBase64'])>196608 || !is_int($s['capturedAt']??NULL)) $bad();
    $bytes=base64_decode($s['dataBase64'],TRUE);
    if ($bytes===FALSE || strlen($bytes)<8 || strlen($bytes)>147456 || base64_encode($bytes)!==$s['dataBase64']) $bad();
    $info=@getimagesizefromstring($bytes);
    if (!$info || $info['mime']!==$s['mimeType'] || ($s['width']??NULL)!==$info[0] || ($s['height']??NULL)!==$info[1] || $info[0]>640 || $info[1]>480) $bad();
  }
}
