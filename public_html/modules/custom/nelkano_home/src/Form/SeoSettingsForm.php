<?php
namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\SeoMetadata;

final class SeoSettingsForm extends ConfigFormBase {
  use AdminFormUiTrait;
  public function getFormId(): string {return 'nelkano_seo_settings';}
  protected function getEditableConfigNames(): array {return [SeoMetadata::CONFIG];}

  public function buildForm(array $form,FormStateInterface $form_state): array {
    $language=$this->activeAdminLanguage();
    $this->applyNelkanoAdminChrome($form,'seo','SEO','Metadatos de cada página. Los cambios se aplican al guardar; no cambian su contenido visible.',$language==='en'?'/en':'/',$language);
    $form['language']=['#type'=>'value','#value'=>$language];
    $form['pages']=['#type'=>'container','#tree'=>TRUE,'#attributes'=>['class'=>['nk-admin-language-fields']]];
    $labels=['title'=>'Título SEO (etiqueta title)','description'=>'Descripción (meta description)','keywords'=>'Palabras clave (meta keywords)','robots'=>'Indexación (meta robots)','canonical'=>'URL canónica','og_title'=>'Título para compartir (Open Graph)','og_description'=>'Descripción para compartir (Open Graph)','image'=>'URL de imagen para Open Graph y Twitter','image_alt'=>'Texto alternativo de la imagen','twitter_title'=>'Título para Twitter / X','twitter_description'=>'Descripción para Twitter / X','twitter_card'=>'Tarjeta de Twitter / X','og_type'=>'Tipo Open Graph','site_name'=>'Nombre del sitio (Open Graph)'];
    foreach (SeoMetadata::pages($language) as $key=>$page) {
      $values=SeoMetadata::editable($key,$language);
      $section=['#type'=>'details','#title'=>$page['label'].' · '.$page['path'],'#open'=>FALSE];
      $section['preview']=['#type'=>'link','#title'=>'Ver página','#url'=>Url::fromUserInput($page['path']),'#attributes'=>['target'=>'_blank','rel'=>'noopener']];
      foreach ($labels as $field=>$label) {
        $section[$field]=['#type'=>str_contains($field,'description')?'textarea':'textfield','#title'=>$label,'#default_value'=>$values[$field],'#maxlength'=>2048];
      }
      $section['title']['#required']=TRUE;
      $section['robots']['#type']='select';
      $section['robots']['#options']=array_combine(['index, follow, max-image-preview:large','index, follow','noindex, follow','noindex, nofollow'],['Indexar y seguir enlaces (vista previa grande)','Indexar y seguir enlaces','No indexar; seguir enlaces','No indexar ni seguir enlaces']);
      $section['canonical']['#type']='url';$section['canonical']['#description']='Vacío: URL automática de esta página, sin filtros ni parámetros.';
      $section['image']['#type']='url';$section['image']['#description']='URL absoluta HTTP o HTTPS. Vacío: imagen social de Nelkano.';
      $section['og_type']['#type']='select';$section['og_type']['#options']=['website'=>'Sitio web','article'=>'Artículo'];
      $section['twitter_card']['#type']='select';$section['twitter_card']['#options']=['summary_large_image'=>'Imagen grande','summary'=>'Resumen'];
      foreach(['og_title','og_description'] as $field){$section[$field]['#description']='Vacío: utiliza el título o la descripción SEO.';}
      foreach(['twitter_title','twitter_description'] as $field){$section[$field]['#description']='Vacío: utiliza el valor de Open Graph.';}
      $section['save']=['#type'=>'submit','#value'=>'Guardar SEO','#name'=>'save_seo_'.$key,'#seo_page'=>$key,'#limit_validation_errors'=>[['pages',$key],['language']],'#submit'=>['::submitForm'],'#attributes'=>['class'=>['r-save-section'],'formnovalidate'=>'formnovalidate']];
      $this->groupAdminFields($section,[
        'search'=>['title'=>'Buscadores','fields'=>['title','keywords','description','robots','canonical']],
        'social'=>['title'=>'Open Graph · redes sociales','fields'=>['site_name','og_type','og_title','image_alt','og_description','image']],
        'twitter'=>['title'=>'Twitter / X','fields'=>['twitter_title','twitter_card','twitter_description']],
      ],['pages',$key]);
      $form['pages'][$key]=$section;
    }
    $form=parent::buildForm($form,$form_state);
    $form['actions']['submit']['#value']='Guardar todo el SEO';
    return $form;
  }

  public function validateForm(array &$form,FormStateInterface $form_state): void {
    parent::validateForm($form,$form_state);
    $selected=$form_state->getTriggeringElement()['#seo_page']??NULL;
    foreach($form_state->getValue('pages',[]) as $key=>$page){
      if($selected!==NULL && $key!==$selected){continue;}
      foreach(['canonical','image'] as $field){
        $value=trim((string)($page[$field]??''));
        if($value!=='' && (!filter_var($value,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($value,PHP_URL_SCHEME)??''),['http','https'],TRUE))){$form_state->setErrorByName('pages]['.$key.']['.$field,'Introduce una URL absoluta HTTP o HTTPS.');}
      }
    }
  }

  public function submitForm(array &$form,FormStateInterface $form_state): void {
    $language=$form_state->getValue('language');
    if(!in_array($language,['es','en'],TRUE)){throw new \InvalidArgumentException('Invalid language.');}
    $config=$this->configFactory->getEditable(SeoMetadata::CONFIG);
    $selected=$form_state->getTriggeringElement()['#seo_page']??NULL;
    foreach(SeoMetadata::pages($language) as $key=>$page){
      if($selected!==NULL && $selected!==$key){continue;}
      $values=$form_state->getValue(['pages',$key]);if(!is_array($values)){continue;}
      $data=[];foreach(SeoMetadata::FIELDS as $field){$data[$field]=trim((string)($values[$field]??''));}
      $config->set($language.'.pages.'.$key,$data);
    }
    $config->save();parent::submitForm($form,$form_state);
  }
}
