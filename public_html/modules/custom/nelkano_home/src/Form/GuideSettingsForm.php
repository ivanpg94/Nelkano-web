<?php
namespace Drupal\nelkano_home\Form;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class GuideSettingsForm extends ConfigFormBase {
  use AdminFormUiTrait;
  use ConfigRowsFormTrait;
  private const TEXTS=['title'=>'Título','eyebrow'=>'Etiqueta','intro'=>'Introducción','toc_label'=>'Índice','empty_label'=>'Texto sin secciones','zoom_label'=>'Botón ampliar imagen','close_label'=>'Botón cerrar imagen'];
  private const ROWS=['layout'=>'cards','title'=>'Secciones de la guía','columns'=>[
    'visible'=>['title'=>'Visible','type'=>'checkbox'],
    'title'=>['title'=>'Título'],
    'description'=>['title'=>'Introducción','type'=>'textarea'],
    'bullets'=>['title'=>'Puntos (uno por línea)','type'=>'textarea'],
    'image_uri'=>['title'=>'Captura opcional','type'=>'managed_file','upload_location'=>'public://nelkano-images','upload_validators'=>['FileExtension'=>['extensions'=>'png jpg jpeg webp gif'],'FileIsImage'=>[]]],
  ]];
  public function getFormId(): string {return 'nelkano_guide_settings';}
  protected function getEditableConfigNames(): array {return ['nelkano_home.guide'];}
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $lang=$this->activeAdminLanguage();
    $this->applyNelkanoAdminChrome($form,'guide','Guía de uso','Edita las secciones, su orden y las capturas.',$lang==='es'?'/guia':'/en/guide',$lang);
    $form['active_language']=['#type'=>'value','#value'=>$lang];
    $data=$this->config('nelkano_home.guide')->get($lang)??[];
    $form[$lang]=['#type'=>'container','#tree'=>TRUE,'#attributes'=>['class'=>['nk-admin-language-fields']]];
    $form[$lang]['heading']=['#type'=>'details','#title'=>'Presentación','#open'=>FALSE];
    foreach (self::TEXTS as $key=>$title) {$form[$lang]['heading'][$key]=['#type'=>$key==='intro'?'textarea':'textfield','#title'=>$title,'#default_value'=>$data[$key]??''];}
    $this->groupAdminFields($form[$lang]['heading'],[
      'intro'=>['title'=>'Presentación de la guía','fields'=>['title','eyebrow','intro']],
      'labels'=>['title'=>'Índice y botones','fields'=>['toc_label','empty_label','zoom_label','close_label']],
    ],[$lang,'heading']);
    $form[$lang]['sections']=$this->buildConfigRowsElement($data['sections']??[],self::ROWS,[$lang,'sections'],$lang,$form_state);
    $form=parent::buildForm($form,$form_state);
    $form['actions']['submit']['#value']=$lang==='es'?'Guardar cambios':'Save changes';return $form;
  }
  public function submitForm(array &$form,FormStateInterface $form_state): void {
    $lang=$form_state->getValue('active_language');$config=$this->configFactory->getEditable('nelkano_home.guide');$data=[];
    foreach(self::TEXTS as $key=>$title){$data[$key]=trim((string)$form_state->getValue([$lang,'heading',$key]));}
    $data['sections']=$this->normalizeConfigRowsValue($form_state->getValue([$lang,'sections']),self::ROWS);
    $config->set($lang,$data)->save();parent::submitForm($form,$form_state);
  }
}
