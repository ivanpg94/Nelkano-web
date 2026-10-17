<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Service;

use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** Metadata and hosted bytes share the same bounded per-IP API budget. */
final class ModuleApiRateLimit {
  private const EVENT = 'nelkano_modules.public_api';
  private const LIMIT = 240;
  private const WINDOW = 60;

  public static function allow(Request $request, FloodInterface $flood): void {
    $ip = $request->getClientIp() ?? 'unknown';
    if (!$flood->isAllowed(self::EVENT, self::LIMIT, self::WINDOW, $ip)) {
      throw new TooManyRequestsHttpException(self::WINDOW);
    }
    $flood->register(self::EVENT, self::WINDOW, $ip);
  }
}
