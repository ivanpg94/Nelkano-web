<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\nelkano_home\Service\WorkflowStatus;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nelkano_home\Service\ReportBulkUpdater;
use Drupal\nelkano_home\Service\ReportDeletion;
use Drupal\nelkano_home\Service\ReportWorkflow;
use Drupal\node\NodeInterface;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Bulk controls embedded by the Views field; never renders a replacement table. */
final class ErrorReportBulkForm extends FormBase {

  // Protected so FormBase's service serialization can restore cached forms.
  public function __construct(protected ReportBulkUpdater $updater) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('nelkano_home.report_bulk_updater'));
  }

  public function getFormId(): string {
    return 'nelkano_error_report_bulk_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?ViewExecutable $view = NULL): array {
    $this->requirePermission();
    if (!$view) {
      throw new NotFoundHttpException('La vista de reportes no está disponible.');
    }
    $form['reports'] = ['#tree' => TRUE];
    $count = 0;
    foreach ($view->result as $index => $row) {
      $node = $row->_entity ?? NULL;
      if (!$node instanceof NodeInterface || $node->bundle() !== 'nelkano_error_report') {
        continue;
      }
      $form['reports'][$index] = [
        '#type' => 'checkbox', '#return_value' => (int) $node->id(),
        '#title' => $this->t('Seleccionar reporte @id', ['@id' => $node->id()]),
        '#title_display' => 'invisible',
      ];
      $count++;
    }
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['class'][] = 'nk-report-bulk-form';
    unset($form['actions']);
    $form['bulk'] = [
      '#type' => 'fieldset', '#title' => $this->t('Acciones'),
      '#weight' => 100, '#access' => $count > 0, '#attributes' => ['class' => ['nk-report-bulk-controls']],
    ];
    $form['bulk']['target_status'] = [
      '#type' => 'select', '#title' => $this->t('Nuevo estado'), '#parents' => ['target_status'],
      '#options' => WorkflowStatus::administrativeOptions(), '#empty_option' => $this->t('- Selecciona un estado -'),
      '#required' => FALSE,
    ];
    $form['bulk']['observations'] = [
      '#type' => 'textarea', '#title' => $this->t('Motivo del bloqueo'), '#parents' => ['observations'],
      '#rows' => 3, '#maxlength' => ReportWorkflow::MAX_OBSERVATIONS_LENGTH,
      '#description' => $this->t('Se guardará este motivo en Observaciones de todos los seleccionados. Para otros estados se conservan las observaciones existentes.'),
      '#states' => [
        'visible' => [':input[name="target_status"]' => ['value' => 'blocked']],
      ],
    ];
    $form['bulk']['apply'] = ['#type' => 'submit', '#value' => $this->t('Aplicar a los seleccionados'), '#button_type' => 'primary'];
    $form['bulk']['delete'] = [
      '#type' => 'submit', '#value' => $this->t('Eliminar seleccionados'),
      '#name' => 'delete_selected', '#report_action' => 'delete',
      '#access' => $this->currentUser()->hasPermission(ReportDeletion::PERMISSION),
      '#attributes' => ['class' => ['nk-report-delete'], 'formnovalidate' => 'formnovalidate'],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->requirePermission();
    // Checkbox processing substitutes #return_value; inspect submitted IDs too
    // so a changed page cannot silently select a different report at that index.
    $input = $form_state->getUserInput();
    $selected = array_filter((array) ($input['reports'] ?? $form_state->getValue('reports', [])));
    $valid = !empty($selected) && count($selected) <= ReportBulkUpdater::MAX_REPORTS;
    foreach ($selected as $index => $id) {
      if (!isset($form['reports'][$index]['#return_value']) || (string) $id !== (string) $form['reports'][$index]['#return_value']) {
        $valid = FALSE;
      }
    }
    if (!$valid) {
      $form_state->setErrorByName('reports', $this->t('Selecciona entre 1 y 50 reportes de esta página. Si la lista cambió, recarga y vuelve a seleccionarlos.'));
      return;
    }
    if (($form_state->getTriggeringElement()['#report_action'] ?? '') === 'delete') {
      ReportDeletion::requirePermission($this->currentUser());
      $form_state->set('delete_reports', array_values(array_unique(array_map('intval', $selected))));
      return;
    }
    $body = ['status' => $form_state->getValue('target_status')];
    if ($body['status'] === 'blocked') {
      $body['observations'] = $form_state->getValue('observations', '');
    }
    try {
      [$status, $observations] = ReportWorkflow::validateUpdate($body, WorkflowStatus::administrativeOptions());
      $form_state->set('bulk_update', [array_values(array_unique(array_map('intval', $selected))), $status, $observations]);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('observations', $this->t('Selecciona un estado válido. Para Bloqueado, escribe un motivo de entre 1 y 4000 caracteres.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->requirePermission();
    if (($form_state->getTriggeringElement()['#report_action'] ?? '') === 'delete') {
      ReportDeletion::start($form_state->get('delete_reports') ?? []);
      $form_state->setRedirect('nelkano_home.admin_error_reports', [], ['query' => $this->getRequest()->query->all()]);
      return;
    }

    [$ids, $status, $observations] = $form_state->get('bulk_update');
    try {
      $changed = $this->updater->update($ids, $status, $observations, $this->currentUser());
    }
    catch (\Throwable $e) {
      $this->getLogger('nelkano_home')->error('Error al actualizar reportes en masa: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('No se ha actualizado ningún reporte. La selección pudo cambiar o un reporte está ocupado; recarga y vuelve a intentarlo.'));
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($this->t('Seleccionados: @selected. Actualizados: @changed. Estado: @status.', [
      '@selected' => count($ids), '@changed' => $changed, '@status' => WorkflowStatus::administrativeOptions()[$status],
    ]));
    $form_state->setRedirect('nelkano_home.admin_error_reports', [], ['query' => $this->getRequest()->query->all()]);
  }

  private function requirePermission(): void {
    if (!$this->currentUser()->hasPermission('administer nelkano error reports') || !$this->currentUser()->hasPermission(ReportBulkUpdater::PERMISSION)) {
      throw new AccessDeniedHttpException();
    }
  }

}
