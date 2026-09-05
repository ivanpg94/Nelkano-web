<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

#[FieldFormatter(
  id: 'nelkano_report_attachment',
  label: new TranslatableMarkup('Descarga privada de adjunto Nelkano'),
  field_types: ['string'],
)]
final class ReportAttachmentFormatter extends FormatterBase {

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $node = $items->getEntity();
    if ($node->getEntityTypeId() !== 'node' || $node->bundle() !== 'nelkano_error_report') {
      return [];
    }
    $screenshot = $items->getName() === 'field_report_screenshot_uri';
    $elements = [];
    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#type' => 'link',
        '#title' => $screenshot ? $this->t('Descargar captura') : $this->t('Descargar slot'),
        '#url' => Url::fromRoute($screenshot ? 'nelkano_home.admin_error_report_screenshot' : 'nelkano_home.admin_error_report_state', ['report' => $node->id()]),
      ];
    }
    return $elements;
  }

}
