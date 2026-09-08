<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\nelkano_home\Service\ReportBulkUpdater;
use Drupal\nelkano_home\Service\ReportWorkflow;
use Drupal\node\NodeInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** CSRF-protected bulk controls below the current page of report results. */
final class ErrorReportBulkForm extends FormBase {

  // Protected so FormBase's service serialization can restore cached forms.
  public function __construct(protected ReportBulkUpdater $updater) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('nelkano_home.report_bulk_updater'));
  }

  public function getFormId(): string {
    return 'nelkano_error_report_bulk_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->requirePermission();
    $view = Views::getView('nelkano_error_reports');
    if (!$view || !$view->access('block_1') || !$view->execute('block_1')) {
      throw new NotFoundHttpException('La vista de reportes no está disponible.');
    }
    $options = [];
    $date_formatter = \Drupal::service('date.formatter');
    foreach ($view->result as $row) {
      $node = $row->_entity ?? NULL;
      if (!$node instanceof NodeInterface || $node->bundle() !== 'nelkano_error_report') {
        continue;
      }
      $plain = static fn (string $value): array => ['data' => ['#plain_text' => $value]];
      $status = (string) $node->get('field_report_status')->value;
      $options[(int) $node->id()] = [
        'id' => ['data' => Link::fromTextAndUrl('#' . $node->id(), Url::fromRoute('nelkano_home.admin_error_report_view', ['report' => $node->id()]))->toRenderable()],
        'status' => $plain(ReportWorkflow::STATUSES[$status] ?? $status),
        'system' => $plain((string) $node->get('field_report_system')->value),
        'game' => $plain((string) $node->get('field_report_game')->value),
        'title' => $plain((string) $node->label()),
        'user' => $plain((string) ($node->getOwner()?->getDisplayName() ?? '')),
        'created' => $plain($date_formatter->format($node->getCreatedTime(), 'short')),
        'observations' => $plain((string) $node->get('field_report_observations')->value),
      ];
    }
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['class'][] = 'nk-report-bulk-form';
    $form['table_wrapper'] = [
      '#type' => 'container', '#attributes' => ['class' => ['view-nelkano-error-reports']],
    ];
    $form['table_wrapper']['reports'] = [
      '#type' => 'tableselect', '#parents' => ['reports'],
      '#header' => ['id' => 'ID', 'status' => 'Estado', 'system' => 'Sistema', 'game' => 'Juego',
        'title' => 'Resumen', 'user' => 'Usuario', 'created' => 'Fecha', 'observations' => 'Observaciones'],
      '#options' => $options, '#multiple' => TRUE, '#js_select' => TRUE,
      '#empty' => $this->t('No hay reportes para mostrar.'),
    ];
    $form['pager'] = $view->pager->render($view->getExposedInput());
    $form['bulk'] = [
      '#type' => 'fieldset', '#title' => $this->t('Cambiar estado de los seleccionados'),
      '#access' => !empty($options), '#attributes' => ['class' => ['nk-report-bulk-controls']],
    ];
    $form['bulk']['help'] = ['#markup' => '<p>' . $this->t('Selecciona reportes de esta página (máximo 50). La casilla de la cabecera selecciona sólo los visibles; no se conservan selecciones al cambiar de página.') . '</p>'];
    $form['bulk']['target_status'] = [
      '#type' => 'select', '#title' => $this->t('Nuevo estado'), '#parents' => ['target_status'],
      '#options' => ReportWorkflow::STATUSES, '#empty_option' => $this->t('- Selecciona un estado -'),
      '#required' => TRUE,
    ];
    $form['bulk']['observations'] = [
      '#type' => 'textarea', '#title' => $this->t('Motivo del bloqueo'), '#parents' => ['observations'],
      '#rows' => 3, '#maxlength' => ReportWorkflow::MAX_OBSERVATIONS_LENGTH,
      '#description' => $this->t('Se guardará este motivo en Observaciones de todos los seleccionados. Para otros estados se conservan las observaciones existentes.'),
      '#states' => [
        'visible' => [':input[name="target_status"]' => ['value' => 'blocked']],
        'required' => [':input[name="target_status"]' => ['value' => 'blocked']],
      ],
    ];
    $form['bulk']['apply'] = ['#type' => 'submit', '#value' => $this->t('Aplicar a los seleccionados'), '#button_type' => 'primary'];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->requirePermission();
    $selected = array_filter((array) $form_state->getValue('reports', []));
    $allowed = $form['table_wrapper']['reports']['#options'];
    if (!$selected || count($selected) > ReportBulkUpdater::MAX_REPORTS || array_diff_key($selected, $allowed)) {
      $form_state->setErrorByName('reports', $this->t('Selecciona entre 1 y 50 reportes de esta página. Si la lista cambió, recarga y vuelve a seleccionarlos.'));
      return;
    }
    $body = ['status' => $form_state->getValue('target_status')];
    if ($body['status'] === 'blocked') {
      $body['observations'] = $form_state->getValue('observations', '');
    }
    try {
      [$status, $observations] = ReportWorkflow::validateUpdate($body);
      $form_state->set('bulk_update', [array_map('intval', array_keys($selected)), $status, $observations]);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('observations', $this->t('Selecciona un estado válido. Para Bloqueado, escribe un motivo de entre 1 y 4000 caracteres.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->requirePermission();
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
      '@selected' => count($ids), '@changed' => $changed, '@status' => ReportWorkflow::STATUSES[$status],
    ]));
    $form_state->setRedirect('nelkano_home.admin_error_reports', [], ['query' => $this->getRequest()->query->all()]);
  }

  private function requirePermission(): void {
    if (!$this->currentUser()->hasPermission('administer nelkano error reports') || !$this->currentUser()->hasPermission(ReportBulkUpdater::PERMISSION)) {
      throw new AccessDeniedHttpException();
    }
  }

}
