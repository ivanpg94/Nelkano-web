<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/** Keep all API failures JSON, including routing errors and unsupported methods. */
final class ModuleApiExceptionSubscriber implements EventSubscriberInterface {
  public static function getSubscribedEvents(): array { return [KernelEvents::EXCEPTION => ['onException', 0]]; }
  public function onException(ExceptionEvent $event): void {
    if (!preg_match('#^/api/nelkano/v1/modules(?:/|$)#', $event->getRequest()->getPathInfo())) { return; }
    $exception = $event->getThrowable();
    $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
    $code = match ($status) {
      400 => 'invalid_parameters', 404 => 'not_found', 405 => 'method_not_allowed',
      429 => 'rate_limited', 503 => 'catalog_unavailable', default => 'request_failed',
    };
    if ($status === 404 && in_array($exception->getMessage(), ['module_not_found', 'version_not_found'], TRUE)) { $code = $exception->getMessage(); }
    $message = match ($status) {
      400 => 'Los parámetros de la consulta no son válidos.', 404 => 'El módulo o la versión no están disponibles.',
      405 => 'Esta API solo permite consultas GET y HEAD.', 429 => 'Demasiadas consultas. Inténtalo de nuevo más tarde.',
      default => 'No se pudo consultar el catálogo. Inténtalo de nuevo más tarde.',
    };
    $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
    $event->setResponse(new JsonResponse(['api_version' => 1, 'ok' => FALSE, 'code' => $code, 'message' => $message],
      $status, $headers + ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']));
  }
}