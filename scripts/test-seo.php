<?php
use Drupal\Core\Form\FormState;
use Drupal\nelkano_home\Service\SeoMetadata;
use Drupal\nelkano_home\Form\SeoSettingsForm;
if(getenv('DRUPAL_DB_HOST')!=='database'){throw new RuntimeException('Local Docker only.');}
$checks=0;$assert=static function($value,$message)use(&$checks){if(!$value){throw new RuntimeException($message);}++$checks;};
$config=\Drupal::configFactory()->getEditable(SeoMetadata::CONFIG);$original=$config->getRawData();
$legacy=[];foreach(['settings','systems','docs','guide'] as $name){$legacy[$name]=\Drupal::config('nelkano_home.'.$name)->getRawData();}
$get=static fn($path)=>\Drupal::httpClient()->get('http://localhost'.$path,['http_errors'=>FALSE,'timeout'=>15]);
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
try{
  $form=SeoSettingsForm::create(\Drupal::getContainer());
  foreach(['es','en'] as $language){
    foreach(SeoMetadata::pages($language) as $key=>$page){
      $values=SeoMetadata::editable($key,$language);
      $values['title']='SEO QA '.$language.' '.$key.' <b> & "';
      $values['description']='Description QA '.$language.' '.$key.' <script> & "';
      $values['keywords']='alpha, beta';$values['robots']='noindex, follow';
      $values['canonical']='https://example.invalid/'.$language.'/'.$key;
      $values['og_title']='OG '.$key;$values['og_description']='OG description '.$key;
      $values['image']='https://example.invalid/social.png';$values['image_alt']='Image '.$key;
      $values['twitter_title']='Twitter '.$key;$values['twitter_description']='Twitter description '.$key;
      $values['twitter_card']='summary';$values['site_name']='Nelkano QA';
      $state=(new FormState())->setValues(['language'=>$language,'pages'=>[$key=>$values]]);
      $trigger=['#seo_page'=>$key];$state->setTriggeringElement($trigger);$render=[];
      $before=$config->getRawData();$form->submitForm($render,$state);
      $expected=$before;$expected[$language]['pages'][$key]=$values;
      $assert($config->getRawData()===$expected,'Per-page save changed other metadata');
      $response=$get($page['path']);$assert($response->getStatusCode()===200,'HTTP '.$page['path']);
      $dom=new DOMDocument();@$dom->loadHTML((string)$response->getBody());$xp=new DOMXPath($dom);
      $title=$xp->query('//head/title');$assert($title->length===1 && $title->item(0)->textContent===$values['title'],'Title escaping '.$page['path']);
      foreach(['description'=>'description','keywords'=>'keywords','robots'=>'robots','twitter:card'=>'twitter_card','twitter:title'=>'twitter_title','twitter:description'=>'twitter_description','twitter:image'=>'image','twitter:image:alt'=>'image_alt'] as $tag=>$field){
        $nodes=$xp->query('//head/meta[@name="'.$tag.'"]');$assert($nodes->length===1 && $nodes->item(0)->getAttribute('content')===$values[$field],$tag.' '.$page['path']);
      }
      foreach(['og:title'=>'og_title','og:description'=>'og_description','og:image'=>'image','og:image:alt'=>'image_alt','og:site_name'=>'site_name','og:type'=>'og_type','og:url'=>'canonical'] as $tag=>$field){
        $nodes=$xp->query('//head/meta[@property="'.$tag.'"]');$assert($nodes->length===1 && $nodes->item(0)->getAttribute('content')===$values[$field],$tag.' '.$page['path']);
      }
      $nodes=$xp->query('//head/link[@rel="canonical"]');$assert($nodes->length===1 && $nodes->item(0)->getAttribute('href')===$values['canonical'],'Canonical '.$page['path']);
      $assert($xp->query('//head/title/b|//head/meta/script')->length===0,'Unescaped metadata');
    }
    echo 'Verified all '.$language." page metadata.\n";
  }
  $schema=\Drupal::service('config.typed')->createFromNameAndData(SeoMetadata::CONFIG,$config->getRawData());
  $violations=$schema->validate();$errors=[];foreach($violations as $v){$errors[]=$v->getPropertyPath().': '.$v->getMessage();}
  $assert(count($violations)===0,'Schema: '.implode('; ',$errors));
  foreach($legacy as $name=>$data){$assert(\Drupal::config('nelkano_home.'.$name)->getRawData()===$data,'Visible content changed: '.$name);}
  // Empty optional fields inherit the current page, with no stored localhost URL.
  $config->set('es.pages.home',['title'=>'Fallback title','description'=>'Fallback description'])->save();
  $resolved=SeoMetadata::resolved('home','es');
  $assert($resolved['og_title']==='Fallback title' && $resolved['twitter_title']==='Fallback title','Social title fallback');
  $assert($resolved['og_description']==='Fallback description','Social description fallback');
  $assert(str_ends_with($resolved['canonical'],'/'),'Automatic canonical');
  foreach(['/user/login','/user/register'] as $path){$html=(string)$get($path)->getBody();$assert(!str_contains($html,'Nelkano QA'),'SEO leaked into auth');}
}finally{$config->setData($original)->save();\Drupal::service('account_switcher')->switchBack();}
echo "PASS: $checks SEO checks; all original metadata restored.\n";
