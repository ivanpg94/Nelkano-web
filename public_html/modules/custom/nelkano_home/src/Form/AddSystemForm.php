<?php
namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\SystemPages;

final class AddSystemForm extends FormBase {
  use AdminFormUiTrait;
  public function getFormId(): string {return 'nelkano_add_system';}
  public function buildForm(array $form,FormStateInterface $form_state): array {
    $language=$this->activeAdminLanguage();
    $this->applyNelkanoAdminChrome($form,'systems','Añadir sistema','Se creará oculto, con fichas en español e inglés. Completa su contenido y hazlo visible cuando esté listo.','/sistemas',$language);
    $form['fields']=['#type'=>'container','#attributes'=>['class'=>['r-field-grid','r-system-create']]];
    $form['fields']['name']=['#type'=>'textfield','#title'=>'Nombre del sistema','#required'=>TRUE,'#maxlength'=>255];
    $form['fields']['slug']=['#type'=>'textfield','#title'=>'Identificador de la URL','#maxlength'=>64,'#description'=>'Ejemplo: sega-saturn → /sistemas/sega-saturn. Vacío: se genera a partir del nombre.'];
    $form['actions']=['#type'=>'actions'];
    $form['actions']['submit']=['#type'=>'submit','#value'=>'Crear sistema'];
    $form['actions']['cancel']=['#type'=>'link','#title'=>'Volver a Sistemas','#url'=>Url::fromRoute('nelkano_home.admin_systems')];
    return $form;
  }
  public function validateForm(array &$form,FormStateInterface $form_state): void {
    $name=trim((string)$form_state->getValue('name'));
    $id=trim((string)$form_state->getValue('slug'));
    if ($id==='') {
      $id=strtolower(\Drupal::transliteration()->transliterate($name,'es',''));
      $id=trim(preg_replace('/[^a-z0-9]+/','-',$id),'-');
    }
    $form_state->setValue('name',$name);$form_state->setValue('slug',$id);
    if ($name==='') {$form_state->setErrorByName('name','Introduce el nombre del sistema.');}
    if (strlen($id)>64 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$id)) {$form_state->setErrorByName('slug','Usa letras minúsculas sin tildes, números y guiones; máximo 64 caracteres.');}
    elseif (SystemPages::exists($id)) {$form_state->setErrorByName('slug','Ya existe un sistema con esa URL. Elige otro identificador.');}
  }
  public function submitForm(array &$form,FormStateInterface $form_state): void {
    $id=$form_state->getValue('slug');
    try {SystemPages::add($id,$form_state->getValue('name'));}
    catch (\InvalidArgumentException|\RuntimeException $e) {$this->messenger()->addError($e->getMessage());$form_state->setRebuild();return;}
    $this->messenger()->addStatus('Sistema creado y oculto. Ya puedes editar sus textos, imagen y SEO.');
    $form_state->setRedirect('nelkano_home.admin_systems',[],['query'=>['system'=>$id,'admin_lang'=>$this->activeAdminLanguage()]]);
  }
}
