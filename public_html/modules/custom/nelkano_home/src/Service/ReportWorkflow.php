<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

/** Shared workflow contract for the PC API and the administrative form. */
final class ReportWorkflow {

  public const STATUSES = [
    'new' => 'Nuevo',
    'in_progress' => 'En proceso',
    'resolved' => 'Terminado',
    'rejected' => 'Descartado',
    'blocked' => 'Bloqueado',
  ];

  public const MAX_OBSERVATIONS_LENGTH = 4000;

  /** Null observations means omitted, not a request to erase stored text. */
  public static function validateUpdate(array $body, array $allowed_statuses = self::STATUSES): array {
    $status = $body['status'] ?? NULL;
    if (!is_string($status) || !isset($allowed_statuses[$status]) || array_diff(array_keys($body), ['status', 'observations'])) {
      throw new \InvalidArgumentException('Expected status: new, in_progress, resolved, rejected or blocked; only observations may also be supplied.');
    }
    $observations = NULL;
    if (array_key_exists('observations', $body)) {
      if (!is_string($body['observations']) || mb_strlen($body['observations'], 'UTF-8') > self::MAX_OBSERVATIONS_LENGTH) {
        throw new \InvalidArgumentException('Observations must be plain text of at most 4000 characters.');
      }
      $observations = $body['observations'];
    }
    if ($status === 'blocked' && ($observations === NULL || !preg_match('/[^\s\p{Z}]/u', $observations))) {
      throw new \InvalidArgumentException('Blocked reports require a reason in observations.');
    }
    return [$status, $observations];
  }

}
