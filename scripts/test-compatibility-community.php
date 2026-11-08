<?php
use Drupal\nelkano_home\Service\CompatibilityCatalog as Catalog;
use Drupal\nelkano_home\Service\CompatibilityCommunity as Community;
use Drupal\nelkano_home\Service\AppSessions;
use Drupal\nelkano_home\Controller\CompatibilityCommunityController as Api;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

if(getenv('DRUPAL_DB_HOST')!=='database')throw new RuntimeException('Local Docker only.');
$db=\Drupal::database();$checks=0;$assert=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$tables=[Community::GAMES,Community::VARIANTS,Community::RATINGS];$snapshot=[];
foreach($tables as $table)$snapshot[$table]=$db->select($table,'t')->fields('t')->execute()->fetchAll(PDO::FETCH_ASSOC);
$stores=[];foreach([Catalog::COLLECTION,Catalog::LISTS_COLLECTION] as $collection)$stores[$collection]=\Drupal::keyValue($collection)->get('game-boy-advance');
$indexStore=\Drupal::keyValue('nelkano_home.compatibility_index');$originalIndex=$indexStore->getAll();
$user=NULL;
$csv=static function(array $rows):string{$file=fopen('php://temp','r+');fputcsv($file,Catalog::HEADERS,',','"','');foreach($rows as $row)fputcsv($file,array_map(static fn($header)=>$row[$header]??'',Catalog::HEADERS),',','"','');rewind($file);$result=stream_get_contents($file);fclose($file);return $result;};
$get=static function($path):string { echo 'HTTP '.$path.' transaction='.(int)\Drupal::database()->inTransaction().PHP_EOL; return (string)\Drupal::httpClient()->get('http://localhost'.$path,['timeout'=>30])->getBody(); };
try {
  $user=User::create(['name'=>'community-qa-'.bin2hex(random_bytes(4)),'mail'=>bin2hex(random_bytes(4)).'@example.invalid','status'=>1]);$user->save();$uid=(int)$user->id();
  $row=['nombre'=>'Acorn Grove QA','estado'=>'jugable','velocidad_porcentaje'=>'100','dispositivo'=>'PC QA','fecha prueba'=>'2026-11-07','sistema_id'=>'gba','juego_id'=>'acorn-grove-qa','rom_sha256'=>str_repeat('d',64)];
  $rows=Catalog::parse($csv([$row]));
  \Drupal::keyValue(Catalog::LISTS_COLLECTION)->delete('game-boy-advance');Catalog::import('game-boy-advance',$rows);
  $identity=['system_id'=>'gba','rom_sha256'=>str_repeat('d',64),'title'=>'Acorn Grove QA complete','version'=>'3.0.6-QA','platform'=>'android'];
  $resolved=Community::resolve($uid,$identity);$assert($resolved['matched']['title']==='Acorn Grove QA','Renamed filename not recognized by content');$game=$resolved['matched']['id'];
  $rating=$identity+['result'=>'completed','issues'=>FALSE,'comment'=>'QA <script>alert(1)</script>','device'=>'QA device','revision'=>0,'game_id'=>$game];
  $saved=Community::rate($uid,$rating);$assert($saved['revision']===1,'First rating not saved');
  $retry=Community::rate($uid,$rating);$assert($retry['revision']===1,'Idempotent retry changed revision');
  $stats=Community::gamesForPage('game-boy-advance');$assert($stats[$game]['completed']===1&&$stats[$game]['total']===1,'Retry duplicated completion');
  $other=$rating;$other['version']='3.0.5-QA';Community::rate($uid,$other);
  $assert(Community::gamesForPage('game-boy-advance')[$game]['completed']===1,'One user counted twice across versions');
  foreach(['es','en'] as $language){$html=$get(Catalog::path('game-boy-advance',$language));$assert(substr_count($html,'class="nk-compat-record"')===1,'CSV and community created duplicate rows');$assert(str_contains($html,'QA &lt;script&gt;alert(1)&lt;/script&gt;')&&!str_contains($html,'<script>alert(1)</script>'),'Public comment not escaped');$assert(str_contains($html,'nk-compat-community-count'),'Missing community summary');}
  $row['nombre']='Acorn Grove QA renamed';Catalog::import('game-boy-advance',Catalog::parse($csv([$row])));
  $assert(Community::resolve($uid,$identity)['matched']['id']===$game,'CSV rename changed permanent game identity');
  $assert(Community::gamesForPage('game-boy-advance')[$game]['total']===2,'Reimport discarded user experiences');
  $wrong=$row;$wrong['juego_id']='different-qa';
  try{Catalog::import('game-boy-advance',Catalog::parse($csv([$wrong])));$rejected=FALSE;}catch(InvalidArgumentException $e){$rejected=TRUE;}
  $assert($rejected&&array_values(Catalog::rows('game-boy-advance'))[0]['juego_id']==='acorn-grove-qa','Conflicting hash partially published CSV');
  $unknown=$identity;$unknown['rom_sha256']=str_repeat('e',64);$unknown['title']='Unknown community QA';
  $unknownRating=$unknown+['result'=>'playing','issues'=>TRUE,'comment'=>'Unknown version','device'=>'QA','revision'=>0,'game_id'=>''];
  $new=Community::rate($uid,$unknownRating);$assert($new['game_id']!==$game,'Unknown game merged by filename');
  $assert(Community::hasExperiences('game-boy-advance'),'Community page not visible');
  // Removing a CSV never removes community rows or their access button.
  \Drupal::keyValue(Catalog::COLLECTION)->delete('game-boy-advance');\Drupal::keyValue(Catalog::LISTS_COLLECTION)->delete('game-boy-advance');
  $html=$get(Catalog::path('game-boy-advance'));$assert(str_contains($html,'Unknown community QA')&&str_contains($html,'Sin prueba de IA'),'Community-only game not shown in existing page');
  $assert(str_contains($get('/sistemas/game-boy-advance'),'class="r-compat-link"'),'Community-only system lost access button');
  Catalog::import('game-boy-advance',Catalog::parse($csv([$row])));
  $entries=Community::moderation('game-boy-advance');$entry=array_values(array_filter($entries,static fn($r)=>$r['game_id']===$new['game_id']))[0];
  Community::moderate('game-boy-advance',['id'=>$entry['id'],'revision'=>(int)$entry['revision'],'action'=>'associate','game_id'=>$game]);
  $assert(Community::resolve($uid,$unknown)['matched']['id']===$game,'Moderator association was not reused');
  $entry=Community::moderation('game-boy-advance')[0];
  Community::moderate('game-boy-advance',['id'=>$entry['id'],'revision'=>(int)$entry['revision'],'action'=>'visibility','visible'=>FALSE]);
  $assert((int)$db->select(Community::RATINGS,'r')->fields('r',['visible'])->condition('id',$entry['id'])->execute()->fetchField()===0,'Moderation did not hide experience');
  $api=new Api();$request=new Request([],[],[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($rating));
  $assert($api->rate($request)->getStatusCode()===401,'Anonymous rating accepted');
  // Use an actual app bearer session, never a web-cookie session or supplied uid.
  $session=AppSessions::issue($user,['id'=>'community-qa','name'=>'QA','platform'=>'android']);
  $request->headers->set('Authorization','Bearer '.$session['access_token']);
  $apiBody=$identity;$apiBody['uid']=1;
  $request=new Request([],[],[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$session['access_token']],json_encode($apiBody));
  $assert($api->resolve($request)->getStatusCode()===200,'Authenticated public account could not resolve');
  $apiRating=$rating;$apiRating['version']='3.0.6-API-QA';$apiRating['uid']=1;
  $request=new Request([],[],[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$session['access_token']],json_encode($apiRating));
  $apiResponse=$api->rate($request);$assert($apiResponse->getStatusCode()===200,'Authenticated public account could not rate');
  $stored=$db->select(Community::RATINGS,'r')->fields('r',['uid'])->condition('version','3.0.6-API-QA')->execute()->fetchField();
  $assert((int)$stored===$uid,'Request body spoofed another account');
  $route=\Drupal::service('router.route_provider')->getRouteByName('nelkano_home.compatibility_moderate');
  $assert($route->getRequirement('_csrf_request_header_token')==='TRUE'&&$route->getRequirement('_permission')==='administer nelkano editable pages','Moderation access protections missing');
  $request=new Request([],[],[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$session['access_token']],'{bad');
  $assert($api->rate($request)->getStatusCode()===400,'Malformed JSON accepted');
  foreach(['rom_sha256'=>'bad','sistema_id'=>'not-a-console','juego_id'=>'Not Stable'] as $field=>$value){$bad=$row;$bad[$field]=$value;try{Catalog::parse($csv([$bad]));$rejected=FALSE;}catch(InvalidArgumentException $e){$rejected=TRUE;}$assert($rejected,'Invalid identity column accepted: '.$field);}
  Community::purge($uid);$assert(!$db->select(Community::RATINGS,'r')->condition('uid',$uid)->countQuery()->execute()->fetchField(),'Account deletion retained ratings');
  echo "PASS: $checks compatibility community checks.\n";
} finally {
  if($user)$user->delete();
  foreach(array_reverse($tables) as $table)$db->delete($table)->execute();
  foreach($snapshot as $table=>$rows)foreach($rows as $entry)$db->insert($table)->fields($entry)->execute();
  foreach($stores as $collection=>$value){$store=\Drupal::keyValue($collection);$value===NULL?$store->delete('game-boy-advance'):$store->set('game-boy-advance',$value);}
  $indexStore->deleteAll();$indexStore->setMultiple($originalIndex);
}
