<?php
/** Import transformation only: no active config, files or database backups are written. */
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageTransformEvent;
if (getenv('DRUPAL_DB_HOST')!=='database') throw new RuntimeException('Local Docker only.');
$active=\Drupal::service('config.storage');$storage=new MemoryStorage();$before=[];
foreach(['nelkano_home.docs','nelkano_home.settings','nelkano_home.app_updates'] as $name) {
  $before[$name]=$active->read($name);$incoming=$before[$name];
  if (!is_array($incoming)) continue;
  $incoming['deployment_probe']='incoming-source';
  foreach(['es','en'] as $lang) {
    if($name==='nelkano_home.docs')$incoming[$lang]['releases_items']=[['version'=>'overwrite-forbidden']];
    if($name==='nelkano_home.settings')$incoming[$lang]['extensions_sdk_enabled']=true;
  }
  $storage->write($name,$incoming);
}
\Drupal::service('event_dispatcher')->dispatch(new StorageTransformEvent($storage),ConfigEvents::STORAGE_TRANSFORM_IMPORT);
foreach(['es','en'] as $lang) {
  if($storage->read('nelkano_home.docs')[$lang]['releases_items']!==$before['nelkano_home.docs'][$lang]['releases_items'])throw new RuntimeException('Manual releases overwritten.');
  if($storage->read('nelkano_home.settings')[$lang]['extensions_sdk_enabled']!==$before['nelkano_home.settings'][$lang]['extensions_sdk_enabled'])throw new RuntimeException('SDK visibility overwritten.');
}
if($storage->read('nelkano_home.docs')['deployment_probe']!=='incoming-source')throw new RuntimeException('Unrelated source config blocked.');
foreach($before as $name=>$value)if($active->read($name)!==$value)throw new RuntimeException('Active data changed by test.');
echo "PASS: automatic import preserves manual releases and SDK visibility; no snapshots or active writes.\n";
