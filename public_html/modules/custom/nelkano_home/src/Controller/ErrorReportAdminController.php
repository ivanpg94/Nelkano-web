<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\nelkano_home\Form\AdminFormUiTrait;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ErrorReportAdminController extends ControllerBase {

  use AdminFormUiTrait;

  public function listing(): array {
    $view = Views::getView('nelkano_error_reports');
    if (!$view) {
      throw new NotFoundHttpException('La vista de reportes no esta instalada.');
    }
    $view->setDisplay('block_1');
    return $this->adminPage(
      'Reportes de errores',
      'Consulta los reportes enviados desde la aplicacion. Cada fila es contenido privado de Drupal y la tabla procede de una View.',
      $view->render(),
      '<a class="nk-admin-public-link" href="' . Url::fromUserInput('/admin/nelkano/error-reports/export.csv', ['query' => ['_format' => 'csv']])->toString() . '">Exportar CSV</a>',
    );
  }

  public function view(int $report): array {
    $node = $this->loadReport($report);
    $reporter = User::load((int) $node->getOwnerId());
    $rows = [];
    foreach ([
      'field_report_status' => 'Estado', 'field_report_category' => 'Categoria',
      'title' => 'Resumen', 'body' => 'Pasos para reproducir',
      'field_report_expected' => 'Resultado esperado', 'field_report_actual' => 'Resultado actual',
      'field_report_system' => 'Sistema', 'field_report_game' => 'Juego',
      'field_report_rom_hash' => 'Identidad de ROM', 'field_report_slot' => 'Slot',
      'field_report_app_version' => 'Version de la aplicacion', 'field_report_app_build' => 'Build',
      'field_report_core_version' => 'Version del core', 'field_report_state_format' => 'Formato del estado',
      'field_report_device_id' => 'ID del dispositivo', 'field_report_device_model' => 'Modelo del dispositivo',
      'field_report_android' => 'Version de Android', 'field_report_abis' => 'ABIs',
      'field_report_gpu' => 'GPU', 'field_report_backend' => 'Backend',
      'field_report_settings' => 'Configuracion del emulador', 'field_report_logs' => 'Registros de diagnostico',
      'field_report_state_sha256' => 'SHA-256 del estado', 'field_report_state_size' => 'Tamano del estado (bytes)',
    ] as $field => $label) {
      $value = $field === 'title' ? $node->label() : (string) $node->get($field)->value;
      if ($field === 'field_report_status') {
        $value = $node->getFieldDefinition($field)->getSetting('allowed_values')[$value] ?? $value;
      }
      $rows[] = [$label, ['data' => ['#plain_text' => $value]]];
    }
    $rows[] = ['Usuario', ['data' => ['#plain_text' => $reporter ? (string) $reporter->getEmail() : 'uid:' . $node->getOwnerId()]]];
    $rows[] = ['Save-state', Link::fromTextAndUrl((string) $node->get('field_report_state_name')->value, Url::fromRoute('nelkano_home.admin_error_report_state', ['report' => $node->id()]))];
    if ((string) $node->get('field_report_screenshot_uri')->value !== '') {
      $rows[] = ['Captura', Link::fromTextAndUrl('Descargar captura', Url::fromRoute('nelkano_home.admin_error_report_screenshot', ['report' => $node->id()]))];
    }
    return $this->adminPage(
      '#' . $node->id() . ' · ' . $node->label(),
      'Detalle completo del contenido recibido desde el emulador.',
      [
        'back' => Link::fromTextAndUrl('Volver a los reportes', Url::fromRoute('nelkano_home.admin_error_reports'))->toRenderable(),
        'details' => ['#type' => 'table', '#rows' => $rows, '#attributes' => ['class' => ['nk-report-details']]],
      ],
    );
  }

  public function downloadState(int $report): BinaryFileResponse {
    $node = $this->loadReport($report);
    return $this->privateDownload((string) $node->get('field_report_state_uri')->value, (string) $node->get('field_report_state_name')->value);
  }

  public function downloadScreenshot(int $report): BinaryFileResponse {
    $node = $this->loadReport($report);
    return $this->privateDownload((string) $node->get('field_report_screenshot_uri')->value, 'report-' . $node->id() . '.png');
  }

  private function adminPage(string $title, string $description, array $content, string $actions = ''): array {
    $module_path = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['nk-admin-config-form']],
      '#attached' => ['library' => ['nelkano_home/admin_forms']],
      '#prefix' => $this->nelkanoAdminHeader($module_path) . '<div class="nk-admin-app"><div class="nk-admin-body">' . $this->nelkanoAdminSidebar('error_reports') . '<div class="nk-admin-workspace"><section class="nk-admin-panel">',
      '#suffix' => '</section></div></div></div>',
      'header' => [
        '#weight' => -1000,
        '#markup' => '<div class="nk-admin-panel-head"><div><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p>' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div><div class="nk-admin-head-actions">' . $actions . '</div></div>',
      ],
      'content' => $content,
    ];
  }

  private function loadReport(int $id): NodeInterface {
    $node = $this->entityTypeManager()->getStorage('node')->load($id);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'nelkano_error_report') {
      throw new NotFoundHttpException();
    }
    return $node;
  }

  private function privateDownload(string $uri, string $filename): BinaryFileResponse {
    $path = \Drupal\nelkano_home\Service\ReportAttachment::path($uri);
    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, basename($path));
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
