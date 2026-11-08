<?php
/** Read-only validation of the actual CSV files using the production importer. */
use Drupal\nelkano_home\Service\CompatibilityCatalog as Catalog;
use Drupal\nelkano_home\Service\CompatibilityCommunity as Community;
if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only.');
$directory = realpath((string) getenv('NELKANO_CSV_INPUT_DIR'));
if (!$directory || !is_dir($directory)) throw new RuntimeException('Set NELKANO_CSV_INPUT_DIR to the input directory.');
$files = glob($directory . '/*.csv'); sort($files);
if (!$files) throw new RuntimeException('No CSV files found.');
$report = []; $errors = []; $total = 0;
foreach ($files as $file) {
  try {
    $rows = Catalog::parse(file_get_contents($file)); $pages = [];
    foreach ($rows as $row) {
      if ($row['version_nelkano'] !== '3.0.7') throw new RuntimeException('Expected version_nelkano 3.0.7');
      if ($row['sistema_id'] !== '') $pages[Community::pageId($row['sistema_id'])] = true;
    }
    if (count($pages) > 1) throw new RuntimeException('One CSV mixes unrelated system pages.');
    $report[] = ['file'=>basename($file),'rows'=>count($rows),'pages'=>array_keys($pages),'sha256'=>hash_file('sha256',$file)];
    $total += count($rows);
  } catch (Throwable $error) { $errors[] = ['file'=>basename($file),'error'=>$error->getMessage()]; }
}
echo json_encode(['files'=>count($files),'rows'=>$total,'errors'=>$errors,'items'=>$report,'databaseModified'=>false], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($errors) throw new RuntimeException('CSV import validation failed.');
