<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Controller;

use Drupal\nelkano_home\Service\WorkflowStatus;

use Drupal\Core\Controller\ControllerBase;
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
      'Consulta los reportes enviados desde la aplicación. Puedes seleccionar varios y cambiar su estado desde los controles bajo la tabla si tienes permiso de modificación.',
      $view->render(),
      '<a class="nk-admin-public-link" href="' . Url::fromUserInput('/admin/nelkano/error-reports/export.csv', ['query' => ['_format' => 'csv']])->toString() . '">Exportar CSV</a>',
    );
  }

  public function view(int $report): array {
    $node = $this->loadReport($report);
    $reporter = User::load((int) $node->getOwnerId());
    $value = static fn(string $name): string => (string) $node->get($name)->value;
    $field = static fn(string $label, string $value, int $span = 1, bool $code = FALSE): array => compact('label', 'value', 'span', 'code');
    $category = $value('field_report_category');
    $category = $node->getFieldDefinition('field_report_category')->getSetting('allowed_values')[$category] ?? $category;
    $status = WorkflowStatus::get($node);
    $settings = $value('field_report_settings');
    $decoded = json_decode($settings, TRUE);
    if (is_array($decoded)) {
      $settings = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $bytes = $value('field_report_state_size');
    $size = $bytes === '' ? '' : number_format((int) $bytes, 0, ',', '.') . ' bytes';
    if ((int) $bytes >= 1048576) {
      $size = number_format((int) $bytes / 1048576, 2, ',', '.') . ' MiB · ' . $size;
    }
    $data = [
      'id' => $node->id(), 'title' => $node->label(),
      'status' => $status, 'status_label' => WorkflowStatus::options()[$status],
      'created' => \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'custom', 'd/m/Y · H:i'),
      'reporter' => $reporter ? (string) $reporter->getEmail() : 'uid:' . $node->getOwnerId(),
      'back_url' => Url::fromRoute('nelkano_home.admin_error_reports')->toString(),
      'overview' => [
        $field('Sistema', $value('field_report_system')),
        $field('Categoría', $category),
        $field('Slot', $value('field_report_slot')),
        $field('Juego', $value('field_report_game'), 3),
      ],
      'problem' => [
        $field('Pasos para reproducir', $value('body'), 2),
        $field('Resultado esperado', $value('field_report_expected')),
        $field('Resultado actual', $value('field_report_actual')),
      ],
      'observations' => $value('field_report_observations'),
      'environment' => [
        $field('Versión de la aplicación', $value('field_report_app_version')),
        $field('Build', $value('field_report_app_build')),
        $field('Versión del core', $value('field_report_core_version')),
        $field('Dispositivo', $value('field_report_device_model')),
        $field('Android', $value('field_report_android')),
        $field('ABIs', $value('field_report_abis')),
        $field('GPU', $value('field_report_gpu'), 2),
        $field('Backend', $value('field_report_backend')),
      ],
      'attachments' => [],
      'file_metadata' => [
        $field('Formato del estado', $value('field_report_state_format')),
        $field('Tamaño del estado', $size, 2),
        $field('SHA-256 del estado', $value('field_report_state_sha256'), 3, TRUE),
      ],
      'identifiers' => [
        $field('Identidad de ROM', $value('field_report_rom_hash'), 1, TRUE),
        $field('ID del dispositivo', $value('field_report_device_id'), 1, TRUE),
      ],
      'settings' => $settings, 'logs' => $value('field_report_logs'),
    ];
    if ($value('field_report_state_uri') !== '') {
      $data['attachments'][] = ['label' => 'Descargar save-state', 'name' => $value('field_report_state_name'), 'url' => Url::fromRoute('nelkano_home.admin_error_report_state', ['report' => $node->id()])->toString()];
    }
    if ($value('field_report_screenshot_uri') !== '') {
      $data['attachments'][] = ['label' => 'Descargar captura', 'name' => 'Imagen de la sesión', 'url' => Url::fromRoute('nelkano_home.admin_error_report_screenshot', ['report' => $node->id()])->toString()];
    }
    $page = $this->adminPage($node->label(), '', ['#theme' => 'nelkano_report_detail', '#report' => $data]);
    unset($page['header']);
    $page['#attached']['library'][] = 'nelkano_home/detail_ui';
    $page['#cache'] = ['max-age' => 0];
    return $page;
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
