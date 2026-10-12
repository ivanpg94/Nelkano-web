<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Strict public API parameters; SDK versions are exact, never inferred ranges. */
final class ModuleApiQuery {
  public static function parse(Request $request, bool $paged = FALSE, bool $search = FALSE): array {
    $input = $request->query->all();
    $allowed = array_merge(['platform', 'sdk'], $paged ? ['offset', 'limit'] : [], $search ? ['q', 'system'] : []);
    foreach ($input as $key => $value) {
      if (!in_array($key, $allowed, TRUE) || !is_string($value)) { throw new BadRequestHttpException('invalid_parameters'); }
    }
    $platform = $input['platform'] ?? '';
    $text = trim($input['q'] ?? '');
    if (!in_array($platform, ['', 'android', 'jvm-desktop'], TRUE) || mb_strlen($text) > 200) {
      throw new BadRequestHttpException('invalid_parameters');
    }
    $system = $input['system'] ?? '';
    if ($system !== '' && !isset(ModuleSystems::options()[$system])) { throw new BadRequestHttpException('invalid_parameters'); }
    $sdks = ($input['sdk'] ?? '') === '' ? [] : explode(',', $input['sdk']);
    if (count($sdks) > 8) { throw new BadRequestHttpException('invalid_parameters'); }
    foreach ($sdks as $sdk) {
      if (!preg_match('/^[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}$/D', $sdk)) { throw new BadRequestHttpException('invalid_parameters'); }
    }
    $sdks = array_values(array_unique($sdks)); sort($sdks, SORT_STRING);
    return ['q' => $text, 'system' => $system, 'platform' => $platform, 'sdks' => $sdks,
      'offset' => self::integer($input, 'offset', 0, 0, 1000000),
      'limit' => self::integer($input, 'limit', 12, 1, 20)];
  }

  private static function integer(array $input, string $key, int $default, int $min, int $max): int {
    if (!isset($input[$key])) { return $default; }
    if (!preg_match('/^(0|[1-9][0-9]{0,6})$/D', $input[$key]) || (int) $input[$key] < $min || (int) $input[$key] > $max) {
      throw new BadRequestHttpException('invalid_parameters');
    }
    return (int) $input[$key];
  }
}