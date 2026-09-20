<?php
/** Local integration checks: real Drupal upload/validation, persistence, shared images, filters and protected pages. */
use Drupal\Core\Form\FormState;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\nelkano_home\Service\SystemPages;
use Drupal\nelkano_home\Service\RedesignContent;
if (getenv('DRUPAL_DB_HOST') !== 'database') {throw new RuntimeException('Local Docker only.');}
$checks=0;
$assert=static function($value,$message)use(&$checks){if(!$value){throw new RuntimeException($message);} $checks++;};
$get=static fn($url)=>\Drupal::httpClient()->get('http://localhost'.$url,['http_errors'=>FALSE,'timeout'=>20]);
$config=\Drupal::configFactory()->getEditable(SystemPages::CONFIG);$original=$config->getRawData();$uploaded=NULL;$temp=NULL;
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
try {
  foreach(['nelkano_home.settings','nelkano_home.systems','nelkano_home.guide','nelkano_home.docs'] as $name){
    $schema=\Drupal::service('config.typed')->createFromNameAndData($name,\Drupal::config($name)->getRawData());
    $violations=$schema->validate();$messages=[];foreach($violations as $v){$messages[]=$v->getPropertyPath().': '.$v->getMessage();}
    $assert(count($violations)===0,'Schema '.$name.': '.implode('; ',$messages));
  }
  foreach(['/','/en','/sistemas','/en/systems','/guia','/en/guide'] as $url){
    $r=$get($url);$assert($r->getStatusCode()===200,'HTTP '.$url);$assert(str_contains((string)$r->getBody(),'class="nkr"'),'Missing redesign scope '.$url);
  }
  $index=(string)$get('/sistemas')->getBody();
  $assert(substr_count($index,'class="r-card"')===count(array_filter($original['es']['pages'],static fn($p)=>$p['enabled'])),'Incorrect system count');
  $filtered=(string)$get('/sistemas?nivel=jugable')->getBody();
  $expected=count(array_filter($original['es']['pages'],static fn($p)=>$p['enabled'] && $p['tier']==='jugable'));
  $assert(substr_count($filtered,'class="r-card"')===$expected,'Category filtering');
  $assert(substr_count((string)$get('/sistemas?nivel=not-valid')->getBody(),'class="r-card"')===substr_count($index,'class="r-card"'),'Invalid filter must show all');
  $directory='public://nelkano-images';\Drupal::service('file_system')->prepareDirectory($directory,FileSystemInterface::CREATE_DIRECTORY);
  $temp=tempnam(sys_get_temp_dir(),'nk-redesign-');copy(DRUPAL_ROOT.'/modules/custom/nelkano_home/assets/logo.png',$temp);
  $validators=['FileExtension'=>['extensions'=>'png jpg jpeg webp gif'],'FileIsImage'=>[]];
  $upload=new InputStreamUploadedFile('redesign-qa.png','redesign-qa.png',$temp,filesize($temp));
  $result=\Drupal::service('file.upload_handler')->handleFileUpload($upload,$validators,$directory,FileExists::Rename);
  $uploaded=$result->getFile();$assert($uploaded!==NULL,'Real PNG upload failed');$temp=NULL;
  $data=SystemPages::content('es');$id='chip-8';$entry=$data['pages'][$id];
  $entry['image_uri']=[$uploaded->id()];$entry['related_ids']=SystemPages::lines($entry['related_ids']);
  $state=(new FormState())->setValues(['active_language'=>'es','es'=>['systems'=>['labels'=>$data['labels'],'pages'=>[$id=>$entry]]]]);
  $trigger=['#r_system'=>$id,'#r_fields'=>['image_uri']];$state->setTriggeringElement($trigger);
  $form=\Drupal\nelkano_home\Form\SystemSettingsForm::create(\Drupal::getContainer());$render=[];$form->submitForm($render,$state);
  $uri=\Drupal::config(SystemPages::CONFIG)->get('es.pages.'.$id.'.image_uri');
  $assert($uri===$uploaded->getFileUri(),'Image field did not persist managed URI');
  $assert(\Drupal\file\Entity\File::load($uploaded->id())->isPermanent(),'Image was not made permanent');
  $url=RedesignContent::image($uri,'chip-8');
  foreach(['/','/sistemas','/sistemas/chip-8'] as $route){$assert(str_contains((string)$get($route)->getBody(),$url),'Uploaded image missing on '.$route);}
  $assert($get($url)->getStatusCode()===200,'Uploaded image inaccessible');
  $after=\Drupal::config(SystemPages::CONFIG)->getRawData();$after['es']['pages'][$id]['image_uri']=$original['es']['pages'][$id]['image_uri'];
  $assert($after===$original,'Image section changed unrelated fields or another language');
  $temp=tempnam(sys_get_temp_dir(),'nk-redesign-');file_put_contents($temp,'<script>alert(1)</script>');
  $bad=new InputStreamUploadedFile('not-an-image.png','not-an-image.png',$temp,filesize($temp));
  $rejected=\Drupal::service('file.upload_handler')->handleFileUpload($bad,$validators,$directory,FileExists::Rename);
  $assert($rejected->hasViolations(),'Fake PNG accepted');
  // Protected public pages must not receive redesigned CSS/content.
  foreach(['/contacto','/user/login','/user/register','/aviso-legal','/privacidad-cookies'] as $route){
    $html=(string)$get($route)->getBody();$assert(!str_contains($html,'class="nkr"') && !str_contains($html,'redesign-admin'),'Redesign leaked into '.$route);
  }
  // Compile templates too, including empty states and guide lightbox.
  foreach(glob(DRUPAL_ROOT.'/modules/custom/nelkano_home/templates/*redesign*.twig') as $path){\Drupal::service('twig')->createTemplate(file_get_contents($path));$checks++;}
}finally{
  $config->setData($original)->save();
  if($uploaded){$uploaded->delete();}
  if($temp && is_file($temp)){unlink($temp);}
  \Drupal::service('account_switcher')->switchBack();
}
echo "PASS: $checks redesign checks; original configuration restored and temporary upload removed.\n";

