<?php
use Drupal\nelkano_home\Service\SystemPages;
use Drupal\nelkano_home\Service\SeoMetadata;
use Drupal\nelkano_home\Form\AddSystemForm;
use Drupal\Core\Form\FormState;
if(getenv('DRUPAL_DB_HOST')!=='database'){throw new RuntimeException('Local Docker only.');}
$checks=0;$assert=static function($value,$message)use(&$checks){if(!$value){throw new RuntimeException($message);}++$checks;};
$id='qa-system-'.bin2hex(random_bytes(4));
$config=\Drupal::configFactory()->getEditable(SystemPages::CONFIG);
$get=static fn($url)=>\Drupal::httpClient()->get('http://localhost'.$url,['http_errors'=>FALSE,'allow_redirects'=>FALSE,'timeout'=>15]);
try {
  $form=new AddSystemForm();$state=(new FormState())->setValues(['name'=>'Nueva Consola Á','slug'=>'']);$render=[];
  $form->validateForm($render,$state);$assert($state->getValue('slug')==='nueva-consola-a','Automatic slug generation');
  SystemPages::add($id,'QA <System>');
  $assert(SystemPages::exists($id),'Creation failed');
  foreach(['es','en'] as $lang){
    $page=SystemPages::content($lang)['pages'][$id];
    $assert(!$page['enabled'] && !$page['show_on_home'],'New system must start hidden');
    $assert($page['overview']==='' && $page['features']==='' && $page['faq']===[],'Unexpected invented system content');
    $assert(isset(SeoMetadata::pages($lang)['system__'.$id]),'New system missing from SEO');
    $assert($get(SystemPages::path($id,$lang))->getStatusCode()===404,'Hidden system public page accessible');
  }
  try{SystemPages::add($id,'Duplicate');$assert(FALSE,'Duplicate accepted');}catch(InvalidArgumentException $e){$checks++;}
  try{SystemPages::add('../bad','Bad');$assert(FALSE,'Invalid slug accepted');}catch(InvalidArgumentException $e){$checks++;}
  $before=[];
  foreach(['es','en'] as $lang){$config->set($lang.'.pages.'.$id.'.summary','QA unique content')->set($lang.'.pages.'.$id.'.show_on_home',TRUE);}
  $config->save();
  SystemPages::setVisibility($id,TRUE);
  foreach(['es','en'] as $lang){
    $before[$lang]=SystemPages::content($lang)['pages'][$id];
    $assert($before[$lang]['enabled'],'Visibility not enabled');
    $response=$get(SystemPages::path($id,$lang));$assert($response->getStatusCode()===200,'New public route failed');
    $assert(str_contains((string)$response->getBody(),'QA &lt;System&gt;'),'New system name not escaped');
    foreach([$lang==='es'?'/':'/en',$lang==='es'?'/sistemas':'/en/systems','/sitemap.xml'] as $url){$assert(str_contains((string)$get($url)->getBody(),SystemPages::path($id,$lang)),'Published system missing from '.$url);}
  }
  SystemPages::setVisibility($id,FALSE);
  foreach(['es','en'] as $lang){
    $page=SystemPages::content($lang)['pages'][$id];$expected=$before[$lang];$expected['enabled']=FALSE;
    $assert($page===$expected,'Hiding changed editorial data');
    $assert($get(SystemPages::path($id,$lang))->getStatusCode()===404,'Hidden page still available');
    foreach([$lang==='es'?'/':'/en',$lang==='es'?'/sistemas':'/en/systems','/sitemap.xml'] as $url){$assert(!str_contains((string)$get($url)->getBody(),SystemPages::path($id,$lang)),'Hidden system still linked in '.$url);}
  }
  SystemPages::setVisibility($id,TRUE);
  foreach(['es','en'] as $lang){$assert(SystemPages::content($lang)['pages'][$id]===$before[$lang],'Restoring visibility lost data');}
  // The editor has one shared checkbox, regardless of the edited language.
  $editor=\Drupal\nelkano_home\Form\SystemSettingsForm::create(\Drupal::getContainer());
  foreach (['es'=>FALSE,'en'=>TRUE] as $language=>$visible) {
    $data=SystemPages::content($language);$entry=$data['pages'][$id];$entry['enabled']=$visible;
    $state=(new FormState())->setValues(['active_language'=>$language,$language=>['systems'=>['pages'=>[$id=>$entry]]]]);
    $trigger=['#r_system'=>$id,'#r_fields'=>['enabled']];$state->setTriggeringElement($trigger);$render=[];
    $editor->submitForm($render,$state);
    foreach (['es','en'] as $locale) {
      $expected=$before[$locale];$expected['enabled']=$visible;
      $assert(SystemPages::content($locale)['pages'][$id]===$expected,'Shared checkbox changed content or failed to sync '.$locale);
    }
  }
  $schema=\Drupal::service('config.typed')->createFromNameAndData(SystemPages::CONFIG,$config->getRawData());$assert(count($schema->validate())===0,'New system violates config schema');
  $assert($get('/admin/nelkano/sistemas/nuevo')->getStatusCode()===403,'Anonymous creation access');
}finally{
  foreach(['es','en'] as $lang){$config->clear($lang.'.pages.'.$id);}$config->save();
}
echo "PASS: $checks system catalogue checks; temporary system removed.\n";
