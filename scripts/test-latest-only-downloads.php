<?php
/** Process-local catalog and tiny synthetic files; no publication or real installers. */
declare(strict_types=1);
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\nelkano_home\Controller\HomeController;
use Drupal\nelkano_home\Controller\AppUpdatesController;
use Drupal\nelkano_home\Service\ReleaseArtifact;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
if(PHP_SAPI!=='cli' || getenv('DRUPAL_DB_HOST')!=='database' || DRUPAL_ROOT!=='/opt/drupal/web')throw new RuntimeException('Local Docker fixtures only.');
$override=new class implements ConfigFactoryOverrideInterface {
 public array $rows=[];
 public function loadOverrides($names){$rows=array_replace(array_fill(0,100,['visible'=>FALSE]),$this->rows);return ['nelkano_home.docs'=>['es'=>['releases_items'=>$rows],'en'=>['releases_items'=>$rows]]];}
 public function getCacheSuffix(){return 'latest-only-isolated-qa';}
 public function createConfigObject($name,$collection=StorageInterface::DEFAULT_COLLECTION){return NULL;}
 public function getCacheableMetadata($name){return (new CacheableMetadata())->setCacheMaxAge(0);}
};
$factory=\Drupal::configFactory();$before=$factory->getEditable('nelkano_home.docs')->getRawData();$factory->addOverride($override);
$fs=\Drupal::service('file_system');$dir='private://nelkano-releases';$fs->prepareDirectory($dir,1);$uris=[];$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$blocked=static function(callable $call)use($check):void{try{$call();throw new RuntimeException('An old download was served');}catch(NotFoundHttpException $expected){$check(TRUE,'blocked');}};
try {
 $tag='latest-only-qa-'.bin2hex(random_bytes(8));
 foreach(ReleaseArtifact::PLATFORMS as $platform=>[$label,$extension,$channel]) {
  foreach([['9.1.0',901],['9.2.0',902],['9.9.0',999]] as [$version,$code]) {
   $uri=$dir.'/'.$tag.'-'.$platform.'-'.$code.'.'.$extension;$uris[]=$uri;if(file_put_contents($uri,'fixture-'.$platform.'-'.$code)===FALSE)throw new RuntimeException('Fixture file storage unavailable.');
   $row=['visible'=>$code!==999,'platform'=>$platform,'version'=>$version,'version_code'=>$code,'apk_file'=>$uri,'date'=>'31/10/2026','changes'=>'Retained notes '.$platform.' '.$version];
   if(in_array($platform,['windows','sdk'],TRUE)){$installer=$dir.'/'.$tag.'-'.$platform.'-'.$code.'.exe';$uris[]=$installer;file_put_contents($installer,'installer-'.$platform.'-'.$code);$row['installer_file']=$installer;}
   $override->rows[]=$row;
  }
 }
 $factory->reset('nelkano_home.docs');$home=HomeController::create(\Drupal::getContainer());$updates=new AppUpdatesController();
 foreach(['es','en'] as $language)foreach(ReleaseArtifact::PLATFORMS as $platform=>[$label,$extension,$channel]) {
  \Drupal::request()->query->set('plataforma',$platform);
  $html=($language==='es'?$home->releasesSpanish():$home->releasesEnglish())->getContent();
  $dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
  $check($xpath->query('//article[contains(@class,"r-release-card")]')->length===2,'Historical notes disappeared or a hidden draft was published');
  $links=$xpath->query('//article[contains(@class,"r-release-card")]//a[contains(@class,"r-btn")]');
  $check($links->length===1 && str_contains($links->item(0)->getAttribute('href'),'9.2.0'),'More than the latest release has a download button');
  $check(str_contains($html,'Retained notes '.$platform.' 9.1.0'),'Older release notes lost');
  $blocked(static fn()=>$home->downloadRelease($language,'9.1.0',$platform));
  if(in_array($platform,['windows','sdk'],TRUE))$blocked(static fn()=>$home->downloadRelease($language,'9.1.0',$platform,TRUE));
  $check(file_get_contents($home->downloadRelease($language,'9.2.0',$platform)->getFile()->getPathname())==='fixture-'.$platform.'-902','Current release changed');
  $manifest=json_decode($updates->latest($channel)->getContent(),TRUE,flags:JSON_THROW_ON_ERROR);
  $check($manifest['latest']['version']==='9.2.0' && $manifest['latest']['version_code']===902,'Wrong updater version');
  $blocked(static fn()=>$updates->download($channel,'901'));
  $blocked(static fn()=>$updates->download($channel,'999'));
  $check(file_get_contents($updates->download($channel,'902')->getFile()->getPathname())==='fixture-'.$platform.'-902','Latest updater bytes changed');
 }
 // Missing current payloads must never cause a silent fallback to old binaries.
 foreach(ReleaseArtifact::PLATFORMS as $platform=>[$label,$extension,$channel]) {
  $row=ReleaseArtifact::newest($override->rows,$platform);$saved=[];
  foreach(['apk_file','installer_file'] as $field)if(!empty($row[$field])){$saved[$row[$field]]=file_get_contents($row[$field]);unlink($row[$field]);}
  try {
   $check(ReleaseArtifact::latestPublished($platform)===[],'Missing newest installer fell back to an old one');
   $check(json_decode($updates->latest($channel)->getContent(),TRUE)['latest']===NULL,'Missing newest update fell back to old bytes');
   $blocked(static fn()=>$updates->download($channel,'901'));
  }finally{foreach($saved as $uri=>$bytes)file_put_contents($uri,$bytes);}
 }
 $check(nelkano_home_file_download('private://app-updates/android-arm64/old.apk')===-1,'Legacy private file bypass accepted');
 $check($factory->getEditable('nelkano_home.docs')->getRawData()===$before,'Stored catalog changed during fixtures');
 echo json_encode(['passed'=>TRUE,'assertions'=>$checks,'scope'=>'Synthetic files and process-only config; ES/EN, four platforms, old routes, update API and missing-current fallback'],JSON_THROW_ON_ERROR)."\n";
}finally{foreach($uris as $uri)if(is_file($uri))unlink($uri);$factory->reset('nelkano_home.docs');}
