<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nelkano_home\Service\ReportDeletion;

/** POST-only cleanup actions for all finished/discarded reports, across pages. */
final class ReportCleanupForm extends FormBase {
  public function getFormId(): string { return 'nelkano_report_cleanup_form'; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    ReportDeletion::requirePermission($this->currentUser());
    $form['#attributes']['class'][] = 'nk-report-cleanup-form';
    foreach (['resolved' => 'Eliminar terminado', 'rejected' => 'Eliminar descartado'] as $status => $label) {
      $form[$status] = [
        '#type' => 'submit', '#value' => $this->t($label), '#name' => 'delete_' . $status,
        '#report_status' => $status, '#attributes' => ['class' => ['nk-report-delete']],
      ];
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    ReportDeletion::requirePermission($this->currentUser());
    $status = $form_state->getTriggeringElement()['#report_status'] ?? '';
    $ids = ReportDeletion::idsForStatus($status);
    if ($ids) {
      ReportDeletion::start($ids, $status);
    }
    else {
      $this->messenger()->addStatus($this->t('No hay reportes en ese estado.'));
    }
    $form_state->setRedirect('nelkano_home.admin_error_reports');
  }
}
