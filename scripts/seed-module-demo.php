<?php
/** Persistent visual demo. Explicit local Docker opt-in; never a deployment update. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (getenv('NELKANO_LOCAL_MODULE_DEMO') !== '1' || \Drupal::database()->getConnectionOptions()['database'] !== 'nelkano_drupal'
  || \Drupal::database()->getConnectionOptions()['host'] !== 'database') {
  throw new RuntimeException('This seed runs only against the local Docker database with explicit opt-in.');
}
require_once DRUPAL_ROOT . '/modules/custom/nelkano_modules/tests/ModuleFixtures.php';
use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\nelkano_modules\Service\ModuleRegistry;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Drupal\user\Entity\User;
use NelkanoModuleTests\FakeDownloadClient;
use NelkanoModuleTests\Packages;

$definitions=json_decode(file_get_contents(__DIR__.'/module-demo.json'),TRUE,512,JSON_THROW_ON_ERROR);
$images='/tmp/nelkano-module-demo';
foreach($definitions as $demo) {
  if(($demo['image']??TRUE) && !is_readable($images.'/'.$demo['slug'].'.png')) { throw new RuntimeException('Render/copy the demo images first.'); }
}
$lock=\Drupal::lock();
if(!$lock->acquire('nelkano_modules_demo_seed',300)) { throw new RuntimeException('Another demo seed is running.'); }
$state=\Drupal::state();$state_key='nelkano_modules_demo_content';
$fixture=$state->get($state_key) ?: ['users'=>[],'modules'=>[],'files'=>[],'keypair'=>base64_encode(sodium_crypto_sign_keypair())];
$state->set($state_key,$fixture);
$keypair=base64_decode($fixture['keypair'],TRUE);
$fake=new FakeDownloadClient();$registry=new ModuleRegistry($fake,new PackageVerifier());
\Drupal::getContainer()->set('nelkano_modules.registry',$registry);
$switcher=\Drupal::service('account_switcher');$switched=FALSE;
try {
  foreach(['Ana','Bruno','Clara'] as $name) {
    $user=isset($fixture['users'][$name]) ? User::load($fixture['users'][$name]) : NULL;
    if(!$user) {
      $username='Nelkano Demo '.$name;
      if(\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name'=>$username])) { throw new RuntimeException('An unrelated account already uses '.$username); }
      $user=User::create(['name'=>$username,'mail'=>strtolower($name).'@nelkano-demo.example.invalid','status'=>1,'pass'=>bin2hex(random_bytes(32))]);
      $user->save();$fixture['users'][$name]=(int)$user->id();$state->set($state_key,$fixture);
    }
  }
  $authors=array_values($fixture['users']);
  foreach($definitions as $i=>$demo) {
    $slug=$demo['slug'];$module_id='example.demo.'.$slug;
    $owner=User::load($authors[$i%count($authors)]);
    $switcher->switchTo($owner);$switched=TRUE;
    $module=isset($fixture['modules'][$slug]) ? Node::load($fixture['modules'][$slug]) : NULL;
    if($module && ($module->bundle()!=='nelkano_module' || $module->get('field_module_id')->value!==$module_id)) { throw new RuntimeException('Demo state points to unrelated content.'); }
    if(!$module) {
      $existing=\Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('type','nelkano_module')->condition('field_module_id',$module_id)->execute();
      if($existing) { throw new RuntimeException('An unrelated module already uses '.$module_id); }
    }
    $verified=[];
    foreach($demo['versions'] as $j=>$version) {
      $url='https://github.com/nelkano-demo/'.$slug.'/releases/download/v'.$version.'/'.$slug.'.nelkmod';
      $fake->responses[$url]=['status'=>200,'body'=>Packages::make($module_id,$version,$keypair,$demo['platforms'],$j===0?'0.1.0':'0.2.0')];
      $verified[$version]=$registry->inspect($url);
    }
    if(!$module) {
      $fid=0;
      if($demo['image']??TRUE) {
        $file=isset($fixture['files'][$slug]) ? File::load($fixture['files'][$slug]) : NULL;
        if(!$file) {
          $directory='public://nelkano-modules/images';
          \Drupal::service('file_system')->prepareDirectory($directory,\Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
          $file=\Drupal::service('file.repository')->writeData(file_get_contents($images.'/'.$slug.'.png'),$directory.'/demo-'.$slug.'.png',FileExists::Rename);
          $fixture['files'][$slug]=(int)$file->id();$state->set($state_key,$fixture);
        }
        $fid=(int)$file->id();
      }
      $module=$registry->saveModule(NULL,NULL,['title'=>$demo['name'].' (prueba)',
        'description'=>$demo['description']."\n\nContenido de demostración local. Descargas simuladas; no es un módulo funcional.",
        'source'=>'','published'=>TRUE,'systems'=>$demo['systems'],'image'=>$fid,'notes'=>'Versión de demostración para revisar la ficha y el buscador. Descarga simulada.'],
        $verified[$demo['versions'][0]]);
      $fixture['modules'][$slug]=(int)$module->id();$state->set($state_key,$fixture);
    }
    if ($module->get('field_module_systems')->isEmpty()) {
      $module=$registry->saveModule($module,(int)$module->getRevisionId(),['title'=>$module->label(),
        'description'=>$module->get('field_module_description')->value,'source'=>$module->get('field_module_source')->value ?? '',
        'published'=>$module->isPublished(),'systems'=>$demo['systems']]);
    }
    $existing=[];
    foreach($registry->releases($module) as $release) {$existing[$release->get('field_release_version')->value]=$release;}
    foreach($verified as $version=>$metadata) {
      $release=$existing[$version]??NULL;
      $saved=$registry->saveVersion($module,$release,$release?(int)$release->getRevisionId():NULL,$metadata,
        'Versión '.$version.' de demostración local. Descarga simulada; permite revisar el selector y los detalles de versiones.');
      if(($demo['retired']??NULL)===(string)$version) {
        $fake->responses[$metadata['url']]=['status'=>404,'body'=>''];$registry->checkVersion((int)$saved->id());
      }
    }
    $switcher->switchBack();$switched=FALSE;
    echo $demo['name'].' -> /modulos/'.$module->id().PHP_EOL;
  }
  $index=Index::load('nelkano_modules');$index->indexItems();
  echo 'READY: '.count($fixture['modules']).' persistent local demos; '.count($fixture['users']).' demo owners; '.count($fixture['files'])." covers.\n";
  echo "Catalog: http://localhost:8088/modulos\n";
  echo "Mock download URLs may be retired by the regular cron; rerun this seed to restore the demos without duplicating them.\n";
}
finally {
  if($switched) {$switcher->switchBack();}
  $lock->release('nelkano_modules_demo_seed');
}