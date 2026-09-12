<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;

/** Stable API codes backed by editable taxonomy labels, never environment IDs. */
final class WorkflowStatus {
  public const VOCABULARY = 'nelkano_workflow';
  public const FIELD = 'field_workflow_status';
  public const HU_CODES = ['new', 'in_progress', 'blocked', 'resolved'];

  public static function termId(string $code): int {
    if (!isset(ReportWorkflow::STATUSES[$code])) {
      throw new \InvalidArgumentException('Estado desconocido.');
    }
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => self::VOCABULARY, 'field_workflow_code' => $code,
    ]);
    if (count($terms) !== 1) {
      throw new \RuntimeException('La taxonomía de estados necesita la actualización 11021.');
    }
    return (int) reset($terms)->id();
  }

  public static function codeForId(int $id): string {
    $term = Term::load($id);
    if (!$term || $term->bundle() !== self::VOCABULARY) {
      throw new \InvalidArgumentException('Estado de taxonomía no válido.');
    }
    $code = (string) $term->get('field_workflow_code')->value;
    if (!isset(ReportWorkflow::STATUSES[$code])) {
      throw new \InvalidArgumentException('Código de estado no válido.');
    }
    return $code;
  }

  public static function get(NodeInterface $node): string {
    return self::codeForId((int) $node->get(self::FIELD)->target_id);
  }

  public static function set(NodeInterface $node, string $code): void {
    if ($node->bundle() === 'hu' && !in_array($code, self::HU_CODES, TRUE)) {
      throw new \InvalidArgumentException('Estado no válido para una HU.');
    }
    $node->set(self::FIELD, ['target_id' => self::termId($code)]);
  }

  public static function options(bool $hu = FALSE): array {
    $options = [];
    foreach ($hu ? self::HU_CODES : array_keys(ReportWorkflow::STATUSES) as $code) {
      $options[$code] = (string) Term::load(self::termId($code))->label();
    }
    return $options;
  }

  /** UI choices include ordinary terms; API choices above remain unchanged. */
  public static function administrativeOptions(): array {
    $options = self::options();
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('vid', self::VOCABULARY)
      ->sort('weight')->sort('name')->sort('tid')->execute();
    foreach ($storage->loadMultiple($ids) as $term) {
      $code = (string) $term->get('field_workflow_code')->value;
      if (!isset(ReportWorkflow::STATUSES[$code])) {
        $options['term:' . $term->id()] = (string) $term->label();
      }
    }
    return $options;
  }

  public static function administrativeTermId(string $status): int {
    if (isset(ReportWorkflow::STATUSES[$status])) {
      return self::termId($status);
    }
    if (!preg_match('/^term:([1-9][0-9]*)$/D', $status, $matches)) {
      throw new \InvalidArgumentException('Selecciona un estado válido.');
    }
    $term = Term::load((int) $matches[1]);
    if (!$term || $term->bundle() !== self::VOCABULARY) {
      throw new \InvalidArgumentException('El estado seleccionado ya no existe.');
    }
    return (int) $term->id();
  }

  public static function administrativeStatusForId(int $id): string {
    $term = Term::load($id);
    if (!$term || $term->bundle() !== self::VOCABULARY) {
      throw new \InvalidArgumentException('Estado de taxonomía no válido.');
    }
    $code = (string) $term->get('field_workflow_code')->value;
    return isset(ReportWorkflow::STATUSES[$code]) ? $code : 'term:' . $id;
  }

  public static function administrativeStatus(NodeInterface $node): string {
    return self::administrativeStatusForId((int) $node->get(self::FIELD)->target_id);
  }
}
