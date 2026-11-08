<?php

namespace Drupal\nelkano_home\Service;

/** Validates complete CSV batches and stores independently scoped catalogs. */
final class CompatibilityCatalog {
  public const REQUIRED_HEADERS = ['nombre', 'estado', 'velocidad_porcentaje', 'dispositivo', 'fecha prueba'];
  public const OPTIONAL_HEADERS = ['observaciones', 'version_nelkano', 'sistema_id', 'juego_id', 'rom_sha256'];
  public const HEADERS = [...self::REQUIRED_HEADERS, ...self::OPTIONAL_HEADERS];
  public const COLLECTION = 'nelkano_home.compatibility';
  public const LISTS_COLLECTION = 'nelkano_home.compatibility_lists';
  public const MAX_LISTS = 20;
  public const LEGACY_FPS_COLLECTION = 'nelkano_home.compatibility_legacy_fps';
  public const MAX_BYTES = 5242880;
  public const MAX_ROWS = 10000;
  public const PAGE_SIZE = 8;
  public const MAX_OBSERVATIONS = 4096;

  public static function statuses(string $language = 'es'): array {
    return array_combine(['perfecto', 'jugable', 'arranque_confirmado', 'con_incidencias', 'no_arranca', 'no_probado'], $language === 'en'
      ? ['Perfect', 'Playable', 'Boot confirmed', 'With issues', 'Does not boot', 'Not tested']
      : ['Perfecto', 'Jugable', 'Arranque confirmado', 'Con incidencias', 'No arranca', 'No probado']);
  }

  /** Retain previous evidence while translating old vocabulary to six states. */
  public static function normalizeStatus(string $status): string {
    $status = mb_strtolower(trim($status));
    $aliases = [
      '' => 'no_probado', 'sin_probar' => 'no_probado', 'no probado' => 'no_probado',
      'arranque confirmado' => 'arranque_confirmado', 'no arranca' => 'no_arranca',
      'con incidencias' => 'con_incidencias', 'gameplay_confirmado' => 'jugable',
      'bloqueo' => 'con_incidencias', 'demo_grafica_observada' => 'arranque_confirmado',
      'funcion_confirmada' => 'arranque_confirmado', 'fallo_grafico' => 'con_incidencias',
    ];
    return $aliases[$status] ?? $status;
  }

  public static function path(string $system, string $language = 'es'): string {
    return SystemPages::path($system, $language) . ($language === 'en' ? '/compatibility' : '/compatibilidad');
  }

  public static function rows(string $system, ?string $list_id = NULL): array {
    $lists = \Drupal::keyValue(self::LISTS_COLLECTION)->get($system);
    if ($lists !== NULL) {
      foreach ($lists as $list) {
        if ($list_id === NULL || $list['id'] === $list_id) { return self::simplify($list['rows']); }
      }
      return [];
    }
    if ($list_id !== NULL && $list_id !== 'default') { return []; }
    return self::simplify(\Drupal::keyValue(self::COLLECTION)->get($system, []));
  }

  /** Existing catalogs remain intact as one editable list, without guessing a console. */
  public static function lists(string $system): array {
    $stored = \Drupal::keyValue(self::LISTS_COLLECTION)->get($system);
    if ($stored !== NULL) { return $stored; }
    $rows = self::rows($system);
    return $rows ? [['id' => 'default', 'title' => 'Catálogo actual', 'filename' => '', 'rows' => $rows]] : [];
  }

  public static function revision(array $lists): string {
    return hash('sha256', serialize($lists));
  }

  public static function listSummary(array $lists): array {
    return array_map(static fn($list) => ['id' => $list['id'], 'title' => $list['title'], 'filename' => $list['filename'], 'count' => count($list['rows'])], $lists);
  }

  /** Validate every replacement before one atomic write; reject stale editors. */
  public static function saveLists(string $system, array $definitions, array $replacements, string $revision): array {
    if (!SystemPages::exists($system)) { throw new \InvalidArgumentException('Sistema desconocido.'); }
    if (!$definitions || count($definitions) > self::MAX_LISTS || !array_is_list($definitions)) {
      throw new \InvalidArgumentException('Añade entre 1 y ' . self::MAX_LISTS . ' listas.');
    }
    $lock = \Drupal::lock();
    $name = self::COLLECTION . ':' . $system;
    if (!$lock->acquire($name, 30)) { throw new \RuntimeException('Hay otra importación en curso. Vuelve a intentarlo.'); }
    try {
      $current = self::lists($system);
      if (!hash_equals(self::revision($current), $revision)) { throw new \RuntimeException('La compatibilidad ha cambiado en otra sesión. Recarga la página antes de guardar.'); }
      $by_id = array_column($current, NULL, 'id');
      $next = [];
      $seen = [];
      foreach ($definitions as $definition) {
        if (!is_array($definition) || !is_string($definition['id'] ?? NULL) || !preg_match('/^[a-z0-9-]{1,64}$/D', $definition['id']) || !is_string($definition['title'] ?? NULL)) {
          throw new \InvalidArgumentException('Lista no válida.');
        }
        $id = $definition['id'];
        $title = trim($definition['title']);
        if ($title === '' || mb_strlen($title) > 80 || isset($seen[$id])) { throw new \InvalidArgumentException('Cada lista necesita un título de hasta 80 caracteres y un identificador único.'); }
        $seen[$id] = TRUE;
        $list = $by_id[$id] ?? ['id' => $id, 'rows' => [], 'filename' => ''];
        if (isset($replacements[$id])) {
          $list['rows'] = $replacements[$id]['rows'];
          $list['filename'] = $replacements[$id]['filename'];
        }
        if (!$list['rows'] || count($list['rows']) > self::MAX_ROWS) { throw new \InvalidArgumentException('Selecciona un CSV válido para cada nueva lista (máximo 10000 registros).'); }
        $list['title'] = $title;
        $next[] = $list;
      }
      if (array_diff_key($replacements, $seen)) { throw new \InvalidArgumentException('Hay archivos sin una lista asociada.'); }
      CompatibilityCommunity::publishLists($system, $next, static fn()=>\Drupal::keyValue(self::LISTS_COLLECTION)->set($system, $next));
      return ['lists' => self::listSummary($next), 'revision' => self::revision($next)];
    }
    finally { $lock->release($name); }
  }

  /** A test is identified by game, device, date and, when provided, app version. */
  public static function rowKey(array $row): string {
    $identity = [($row['juego_id'] ?? '') ?: $row['nombre'], $row['dispositivo'], $row['fecha prueba']];
    // Preserve separately named CSV entries of the same ROM; permanent game identity is owned by Community.
    if (($row['juego_id'] ?? '') !== '') { $identity[] = $row['nombre']; }
    if (($row['sistema_id'] ?? '') !== '') { $identity[] = $row['sistema_id']; }
    if (($row['rom_sha256'] ?? '') !== '') { $identity[] = $row['rom_sha256']; }
    if (($row['version_nelkano'] ?? '') !== '') { $identity[] = $row['version_nelkano']; }
    return hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  public static function simplify(array $rows): array {
    $simplified = [];
    foreach ($rows as $row) {
      // FPS cannot be converted to speed without the original emulated clock.
      $row['velocidad_porcentaje'] = ($row['velocidad_porcentaje'] ?? '') === '' ? NULL : $row['velocidad_porcentaje'];
      $row['fecha prueba'] = $row['fecha prueba'] ?? $row['fecha_prueba'] ?? '';
      $row = array_replace(array_fill_keys(self::HEADERS, ''), array_intersect_key($row, array_flip(self::HEADERS)));
      $row['estado'] = self::normalizeStatus($row['estado']);
      $key = self::rowKey($row);
      // Preserve legacy rows even if their old IDs described the same test.
      $suffix = 0;
      while (isset($simplified[$key])) { $key = self::rowKey($row) . '-' . ++$suffix; }
      $simplified[$key] = $row;
    }
    return $simplified;
  }

  public static function migrateStored(): void {
    foreach (array_keys(\Drupal::keyValue(self::COLLECTION)->getAll()) as $system) {
      self::migrateSystem($system);
    }
  }

  /** Preserve the original FPS catalog privately before changing its metric. */
  public static function migrateSystem(string $system): void {
    $collection = \Drupal::keyValue(self::COLLECTION);
    $lock = \Drupal::lock();
    $name = self::COLLECTION . ':' . $system;
    if (!$lock->acquire($name, 30)) { throw new \RuntimeException('Hay otra importación en curso.'); }
    try {
      $rows = $collection->get($system);
      if ($rows === NULL) { return; }
      $has_fps = array_filter($rows, static fn($row) => (bool) array_intersect(['fps', 'fps_intro', 'fps_gameplay'], array_keys($row)));
      if ($has_fps) {
        $archive = \Drupal::keyValue(self::LEGACY_FPS_COLLECTION);
        if ($archive->get($system) === NULL) { $archive->set($system, $rows); }
      }
      $collection->set($system, self::simplify($rows));
    }
    finally { $lock->release($name); }
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
      if (count($headers) !== count(array_unique($headers)) || array_diff(self::REQUIRED_HEADERS, $headers) || array_diff($headers, self::HEADERS)) {
        throw new \InvalidArgumentException('Columnas obligatorias: ' . implode(',', self::REQUIRED_HEADERS) . '. Opcionales: ' . implode(',', self::OPTIONAL_HEADERS) . '. No se admiten columnas repetidas ni desconocidas.');
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
          $limit = match($key) {'observaciones'=>self::MAX_OBSERVATIONS,'version_nelkano','rom_sha256'=>64,'juego_id'=>80,'sistema_id'=>32,default=>255};
          if (mb_strlen($value) > $limit) { $fail("$key supera el máximo de $limit caracteres."); }
        }
        if ($row['nombre'] === '') { $fail('nombre obligatorio.'); }
        if ($row['juego_id'] !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$row['juego_id'])) { $fail('juego_id debe ser un nombre máquina estable en minúsculas, con guiones.'); }
        if ($row['rom_sha256'] !== '' && !preg_match('/^[a-f0-9]{64}$/D',$row['rom_sha256'])) { $fail('rom_sha256 debe tener 64 caracteres hexadecimales en minúsculas.'); }
        if ($row['rom_sha256'] !== '' && ($row['juego_id'] === '' || $row['sistema_id'] === '')) { $fail('rom_sha256 requiere juego_id y sistema_id.'); }
        if ($row['sistema_id'] !== '') { try { CompatibilityCommunity::pageId($row['sistema_id']); } catch (\Exception) { $fail('sistema_id no reconocido.'); } }
        $row['estado'] = self::normalizeStatus($row['estado']);
        if (!isset(self::statuses()[$row['estado']])) { $fail('estado inválido. Usa: ' . implode(', ', array_keys(self::statuses())) . '.'); }
        foreach (['velocidad_porcentaje'] as $key) {
          $value = str_replace(',', '.', $row[$key]);
          if ($value !== '' && (!preg_match('/^\d+(\.\d{1,3})?$/D', $value) || (float) $value > 1000)) { $fail("$key debe estar vacío o ser un número entre 0 y 1000."); }
          $row[$key] = $value === '' ? NULL : (float) $value;
        }
        if ($row['fecha prueba'] !== '') {
          $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['fecha prueba']);
          if (!$date || $date->format('Y-m-d') !== $row['fecha prueba']) { $fail('fecha prueba debe ser una fecha válida AAAA-MM-DD.'); }
        }
        $key = self::rowKey($row);
        if (isset($rows[$key])) { $fail('prueba repetida para el mismo nombre, dispositivo, fecha y versión de Nelkano.'); }
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
      $lists = \Drupal::keyValue(self::LISTS_COLLECTION)->get($system);
      if ($lists !== NULL) {
        if (count($lists) !== 1) { throw new \InvalidArgumentException('Selecciona la lista que quieres sustituir desde la administración.'); }
        $lists[0]['rows'] = $rows;
        CompatibilityCommunity::publishLists($system, $lists, static fn()=>\Drupal::keyValue(self::LISTS_COLLECTION)->set($system, $lists));
      }
      else { CompatibilityCommunity::publishLists($system, [['rows'=>$rows]], static fn()=>\Drupal::keyValue(self::COLLECTION)->set($system, $rows)); }
      return $counts;
    }
    finally { $lock->release($name); }
  }
}
