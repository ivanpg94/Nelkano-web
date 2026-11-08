<?php
/** One scoped loopback check. Removes its temporary account and experience. */
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\nelkano_home\Service\CompatibilityCatalog as Catalog;
use Drupal\user\Entity\User;

if(getenv('DRUPAL_DB_HOST')!=='database')throw new RuntimeException('Local Docker only.');
$user=NULL;$client=\Drupal::httpClient();$page='game-boy-advance';
$lists=Catalog::lists($page);$rows=Catalog::rows($page);$row=reset($rows);
if(!$row)throw new RuntimeException('A local catalog is required for the scoped check.');
$before=Catalog::revision($lists);
try {
  $nonce=bin2hex(random_bytes(6));$user=User::create(['name'=>'ratings-check-'.$nonce,'mail'=>$nonce.'@example.invalid','status'=>1]);$user->save();
  $session=AppSessions::issue($user,['id'=>'ratings-check-'.$nonce,'platform'=>'android','name'=>'Local check']);
  $post=static function($operation,$body)use($client,$session){$response=$client->post('http://localhost/api/nelkano/v1/compatibility/'.$operation,['headers'=>['Authorization'=>'Bearer '.$session['access_token']],'json'=>$body,'timeout'=>20]);return json_decode((string)$response->getBody(),TRUE,32,JSON_THROW_ON_ERROR);};
  $identity=['system_id'=>'gba','rom_sha256'=>hash('sha256',$nonce),'title'=>$row['nombre'],'version'=>'local-check','platform'=>'android'];
  $resolved=$post('resolve',$identity);$candidate=$resolved['matched']??($resolved['candidates'][0]??NULL);
  if(!$candidate)throw new RuntimeException('The existing game was not offered.');
  $saved=$post('rate',$identity+['game_id'=>$candidate['id'],'result'=>'playing','issues'=>FALSE,'comment'=>'','device'=>'Local check','revision'=>0]);
  if(empty($saved['saved']))throw new RuntimeException('Experience was not saved.');
  $html=(string)$client->get('http://localhost'.Catalog::path($page).'?q='.rawurlencode($candidate['title']),['timeout'=>20])->getBody();
  if(!str_contains($html,'nk-compat-community-count'))throw new RuntimeException('Experience was not shown in the existing compatibility page.');
  $module=$client->get('http://localhost/modulos/1409',['timeout'=>20]);
  if(!str_contains((string)$module->getBody(),'Valorar'))throw new RuntimeException('Public local module page missing.');
  echo "OK: authenticated resolve → save → existing compatibility row; public local module available.\n";
} finally {
  if($user)$user->delete();
  if(!hash_equals($before,Catalog::revision(Catalog::lists($page))))throw new RuntimeException('Catalog changed during read-only CSV check.');
  echo "Temporary account/experience removed; original CSV catalog unchanged.\n";
}
