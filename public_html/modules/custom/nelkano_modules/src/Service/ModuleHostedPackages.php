<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Service;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/** Private managed uploads use the very same signed-package verifier as external releases. */
final class ModuleHostedPackages {
  public const PREFIX = 'private://nelkano-modules/packages/';

  public function __construct(private readonly PackageVerifier $packages) {}

  public function file(int $fid, ?NodeInterface $module = NULL, ?NodeInterface $release = NULL, bool $upload = TRUE): FileInterface {
    if ($upload && !ModuleAudience::canManageTester(\Drupal::currentUser())) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    $file = $fid ? File::load($fid) : NULL;
    $bound = $release && $release->hasField('field_release_package')
      && (int) $release->get('field_release_package')->target_id === $fid;
    if (!$file || !str_starts_with($file->getFileUri(), self::PREFIX)
      || ($upload && !$bound && (int) $file->getOwnerId() !== (int) \Drupal::currentUser()->id())) {
      throw new \InvalidArgumentException('Selecciona un paquete subido desde este formulario.');
    }
    // A file already attached to a different release is never reusable as an upload.
    if ($upload && !$bound) {
      $ids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)
        ->condition('type', 'nelkano_module_release')->condition('field_release_package', $fid)->range(0, 1)->execute();
      if ($ids || $file->isPermanent()) { throw new \InvalidArgumentException('El archivo ya pertenece a otra versión. Sube una copia nueva.'); }
    }
    $path = $this->path($file);
    $size = filesize($path);
    if (!$size || $size > PackageVerifier::MAX_ARCHIVE || strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)) !== 'nelkmod') {
      throw new \InvalidArgumentException('El paquete debe ser .nelkmod y no superar 32 MiB.');
    }
    return $file;
  }

  public function path(FileInterface $file): string {
    $file_system = \Drupal::service('file_system');
    $root = $file_system->realpath('private://');
    $packages = $file_system->realpath('private://nelkano-modules/packages');
    $path = $file_system->realpath($file->getFileUri());
    $docroot = realpath(DRUPAL_ROOT);
    if (!$root || !$packages || !$path || !is_file($path)
      || !str_starts_with($path, rtrim($packages, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
      || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
      || ($docroot && str_starts_with($path, rtrim($docroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
      throw new \InvalidArgumentException('El almacenamiento privado del servidor no está disponible.');
    }
    return $path;
  }

  public function inspect(int $fid, ?NodeInterface $module = NULL, ?NodeInterface $release = NULL): array {
    if (!ModuleAudience::canManageTester(\Drupal::currentUser())) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    $file = $this->file($fid, $module, $release);
    $bytes = file_get_contents($this->path($file));
    if (!is_string($bytes)) { throw new \InvalidArgumentException('No se pudo leer el paquete subido.'); }
    return $this->packages->verify($bytes) + ['url' => '', 'etag' => '', 'modified' => '', 'package_fid' => $fid];
  }

  public static function fid(NodeInterface $release): int {
    return $release->hasField('field_release_package') ? (int) $release->get('field_release_package')->target_id : 0;
  }
}
