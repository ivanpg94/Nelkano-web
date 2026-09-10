<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\nelkano_home\Controller\HuAdminController;
use Drupal\nelkano_home\Service\HuWorkflow;
use Drupal\nelkano_home\Service\WorkflowStatus;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class HuForm extends FormBase {
  use AdminFormUiTrait;

  public function getFormId(): string {
    return 'nelkano_hu_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $hu = NULL): array {
    $sprint_filter = HuAdminController::sprintFilter($this->getRequest());
    $node = $hu !== NULL ? Node::load($hu) : NULL;
    if ($hu !== NULL && (!$node || $node->bundle() !== 'hu')) {
      throw new NotFoundHttpException();
    }
    // Keep the original revision server-side across validation/rebuilds.
    if (!$form_state->has('hu_id')) {
      $form_state->set('hu_id', $hu ?? 0);
      $form_state->set('hu_revision', $node ? (int) $node->getRevisionId() : NULL);
      $form_state->set('sprint_filter', $sprint_filter);
    }
    $module_path = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    $form['#attached']['library'] = ['nelkano_home/admin_forms', 'nelkano_home/detail_ui'];
    $form['#attributes']['class'][] = 'nk-admin-config-form';
    $form['#prefix'] = $this->nelkanoAdminHeader($module_path) . '<div class="nk-admin-app"><div class="nk-admin-body">' . $this->nelkanoAdminSidebar('backlog') . '<div class="nk-admin-workspace"><section class="nk-admin-panel nk-hu-form">';
    $form['#suffix'] = '</section></div></div></div>';
    $back_url = Url::fromRoute('nelkano_home.admin_backlog', [], ['query' => $sprint_filter === '' ? [] : ['sprint' => $sprint_filter]])->toString();
    $form['heading'] = ['#markup' => '<header class="nk-record-head"><a class="nk-record-back" href="' . htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') . '">← Volver al backlog</a><div class="nk-record-heading"><div><span class="nk-record-eyebrow">Historia de usuario</span><h1>' . ($node ? 'HU ' . HuWorkflow::number($node) : 'Nueva HU') . '</h1><p>' . ($node ? 'Define el trabajo y organiza su seguimiento.' : 'El número se asignará automáticamente al guardar.') . '</p></div></div></header>'];
    $form['title'] = ['#type' => 'textfield', '#title' => $this->t('Título'), '#required' => TRUE, '#maxlength' => 255, '#default_value' => $node?->label() ?? ''];
    $form['description'] = ['#type' => 'textarea', '#title' => $this->t('Descripción'), '#required' => TRUE, '#rows' => 9, '#maxlength' => 20000, '#default_value' => $node?->get('field_hu_description')->value ?? ''];
    $form['status'] = ['#type' => 'select', '#title' => $this->t('Estado'), '#required' => TRUE, '#options' => WorkflowStatus::options(TRUE), '#default_value' => $node ? WorkflowStatus::get($node) : 'new'];
    $form['sprint'] = ['#type' => 'number', '#title' => $this->t('Sprint'), '#step' => 1, '#min' => -2147483648, '#max' => 2147483647, '#description' => $this->t('Déjalo vacío si la HU aún no tiene sprint.'), '#default_value' => $node ? $node->get('field_hu_sprint')->value : ($sprint_filter !== '' && $sprint_filter !== 'none' ? (int) $sprint_filter : NULL)];
    // Keep Form API field names and values flat while grouping their layout.
    $form['title']['#prefix'] = '<div class="nk-hu-editor"><section class="nk-hu-content" aria-labelledby="nk-hu-content-title"><h2 id="nk-hu-content-title">Contenido</h2>';
    $form['description']['#suffix'] = '</section>';
    $form['status']['#prefix'] = '<aside class="nk-hu-planning" aria-labelledby="nk-hu-plan-title"><h2 id="nk-hu-plan-title">Planificación</h2><div class="nk-hu-planning-fields">';
    $form['sprint']['#suffix'] = '</div></aside></div>';
    $form['actions'] = ['#type' => 'actions', '#attributes' => ['class' => ['nk-hu-actions']]];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Guardar HU'), '#attributes' => ['class' => ['nk-record-primary']]];
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => $this->t('Cancelar'), '#attributes' => ['class' => ['nk-record-secondary']], '#url' => Url::fromRoute('nelkano_home.admin_backlog', [], ['query' => $sprint_filter === '' ? [] : ['sprint' => $sprint_filter]])];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $sprint = $form_state->getValue('sprint');
    if ($sprint !== '' && $sprint !== NULL && filter_var($sprint, FILTER_VALIDATE_INT, ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]) === FALSE) {
      $form_state->setErrorByName('sprint', $this->t('Sprint debe ser un número entero.'));
    }
    foreach (['title' => 255, 'description' => 20000] as $field => $max) {
      $value = (string) $form_state->getValue($field);
      if (trim($value) === '' || mb_strlen($value) > $max) {
        $form_state->setErrorByName($field, $this->t('Completa este campo (máximo @max caracteres).', ['@max' => $max]));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $sprint = $form_state->getValue('sprint');
      $node = HuWorkflow::save($form_state->get('hu_id') ?: NULL, $form_state->get('hu_revision'), $form_state->getValue('status'), $form_state->getValue('title'), $form_state->getValue('description'), ['sprint' => $sprint === '' || $sprint === NULL ? NULL : (int) $sprint]);
      $this->messenger()->addStatus($this->t('HU @number guardada.', ['@number' => HuWorkflow::number($node)]));
      $filter = $form_state->get('sprint_filter');
      $form_state->setRedirect('nelkano_home.admin_backlog', [], ['query' => $filter === '' ? [] : ['sprint' => $filter]]);
    }
    catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
    }
  }
}
