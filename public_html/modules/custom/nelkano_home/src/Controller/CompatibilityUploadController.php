<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nelkano_home\Service\CompatibilityCatalog as Catalog;
use Drupal\nelkano_home\Service\SystemPages;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Direct upload from each system's editor. */
final class CompatibilityUploadController extends ControllerBase {
  public function legacyPage(): RedirectResponse {
    return parent::redirect('nelkano_home.admin_systems');
  }

  public function upload(Request $request, string $system): JsonResponse {
    if (!isset(SystemPages::content('es')['pages'][$system])) {
      return $this->result(['error' => 'Sistema desconocido.'], 404);
    }
    if ($request->request->has('lists')) { return $this->saveLists($request, $system); }
    $file = $request->files->get('csv');
    if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'csv' || $file->getSize() > Catalog::MAX_BYTES) {
      return $this->result(['error' => 'Selecciona un archivo .csv válido de hasta 5 MB.'], 400);
    }
    try {
      $rows = Catalog::parse(file_get_contents($file->getPathname()));
      Catalog::import($system, $rows);
      return $this->result(['count' => count($rows)]);
    }
    catch (\InvalidArgumentException $e) { return $this->result(['error' => $e->getMessage()], 400); }
    catch (\RuntimeException $e) { return $this->result(['error' => $e->getMessage()], 409); }
  }

  private function saveLists(Request $request, string $system): JsonResponse {
    try {
      $definitions = json_decode($request->request->getString('lists'), TRUE, 32, JSON_THROW_ON_ERROR);
      if (!is_array($definitions) || !array_is_list($definitions) || count($definitions) > Catalog::MAX_LISTS) { throw new \InvalidArgumentException('Listas no válidas.'); }
      $files = $request->files->all('csvs');
      if (count($files) > Catalog::MAX_LISTS) { throw new \InvalidArgumentException('Demasiados archivos.'); }
      $replacements = [];
      foreach ($files as $id => $file) {
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'csv' || $file->getSize() > Catalog::MAX_BYTES) {
          throw new \InvalidArgumentException('Selecciona archivos .csv válidos de hasta 5 MB cada uno.');
        }
        try { $rows = Catalog::parse(file_get_contents($file->getPathname())); }
        catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException($file->getClientOriginalName() . ': ' . $e->getMessage()); }
        $replacements[$id] = ['rows' => $rows, 'filename' => mb_substr($file->getClientOriginalName(), 0, 255)];
      }
      return $this->result(Catalog::saveLists($system, $definitions, $replacements, $request->request->getString('revision')));
    }
    catch (\JsonException | \InvalidArgumentException | \Symfony\Component\HttpFoundation\Exception\BadRequestException $e) { return $this->result(['error' => 'No se ha guardado ningún cambio. ' . $e->getMessage()], 400); }
    catch (\RuntimeException $e) { return $this->result(['error' => $e->getMessage()], 409); }
  }

  private function result(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
  }
}
