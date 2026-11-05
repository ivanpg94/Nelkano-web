<?php
declare(strict_types=1);
namespace Drupal\nelkano_ai\Form;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nelkano_ai\Service\AiAccess;
final class WorkerKeyForm extends FormBase {
  public function getFormId(): string {return 'nelkano_ai_worker_key';}
  public function buildForm(array $form,FormStateInterface $state): array {
    $form['intro']=['#markup'=>'<p>Esta clave conecta el PC con la web. No se comparte con los módulos ni los jugadores. Crear otra clave revoca la anterior.</p>'];
    if($token=$state->get('new_worker_token'))$form['token']=['#type'=>'textarea','#title'=>'Copia esta clave en la configuración privada de Nelkan-AI','#value'=>$token,'#attributes'=>['readonly'=>'readonly','autocomplete'=>'off']];
    $form['actions']['submit']=['#type'=>'submit','#value'=>'Crear o sustituir clave del PC'];
    $form['#cache']['max-age']=0;return $form;
  }
  public function submitForm(array &$form,FormStateInterface $state): void {$state->set('new_worker_token',AiAccess::issueWorker());$state->setRebuild(TRUE);}
}
