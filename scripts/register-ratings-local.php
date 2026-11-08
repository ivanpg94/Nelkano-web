<?php
/** Registers the signed public module in local Drupal only; never publishes production. */
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

$options=\Drupal::database()->getConnectionOptions();
if(PHP_SAPI!=='cli'||getenv('DRUPAL_DB_HOST')!=='database'||($options['database']??'')!=='nelkano_drupal')throw new RuntimeException('Local Drupal only.');
$path=getenv('NELKANO_RATINGS_PACKAGE');
if(!$path||!is_file($path)||filesize($path)>32*1024*1024)throw new RuntimeException('Supply NELKANO_RATINGS_PACKAGE.');
$bytes=file_get_contents($path);$switcher=\Drupal::service('account_switcher');$switcher->switchTo(User::load(1));
try {
  $registry=\Drupal::service('nelkano_modules.registry');
  $ids=\Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type','nelkano_module')->condition('field_module_id','nelkano.ratings')->execute();
  $module=$ids?Node::load(reset($ids)):NULL;
  if($module) {
    foreach($registry->releases($module) as $release)if(hash_equals((string)$release->get('field_release_hash')->value,hash('sha256',$bytes))){echo 'Already registered: '.$module->toUrl()->toString().PHP_EOL;return;}
  }
  $directory='private://nelkano-modules/packages';
  foreach(['private://nelkano-modules',$directory] as $parent)
    if(!\Drupal::service('file_system')->prepareDirectory($parent,FileSystemInterface::CREATE_DIRECTORY|FileSystemInterface::MODIFY_PERMISSIONS))throw new RuntimeException('Private module directory unavailable.');
  $file=\Drupal::service('file.repository')->writeData($bytes,$directory.'/nelkano.ratings-0.1.0.nelkmod',FileExists::Rename);
  $file->setTemporary();$file->save();
  try {
    $verified=$registry->inspectUpload((int)$file->id(),$module);
    if($verified['id']!=='nelkano.ratings')throw new RuntimeException('Unexpected module identity.');
    if(!$module)$module=$registry->saveModule(NULL,NULL,['title'=>'Valorar','description'=>'Comparte si has terminado un juego en Nelkano, los problemas encontrados y tu experiencia por dispositivo. Las valoraciones se reúnen con las pruebas en la página de compatibilidad. Requiere una cuenta y la compilación de Nelkano que incorpora Valorar.','source'=>'','access'=>'public','published'=>TRUE,'langcode'=>'es','systems'=>array_values(array_diff(array_keys(\Drupal\nelkano_modules\Service\ModuleSystems::options()),['switch'])),'notes'=>'Primera versión pública: Valorar desde la ficha y el menú de partida. Identificación por contenido, experiencias editables y moderación web.'], $verified);
    else $registry->saveVersion($module,NULL,NULL,$verified,'Valorar: experiencias de compatibilidad por juego.');
    echo 'Public local module: '.$module->toUrl()->toString().PHP_EOL;
  } catch(\Throwable $e) {if($file->isTemporary())$file->delete();throw $e;}
} finally {$switcher->switchBack();}
