<?php

use Drupal\nelkano_home\Service\CompatibilityCatalog as Catalog;
use Drupal\nelkano_home\Service\SystemPages;

if (getenv('DRUPAL_DB_HOST') !== 'database') { throw new RuntimeException('Local Docker only.'); }
$checks = 0;
$expect = static function ($value, $message) use (&$checks) { if (!$value) { throw new RuntimeException($message); } $checks++; };
$get = static fn($path) => \Drupal::httpClient()->get('http://localhost' . $path, ['http_errors' => FALSE, 'timeout' => 15]);
$kv = \Drupal::keyValue(Catalog::COLLECTION);
$original = [];
foreach (['game-boy', 'game-boy-color'] as $system) { $original[$system] = $kv->get($system); }
$sample = file_get_contents(DRUPAL_ROOT . '/modules/custom/nelkano_home/assets/compatibilidad-ejemplo.csv');
$serialize = static function (array $rows, string $separator = ','): string {
  $f = fopen('php://temp', 'r+');
  fputcsv($f, Catalog::HEADERS, $separator, '"', '');
  foreach ($rows as $row) { fputcsv($f, array_values($row), $separator, '"', ''); }
  rewind($f); $csv = stream_get_contents($f); fclose($f); return $csv;
};
try {
  $rows = Catalog::parse($sample);
  [$first, $second] = array_keys($rows);
  $expect(count($rows) === 2, 'Sample requires exactly two rows');
  $expect($rows[$second]['fps'] === NULL, 'Blank FPS must be null');
  $expect(Catalog::parse($serialize($rows, ';')) === $rows, 'Semicolon CSV failed');
  $zero = $rows; $zero[$second]['fps'] = 0;
  $expect(Catalog::parse($serialize($zero))[$second]['fps'] === 0.0, 'Zero must remain zero');
  $quoted = $rows; $quoted[$first]['dispositivo'] = "Coma, comillas \"sí\"\ny salto";
  $expect(array_values(Catalog::parse($serialize($quoted)))[0]['dispositivo'] === $quoted[$first]['dispositivo'], 'Quoted multiline CSV failed');
  $invalid = [str_replace('fps', 'otra', $sample), $serialize([$rows[$first], $rows[$first]]), "id,nombre\na,b", implode(',', Catalog::HEADERS), str_repeat('x', Catalog::MAX_BYTES + 1)];
  foreach (['estado' => 'perfecto', 'fps' => '-1', 'fecha prueba' => '2026-02-30', 'nombre' => ''] as $key => $value) {
    $bad = $rows; $bad[$first][$key] = $value; $invalid[] = $serialize($bad);
  }
  foreach ($invalid as $csv) {
    try { Catalog::parse($csv); $rejected = FALSE; } catch (InvalidArgumentException $e) { $rejected = TRUE; }
    $expect($rejected, 'Invalid CSV accepted');
  }
  foreach (["\"sin cierre", 'campo"roto', '"cerrado"resto'] as $bad_quote) {
    try { Catalog::parse(implode(',', Catalog::HEADERS) . "\n" . $bad_quote); $rejected = FALSE; } catch (InvalidArgumentException $e) { $rejected = TRUE; }
    $expect($rejected, 'Malformed quoting accepted');
  }
  $legacy = ['old' => ['id'=>'old', 'nombre'=>'Old', 'estado'=>'arranque_confirmado', 'fps_intro'=>60, 'fps_gameplay'=>NULL, 'dispositivo'=>'Moto', 'fecha_prueba'=>'2026-09-17', 'observaciones'=>'FPS durante secuencia automática=52.3', 'version_nelkano'=>'old']];
  $simple = Catalog::simplify($legacy);
  $expect(array_keys(array_values($simple)[0]) === Catalog::HEADERS, 'Legacy migration retained extra fields');
  $expect(array_values($simple)[0]['fps'] === 52.3 && array_values($simple)[0]['estado'] === 'arranque_confirmado', 'Legacy migration changed measurement/status');
  $expect(Catalog::simplify($simple) === $simple, 'Migration must be idempotent');
  $sameName = $rows; $extra = $rows[$first]; $extra['dispositivo'] = 'Second device'; $sameName[Catalog::rowKey($extra)] = $extra;
  $expect(count(Catalog::parse($serialize($sameName))) === 3, 'Different devices incorrectly deduplicated');
  $kv->delete('game-boy'); $kv->delete('game-boy-color');
  $expect(Catalog::rows('game-boy') === [], 'Parsing published data');
  $expect(Catalog::import('game-boy', $rows)['new'] === 2, 'New rows count');
  $expect(Catalog::rows('game-boy-color') === [], 'Cross-system contamination');
  $expect(Catalog::import('game-boy', $rows)['unchanged'] === 2, 'Reimport duplicated data');
  $one = [$first => $rows[$first]]; $one[$first]['fps'] = 55.0;
  $expect(Catalog::import('game-boy', $one)['updated'] === 1, 'Update failed');
  $expect(count(Catalog::rows('game-boy')) === 1 && !isset(Catalog::rows('game-boy')[$second]), 'Upload must replace all previous rows');
  $one[$first]['nombre'] = 'QA <script>alert(1)</script>';
  Catalog::import('game-boy', $one + [$second => $rows[$second]]);
  $upload = new \Drupal\nelkano_home\Controller\CompatibilityUploadController();
  $file = new \Symfony\Component\HttpFoundation\File\UploadedFile(DRUPAL_ROOT . '/modules/custom/nelkano_home/assets/compatibilidad-ejemplo.csv', 'example.csv', 'text/csv', NULL, TRUE);
  $request = new \Symfony\Component\HttpFoundation\Request([], [], [], [], ['csv' => $file]);
  $response = $upload->upload($request, 'game-boy-color');
  $expect($response->getStatusCode() === 200 && count(Catalog::rows('game-boy-color')) === 2, 'Direct upload failed');
  $response = $upload->upload(new \Symfony\Component\HttpFoundation\Request(), 'game-boy-color');
  $expect($response->getStatusCode() === 400 && count(Catalog::rows('game-boy-color')) === 2, 'Invalid upload erased stored rows');
  $expect($upload->upload($request, 'unknown')->getStatusCode() === 404, 'Unknown upload system accepted');
  $route = \Drupal::service('router.route_provider')->getRouteByName('nelkano_home.upload_compatibility');
  $expect($route->getRequirement('_csrf_request_header_token') === 'TRUE' && $route->getMethods() === ['POST'], 'Upload security requirements missing');
  $kv->delete('game-boy-color');
  $html = (string) $get(Catalog::path('game-boy'))->getBody();
  $expect(str_contains($html, 'QA &lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>alert(1)</script>'), 'Unsafe or missing output');
  $expect(str_contains($html, 'No medido'), 'Missing null FPS label');
  $dom = new DOMDocument(); @$dom->loadHTML($html);
  $xpath = new DOMXPath($dom);
  $heads = []; foreach ($xpath->query('//table[contains(@class,"nk-compat-table")]/thead/tr/th') as $th) { $heads[] = trim($th->textContent); }
  $expect($heads === ['Nombre', 'Estado', 'FPS', 'Dispositivo', 'Fecha prueba'], 'Expected exactly five table columns');
  $expect(!str_contains($html, 'Detalles de la prueba') && !str_contains($html, 'FPS intro') && !str_contains($html, 'FPS gameplay'), 'Legacy details still visible');
  $expect(str_contains($html, 'Moto g15 (ejemplo)') && str_contains($html, '2026-09-17'), 'Device/date missing from table');
  $english = (string) $get(Catalog::path('game-boy', 'en'))->getBody();
  $expect(str_contains($english, '>Device</th>') && str_contains($english, '>Test date</th>'), 'English table missing fields');
  $filtered = (string) $get(Catalog::path('game-boy') . '?q=Puzzle&estado=arranque_confirmado')->getBody();
  $expect(str_contains($filtered, 'Puzzle de ejemplo') && !str_contains($filtered, 'QA &lt;script'), 'Filtering failed');
  $expect(str_contains((string) $get(Catalog::path('game-boy') . '?q=nomatch')->getBody(), 'No hay juegos con estos filtros'), 'Filtered empty state failed');
  $expect(str_contains((string) $get(Catalog::path('game-boy-color'))->getBody(), 'Todavía no hay pruebas publicadas'), 'Catalog empty state failed');
  $many = [];
  for ($i = 1; $i <= 51; $i++) { $row = $rows[$first]; $row['nombre'] = 'Pagination ' . $i; $many[Catalog::rowKey($row)] = $row; }
  Catalog::import('game-boy-color', $many);
  $html = (string) $get(Catalog::path('game-boy-color') . '?q=Pagination&pagina=2')->getBody();
  $expect(substr_count($html, '<th scope="row"') === 1 && str_contains($html, 'Pagination 51'), 'Pagination failed');
  foreach (['es', 'en'] as $language) {
    foreach (SystemPages::content($language)['pages'] as $system => $page) {
      $response = $get(Catalog::path($system, $language));
      $expect($response->getStatusCode() === ($page['enabled'] ? 200 : 404), 'Compatibility route failed ' . $system);
      if ($page['enabled']) {
        $expect(str_contains((string) $get(SystemPages::path($system, $language))->getBody(), Catalog::path($system, $language)), 'Missing compatibility button');
      }
    }
  }
  $expect($get('/sistemas/no-core/compatibilidad')->getStatusCode() === 404, 'Unknown core must 404');
  $expect($get('/admin/nelkano/sistemas/game-boy/compatibilidad')->getStatusCode() !== 200, 'Anonymous importer access');
  echo "PASS: $checks compatibility checks.\n";
}
finally {
  foreach ($original as $system => $data) { $data === NULL ? $kv->delete($system) : $kv->set($system, $data); }
}
