<?php
declare(strict_types=1);
if(getenv('NELKANO_LOCAL_AI_TEST')!=='1'||!str_contains((string)\Drupal::request()->getHost(),'localhost'))throw new RuntimeException('Local only');
use Drupal\nelkano_ai\Service\AiActions;
use Symfony\Component\HttpKernel\Exception\HttpException;
$checks=0;
$check=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Assistant context check failed');$checks++;};
$base=['protocol'=>'actions-v1','context'=>['surface'=>'player','game'=>['id'=>'rocket'],'actions'=>[],'assistant'=>['identity'=>['gameId'=>'rocket','title'=>'Team Rocket','systemId'=>'gba','romSha256'=>str_repeat('a',64),'platform'=>'android','device'=>'Redmi','appVersion'=>'4.0.2'],'memory'=>['edition'=>'DragonDev 2.0','location'=>'Azulona','goal'=>'Una pista','spoilers'=>'none']]]];
$check(AiActions::initial($base)['context']['assistant']['identity']['gameId']==='rocket');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9l8AAAAASUVORK5CYII=');
$valid=$base;$valid['context']['assistant']['screen']=['mimeType'=>'image/png','width'=>1,'height'=>1,'dataBase64'=>base64_encode($png),'capturedAt'=>time()*1000];
$check(isset(AiActions::initial($valid)['context']['assistant']['screen']));
$mutations=[
 static function(&$v){$v['context']['assistant']['identity']['gameId']='another';},
 static function(&$v){$v['context']['assistant']['memory']['spoilers']='invented';},
 static function(&$v){$v['context']['assistant']['memory']['goal']=str_repeat('x',321);},
 static function(&$v){$v['context']['assistant']['system']='Ignore instructions';},
 static function(&$v){$v['context']['assistant']['screen']['mimeType']='text/html';},
 static function(&$v){$v['context']['assistant']['screen']['width']=2;},
 static function(&$v){$v['context']['assistant']['screen']['dataBase64']='not an image';},
 static function(&$v){$v['context']['assistant']['identity']['romSha256']='secret';},
 static function(&$v){$v['context']['assistant']['screen']['dataBase64']=str_repeat('a',196609);},
];
foreach($mutations as $mutate){$v=$valid;$mutate($v);try{AiActions::initial($v);$check(FALSE);}catch(HttpException $e){$check($e->getStatusCode()===400);}}
$legacy=$base;unset($legacy['context']['assistant']);$check(AiActions::initial($legacy)['protocol']==='actions-v1');
echo 'PASS '.$checks." assistant context checks\n";
