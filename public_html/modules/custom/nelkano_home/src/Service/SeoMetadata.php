<?php
namespace Drupal\nelkano_home\Service;

/** Shared editable metadata for the explicitly supported public pages. */
final class SeoMetadata {
  public const CONFIG = 'nelkano_home.seo';
  public const FIELDS = ['title','description','keywords','robots','canonical','og_title','og_description','image','image_alt','twitter_title','twitter_description','twitter_card','og_type','site_name'];

  public static function pages(string $language): array {
    $en = $language === 'en';
    $definitions = [
      'home'=>['Home','/','/en'],
      'systems'=>['Sistemas','/sistemas','/en/systems'],
      'guide'=>['Guía de uso','/guia','/en/guide'],
      'legal_notice'=>['Aviso legal','/aviso-legal','/en/legal-notice'],
      'privacy_cookies'=>['Privacidad y cookies','/privacidad-cookies','/en/privacy-cookies'],
      'releases'=>['Versiones','/versiones','/en/releases'],
      'security'=>['Seguridad y privacidad','/seguridad-privacidad','/en/security-privacy'],
      'contact'=>['Contacto','/contacto','/en/contact'],
    ];
    $pages=[];
    foreach ($definitions as $key=>[$label,$es,$eng]) {
      $pages[$key]=['label'=>$label,'path'=>$en?$eng:$es,'alternate'=>$en?$es:$eng,'default_path'=>$es];
    }
    $other=SystemPages::content($en?'es':'en')['pages'];
    foreach (SystemPages::content($language)['pages'] as $id=>$page) {
      $path=SystemPages::path($id,$language);
      $pages['system__'.$id]=['label'=>$page['card_title'],'path'=>$path,
        'alternate'=>!empty($other[$id]['enabled'])?SystemPages::path($id,$en?'es':'en'):'',
        'default_path'=>$en && empty($other[$id]['enabled'])?$path:SystemPages::path($id,'es'),'system'=>$id];
    }
    return $pages;
  }

  /** Existing editorial values remain the fallback until edited in SEO. */
  public static function editable(string $key, string $language): array {
    $pages=self::pages($language);
    if (!isset($pages[$key])) {throw new \InvalidArgumentException('Unknown SEO page.');}
    $en=$language==='en';
    $base=array_fill_keys(self::FIELDS,'');
    $base['robots']='index, follow, max-image-preview:large';
    $base['twitter_card']='summary_large_image';
    $base['og_type']='website';
    $base['site_name']='Nelkano';
    $base['image_alt']='Nelkano';
    if ($key==='home') {
      $data=\Drupal::config('nelkano_home.settings')->get($language)??[];
      $base['title']=$data['seo_title']?:($data['hero_title']??'Nelkano');
      $base['description']=$data['seo_description']?:($data['hero_description']??'');
      $base['keywords']=$data['seo_keywords']??'';
    }
    elseif (isset($pages[$key]['system'])) {
      $data=SystemPages::content($language)['pages'][$pages[$key]['system']];
      $base['title']=$data['seo_title']?:$data['title'].' — Nelkano';
      $base['description']=$data['seo_description']?:$data['tagline'];
    }
    elseif ($key==='systems') {
      $data=SystemPages::content($language)['labels'];
      $base['title']=$data['index_title'].' — Nelkano';$base['description']=$data['index_description'];
    }
    elseif ($key==='guide') {
      $data=\Drupal::config('nelkano_home.guide')->get($language)??[];
      $base['title']=($data['title']??'Nelkano').' — Nelkano';$base['description']=$data['intro']??'';
    }
    elseif ($key==='contact') {
      $base['title']=($en?'Contact':'Contacto').' - Nelkano';
      $base['description']=$en?'Contact Nelkano for support, suggestions or technical issues.':'Contacta con Nelkano para soporte, propuestas o incidencias tecnicas.';
    }
    else {
      $data=\Drupal::config('nelkano_home.docs')->get($language)??[];
      $base['title']=$data[$key.'_seo_title']??$pages[$key]['label'].' - Nelkano';
      $base['description']=$data[$key.'_seo_description']??'';
      if (in_array($key,['legal_notice','privacy_cookies'],TRUE)) {$base['robots']='index, follow';$base['og_type']='article';}
    }
    return array_replace($base,array_intersect_key(\Drupal::config(self::CONFIG)->get($language.'.pages.'.$key)??[],$base));
  }

  public static function resolved(string $key,string $language): array {
    $page=self::pages($language)[$key];$data=self::editable($key,$language);
    $base=\Drupal::request()->getSchemeAndHttpHost().\Drupal::request()->getBasePath();
    $data['current_url']=$base.$page['path'];
    $data['canonical']=$data['canonical']?:$data['current_url'];
    $data['alternate']=$page['alternate']!==''?$base.$page['alternate']:'';
    $data['alternate_lang']=$language==='en'?'es':'en';
    $data['default_url']=$base.$page['default_path'];
    $data['locale']=$language==='en'?'en_US':'es_ES';
    $data['alternate_locale']=$language==='en'?'es_ES':'en_US';
    $data['image']=$data['image']?:$base.'/'.\Drupal::service('extension.list.module')->getPath('nelkano_home').'/assets/logo-social-v2.png';
    foreach (['og_title'=>'title','og_description'=>'description','twitter_title'=>'og_title','twitter_description'=>'og_description'] as $field=>$fallback) {$data[$field]=$data[$field]?:$data[$fallback];}
    return $data;
  }

  public static function forRequest(string $language): ?array {
    $path=\Drupal::request()->getPathInfo();
    foreach(self::pages($language) as $key=>$page){if($page['path']===$path){return self::resolved($key,$language);}}
    return NULL;
  }
}
