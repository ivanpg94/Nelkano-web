<?php
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_MODULE_CATALOG_TEST') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal') {
  throw new RuntimeException('Explicit local Docker opt-in required.');
}
require_once __DIR__ . '/ModuleFixtures.php';
use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Drupal\user\Entity\User;
use GuzzleHttp\Cookie\CookieJar;
use NelkanoModuleTests\FakeDownloadClient;
use NelkanoModuleTests\Packages;

$checks=0;
$check=static function ($ok,$label) use (&$checks) { if (!$ok) {throw new RuntimeException('FAIL: '.$label);} $checks++; echo 'PASS: '.$label.PHP_EOL; };
$jar=new CookieJar();
$http=\Drupal::httpClient();
$call=static function ($path, array $query=[], array $headers=[], string $method='GET') use ($http,$jar): array {
  $response=$http->request($method,'http://127.0.0.1'.$path,['query'=>$query,'headers'=>$headers+['Accept'=>'application/json'],
    'http_errors'=>FALSE,'allow_redirects'=>FALSE,'cookies'=>$jar]);
  $body=(string)$response->getBody();
  $data=$body==='' ? NULL : json_decode($body,TRUE,512,JSON_THROW_ON_ERROR);
  return [$response,$data];
};
$prefix='apitest'.bin2hex(random_bytes(4)); $id='example.'.$prefix;
$modules=[]; $users=[]; $files=[]; $switched=FALSE;
$switcher=\Drupal::service('account_switcher'); $key=sodium_crypto_sign_keypair();
$fake=new FakeDownloadClient(); $registry=new ModuleRegistry($fake,new PackageVerifier());
\Drupal::getContainer()->set('nelkano_modules.registry',$registry);
$values=['title'=>'Colección ágil '.$prefix,'description'=>str_repeat('Organiza tus juegos. ',40), 'source'=>'https://github.com/example/'.$prefix,'published'=>TRUE,'notes'=>'Primera versión'];
$api='/api/nelkano/v1/modules'; $index=Index::load('nelkano_modules'); $index_enabled=$index->status();
try {
  $owner=User::create(['name'=>$prefix,'mail'=>$prefix.'@example.invalid','status'=>1]); $owner->save(); $users[]=$owner;
  $switcher->switchTo($owner); $switched=TRUE;
  $url1='https://github.com/example/'.$prefix.'/v1/module.nelkmod';
  $url2='https://github.com/example/'.$prefix.'/v2/module.nelkmod';
  $fake->responses[$url1]=['status'=>200,'body'=>Packages::make($id,'1.0.0',$key,['android'],'0.1.0')];
  $fake->responses[$url2]=['status'=>200,'body'=>Packages::make($id,'2.0.0',$key,['jvm-desktop'])];
  $file=\Drupal::service('file.repository')->writeData(file_get_contents(__DIR__.'/../../nelkano_home/assets/social-card.png'),
    'public://nelkano-modules/images/'.$prefix.'.png',FileExists::Error); $files[]=(int)$file->id();
  $module=$registry->saveModule(NULL,NULL,$values+['image'=>(int)$file->id()],$registry->inspect($url1)); $modules[]=(int)$module->id();
  $v1=$registry->releases($module)[0];
  $v2=$registry->saveVersion($module,NULL,NULL,$registry->inspect($url2),'Segunda versión');
  // More than one Search API batch, so sdk post-filtering must advance correctly.
  for($i=0;$i<52;$i++) {
    $mid=$id.'.item'.$i; $url='https://github.com/example/'.$prefix.'/m'.$i.'.nelkmod';
    $fake->responses[$url]=['status'=>200,'body'=>Packages::make($mid,'1.0.0',$key,['android'])];
    $node=$registry->saveModule(NULL,NULL,array_replace($values,['title'=>'Módulo '.$i.' '.$prefix]),$registry->inspect($url)); $modules[]=(int)$node->id();
  }
  $draft_url='https://github.com/example/'.$prefix.'/draft.nelkmod';
  $fake->responses[$draft_url]=['status'=>200,'body'=>Packages::make($id.'.draft','1.0.0',$key)];
  $draft=$registry->saveModule(NULL,NULL,array_replace($values,['published'=>FALSE]),$registry->inspect($draft_url)); $modules[]=(int)$draft->id();
  $switcher->switchBack(); $switched=FALSE;
  $index->indexItems();
  [$response,$data]=$call($api,['q'=>$prefix]);
  $check($response->getStatusCode()===200 && $data['api_version']===1 && $data['ok'], 'public JSON API without authentication');
  $check(str_contains($response->getHeaderLine('Content-Type'),'application/json') && $response->getHeaderLine('X-Content-Type-Options')==='nosniff', 'JSON content type and nosniff');
  $seen=[];$next_query=['q'=>$prefix,'limit'=>'20'];$pages=0;
  do {
    [$r,$page]=$call($api,$next_query); $check($r->getStatusCode()===200,'catalog page HTTP 200');
    foreach($page['items'] as $item) { $check(!isset($seen[$item['id']]),'no duplicate across catalog pages'); $seen[$item['id']]=TRUE; }
    $next=$page['pagination']['next_url']; $next_query=[];
    if($next) {parse_str(parse_url($next,PHP_URL_QUERY),$next_query);}
    $pages++;
  } while($next && $pages<10);
  $check(count($seen)===53 && !$next && $pages===3, 'all public modules paginated with stable order');
  [$r,$page]=$call($api,['q'=>$prefix,'platform'=>'android','sdk'=>'0.1.0']);
  $check(count($page['items'])===1 && $page['items'][0]['id']===$id && !$page['pagination']['has_more'], 'SDK filter traverses more than one Search API batch');
  $check($page['items'][0]['latest_release']['version']==='1.0.0', 'latest release compatible with Android and exact SDK');
  $check(mb_strlen($page['items'][0]['description'])===320, 'catalog description bounded');
  $check(str_contains($page['items'][0]['image']['thumbnail_url'],'/styles/nelkano_module_card/') && str_starts_with($page['items'][0]['image']['url'],'http://127.0.0.1/'), 'absolute image and thumbnail URLs');
  [$r,$detail]=$call($api.'/'.$id);
  $check($detail['module']['latest_release']['version']==='2.0.0' && mb_strlen($detail['module']['description'])>320, 'detail includes full description and newest release');
  $check($detail['module']['source_url']===$values['source'] && $detail['module']['signing']['author_fingerprint_sha256']===$module->get('field_module_author')->value, 'source and SDK signing fingerprint');
  $check(!str_contains(json_encode($detail),'@example.invalid') && !isset($detail['module']['author']['uid']), 'no email, user ID or private owner diagnostics');
  [$r,$versions]=$call($api.'/'.$id.'/versions',['limit'=>'1']);
  $check($versions['items'][0]['version']==='2.0.0' && $versions['pagination']['next_offset']===1, 'versions paginated newest first');
  [$r,$older]=$call($api.'/'.$id.'/versions',['limit'=>'1','offset'=>'1']);
  $check($older['items'][0]['version']==='1.0.0' && !$older['pagination']['has_more'], 'old version retained and addressable');
  [$r,$release]=$call($api.'/'.$id.'/versions/1.0.0');
  $check($r->getStatusCode()===200 && $release['release']['download_url']===$url1 && $release['release']['notes']==='Primera versión', 'specific version includes external download and full notes');
  $check($release['release']['sha256']===hash('sha256',$fake->responses[$url1]['body']) && $release['release']['size_bytes']===strlen($fake->responses[$url1]['body']), 'hash and byte size identify exact signed package');
  $check($release['release']['sdk_version']==='0.1.0' && $release['release']['capabilities']===['library.read'], 'SDK and permissions match package');
  $etag=$r->getHeaderLine('ETag');
  [$r,$not_modified]=$call($api.'/'.$id.'/versions/1.0.0',[],['If-None-Match'=>$etag]);
  $check($r->getStatusCode()===304 && $not_modified===NULL, 'ETag revalidation returns bodyless 304');
  [$r,$none]=$call($api,[],[],'HEAD');
  $check($r->getStatusCode()===200 && $none===NULL, 'HEAD supported without JSON body');
  [$r,$none]=$call($api,['q'=>'definitelynonexistent'.$prefix]);
  $check($none['items']===[] && $none['pagination']['next_url']===NULL, 'empty search is HTTP 200');
  foreach([['q'=>['array']],['limit'=>'0'],['limit'=>'21'],['offset'=>'-1'],['offset'=>'1000001'],['platform'=>'ios'],['sdk'=>'^0.2.0'],['sdk'=>'0.2.0,'],['unknown'=>'x']] as $bad) {
    [$r,$error]=$call($api,$bad); $check($r->getStatusCode()===400 && $error['code']==='invalid_parameters', 'invalid API query rejected as JSON');
  }
  foreach(['/'. $id.'.missing', '/'.$id.'/versions/9.0.0', '/'.$id.'/versions/garbage', '/'.$id.'.draft'] as $path) {
    [$r,$error]=$call($api.$path); $check($r->getStatusCode()===404 && !$error['ok'],'unavailable identifier returns JSON 404');
  }
  [$r,$error]=$call($api,[],[],'POST');
  $check($r->getStatusCode()===405 && $error['code']==='method_not_allowed' && str_contains($r->getHeaderLine('Allow'),'GET'), 'write method rejected with JSON 405');
  $fake->responses[$url2]=['status'=>404,'body'=>'']; $registry->checkVersion((int)$v2->id());
  [$r,$error]=$call($api.'/'.$id.'/versions/2.0.0');
  $check($r->getStatusCode()===410 && $error['code']==='download_unavailable' && $error['release']['download_url']===NULL, 'broken version returns 410 without download URL');
  [$r,$versions]=$call($api.'/'.$id.'/versions');
  $check(!$versions['items'][0]['available'] && $versions['items'][1]['available'], 'old valid version remains selectable');
  $fake->responses[$url1]=['status'=>404,'body'=>'']; $registry->checkVersion((int)$v1->id());
  [$r,$gone]=$call($api.'/'.$id.'/versions/1.0.0',[],['If-None-Match'=>$etag]);
  $check($r->getStatusCode()===410 && $gone['code']==='download_unavailable', 'old ETag cannot hide a revoked download');
  [$r,$detail]=$call($api.'/'.$id);
  $check($r->getStatusCode()===200 && !$detail['module']['available'] && $detail['module']['latest_release']===NULL, 'all broken releases preserve public fiche with unavailable state');
  [$r,$page]=$call($api,['q'=>$id,'platform'=>'jvm-desktop']);
  $check($page['items']===[], 'unavailable module absent from catalog');
  $fake->responses[$url1]=['status'=>200,'body'=>Packages::make($id,'1.0.0',$key,['android'],'0.1.0')];
  $registry->checkVersion((int)$v1->id());
  [$r,$restored]=$call($api.'/'.$id.'/versions/1.0.0',[],['If-None-Match'=>$etag]);
  $check($r->getStatusCode()===200 || $r->getStatusCode()===304, 'restored identical package regains download availability');
  // Cookie authentication must never expose a draft through the public contract.
  $time=time(); $login=$http->get('http://127.0.0.1/user/reset/'.$owner->id().'/'.$time.'/'.user_pass_rehash($owner,$time).'/login', ['cookies'=>$jar,'allow_redirects'=>FALSE]);
  [$r,$error]=$call($api.'/'.$id.'.draft');
  $check($r->getStatusCode()===404 && $error['code']==='module_not_found','owner session cannot expose draft through API');
  $jar->clear();
  $index->disable()->save();
  [$r,$error]=$call($api);
  $check($r->getStatusCode()===503 && $error['code']==='catalog_unavailable' && $r->getHeaderLine('Retry-After')==='60', 'unavailable Search API index returns retryable JSON 503');
  $index->enable()->save();
  $flood=\Drupal::service('flood');$test_ip='198.51.100.123';
  for($i=0;$i<240;$i++) {$flood->register('nelkano_modules.public_api',60,$test_ip);}
  $request=\Symfony\Component\HttpFoundation\Request::create($api,'GET',[],[],[],['REMOTE_ADDR'=>$test_ip]);
  $controller=new \Drupal\nelkano_modules\Controller\ModuleApiController(\Drupal::service('nelkano_modules.api_catalog'),$flood);
  try {$controller->catalog($request);throw new RuntimeException('Rate limit failed');}
  catch(\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e) {$check($e->getHeaders()['Retry-After']==='60' || $e->getHeaders()['Retry-After']===60,'public API rate limit enforced');}
  $flood->clear('nelkano_modules.public_api',$test_ip);
  $check(count($fake->requests)===58,'read-only HTTP queries never fetch packages or trigger cron');
  echo "SUCCESS: $checks API checks.\n";
}
finally {
  if($switched) {$switcher->switchBack();}
  if($index_enabled) {$index->enable()->save();}
  foreach($modules as $nid) {Node::load($nid)?->delete();}
  foreach($files as $fid) {File::load($fid)?->delete();}
  foreach($users as $user) {$user->delete();}
  \Drupal::service('flood')->clear('nelkano_modules.public_api','198.51.100.123');
  echo "Only synthetic API nodes/accounts/files removed.\n";
}