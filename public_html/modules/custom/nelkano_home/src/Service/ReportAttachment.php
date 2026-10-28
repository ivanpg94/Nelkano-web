<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Never serves arbitrary URIs, even if a node is edited incorrectly. */
final class ReportAttachment {

  public static function isNativeSave(\Drupal\node\NodeInterface $node): bool {
    return $node->get('field_report_system')->value === 'RPG_MAKER' && $node->get('field_report_state_format')->value === 'rpgmaker-native-save';
  }

  public static function validSaveName(string $name): bool {
    if ($name === '' || mb_strlen($name) > 240 || preg_match('/[\\\\\x00-\x1f\x7f"]/', $name) || !preg_match('/\.(rxdata|rvdata2?|sav|save|dat|bin)$/iD', $name)) {
      return FALSE;
    }
    foreach (explode('/', $name) as $part) {
      if (in_array($part, ['', '.', '..'], TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  public static function path(string $uri): string {
    if (!preg_match('@^private://nelkano-error-reports/[a-f0-9-]{36}/[^/\\\\]+$@D', $uri)) {
      throw new NotFoundHttpException('Attachment not found.');
    }
    $fs = \Drupal::service('file_system');
    $root = realpath($fs->realpath('private://nelkano-error-reports') ?: '');
    $path = realpath($fs->realpath($uri) ?: '');
    if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
      throw new NotFoundHttpException('Attachment not found.');
    }
    return $path;
  }

}
