<?php

namespace Drupal\nelkano_home\Service;

/** Validates complete CSV batches and stores independently scoped catalogs. */
final class CompatibilityCatalog {
  public const HEADERS = ['nombre', 'estado', 'fps', 'dispositivo', 'fecha prueba'];
  public const COLLECTION = 'nelkano_home.compatibility';
  public const MAX_BYTES = 5242880;
  public const MAX_ROWS = 10000;

  public static function statuses(string $language = 'es'): array {
    return array_combine(['sin_probar', 'arranque_confirmado', 'gameplay_confirmado', 'con_incidencias', 'no_arranca'], $language === 'en'
      ? ['Not tested', 'Boot confirmed', 'Gameplay confirmed', 'Issues found', 'Does not boot']
      : ['Sin probar', 'Arranque confirmado', 'Gameplay confirmado', 'Con incidencias', 'No arranca']);
  }

  public static function path(string $system, string $language = 'es'): string {
    return SystemPages::path($system, $language) . ($language === 'en' ? '/compatibility' : '/compatibilidad');
  }

  public static function rows(string $system): array {
    return self::simplify(\Drupal::keyValue(self::COLLECTION)->get($system, []));
  }

  /** Private storage keys are derived from the five public fields, not CSV IDs. */
  public static function rowKey(array $row): string {
    return hash('sha256', json_encode([$row['nombre'], $row['dispositivo'], $row['fecha prueba']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  public static function simplify(array $rows): array {
    $simplified = [];
    foreach ($rows as $row) {
      if (!array_key_exists('fps', $row)) {
        $fps = $row['fps_gameplay'] ?? NULL;
        if ($fps === NULL || $fps === '') {
          $fps = preg_match('/FPS durante secuencia automática=(\d+(?:\.\d+)?)/u', $row['observaciones'] ?? '', $match)
            ? (float) $match[1] : ($row['fps_intro'] ?? NULL);
        }
        $row['fps'] = $fps;
        $row['fecha prueba'] = $row['fecha_prueba'] ?? '';
      }
      $row = array_replace(array_fill_keys(self::HEADERS, ''), array_intersect_key($row, array_flip(self::HEADERS)));
      $key = self::rowKey($row);
      // Preserve legacy rows even if their old IDs described the same test.
      $suffix = 0;
      while (isset($simplified[$key])) { $key = self::rowKey($row) . '-' . ++$suffix; }
      $simplified[$key] = $row;
    }
    return $simplified;
  }

  public static function migrateStored(): void {
    $collection = \Drupal::keyValue(self::COLLECTION);
    foreach (array_keys($collection->getAll()) as $system) {
      $lock = \Drupal::lock();
      $name = self::COLLECTION . ':' . $system;
      if (!$lock->acquire($name, 30)) { throw new \RuntimeException('Hay otra importación en curso.'); }
      try { $collection->set($system, self::simplify($collection->get($system, []))); }
      finally { $lock->release($name); }
    }
  }

  public static function parse(string $csv): array {
    if (strlen($csv) > self::MAX_BYTES || !mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
      throw new \InvalidArgumentException('Usa un CSV UTF-8 de hasta 5 MB.');
    }
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $first = strtok($csv, "\r\n") ?: '';
    $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    self::validateQuoting($csv, $delimiter);
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    try {
      $headers = fgetcsv($stream, 0, $delimiter, '"', '');
      $headers = $headers ? array_map('trim', $headers) : [];
      if (count($headers) !== count(self::HEADERS) || array_diff(self::HEADERS, $headers)) {
        throw new \InvalidArgumentException('La cabecera debe contener exactamente: ' . implode(',', self::HEADERS));
      }
      $rows = [];
      $record = 1;
      while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== FALSE) {
        $record++;
        if ($cells === [NULL]) { continue; }
        $fail = static function (string $reason) use ($record): never {
          throw new \InvalidArgumentException("Registro $record: $reason");
        };
        if (count($cells) !== count($headers)) { $fail('número de columnas incorrecto.'); }
        $row = array_combine($headers, array_map('trim', $cells));
        // Fixed order also makes comparison independent of CSV column order.
        $row = array_replace(array_fill_keys(self::HEADERS, ''), $row);
        foreach ($row as $key => $value) {
          if (mb_strlen($value) > 255) { $fail("$key es demasiado largo."); }
        }
        if ($row['nombre'] === '') { $fail('nombre obligatorio.'); }
        if (!isset(self::statuses()[$row['estado']])) { $fail('estado inválido.'); }
        foreach (['fps'] as $key) {
          $value = str_replace(',', '.', $row[$key]);
          if ($value !== '' && (!preg_match('/^\d+(\.\d{1,3})?$/D', $value) || (float) $value > 1000)) { $fail("$key debe estar vacío o ser un número entre 0 y 1000."); }
          $row[$key] = $value === '' ? NULL : (float) $value;
        }
        if ($row['fecha prueba'] !== '') {
          $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['fecha prueba']);
          if (!$date || $date->format('Y-m-d') !== $row['fecha prueba']) { $fail('fecha prueba debe ser una fecha válida AAAA-MM-DD.'); }
        }
        $key = self::rowKey($row);
        if (isset($rows[$key])) { $fail('prueba repetida para el mismo nombre, dispositivo y fecha.'); }
        $rows[$key] = $row;
        if (count($rows) > self::MAX_ROWS) { $fail('máximo 10000 registros.'); }
      }
      if (!$rows) { throw new \InvalidArgumentException('El CSV no contiene registros.'); }
      return $rows;
    }
    finally { fclose($stream); }
  }

  public static function changes(array $current, array $incoming): array {
    $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0];
    foreach ($incoming as $id => $row) {
      $counts[!isset($current[$id]) ? 'new' : ($current[$id] === $row ? 'unchanged' : 'updated')]++;
    }
    return $counts;
  }

  /** fgetcsv alone silently accepts unterminated quotes; reject corrupt files. */
  private static function validateQuoting(string $csv, string $delimiter): void {
    $state = 'start';
    $length = strlen($csv);
    for ($i = 0; $i < $length; $i++) {
      $char = $csv[$i];
      if ($state === 'quoted') {
        if ($char === '"') {
          if ($i + 1 < $length && $csv[$i + 1] === '"') { $i++; }
          else { $state = 'closed'; }
        }
      }
      elseif ($char === $delimiter || $char === "\n" || $char === "\r") { $state = 'start'; }
      elseif ($state === 'start' && $char === '"') { $state = 'quoted'; }
      elseif (($state === 'closed' && $char !== ' ' && $char !== "\t") || $char === '"') {
        throw new \InvalidArgumentException('Comillas incorrectas en el CSV. Encierra el campo completo entre comillas y duplica las comillas interiores.');
      }
      elseif ($state !== 'closed') { $state = 'unquoted'; }
    }
    if ($state === 'quoted') { throw new \InvalidArgumentException('El CSV contiene un campo con comillas sin cerrar.'); }
  }

  public static function import(string $system, array $rows): array {
    if (!isset(SystemPages::content('es')['pages'][$system])) { throw new \InvalidArgumentException('Sistema desconocido.'); }
    $lock = \Drupal::lock();
    $name = self::COLLECTION . ':' . $system;
    if (!$lock->acquire($name, 30)) { throw new \RuntimeException('Hay otra importación en curso. Vuelve a intentarlo.'); }
    try {
      $current = self::rows($system);
      $counts = self::changes($current, $rows);
      if (count($rows) > self::MAX_ROWS) { throw new \RuntimeException('El catálogo supera los 10000 registros.'); }
      \Drupal::keyValue(self::COLLECTION)->set($system, $rows);
      return $counts;
    }
    finally { $lock->release($name); }
  }
}
