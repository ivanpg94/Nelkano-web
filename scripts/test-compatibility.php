<?php
use Drupal\nelkano_home\Service\CompatibilityCatalog as Catalog;
use Drupal\nelkano_home\Service\SystemPages;

if (getenv('DRUPAL_DB_HOST') !== 'database') { throw new RuntimeException('Local Docker only.'); }
$checks = 0;
$expect = static function ($value, $message) use (&$checks) { if (!$value) { throw new RuntimeException($message); } $checks++; };
$get = static fn($path) => \Drupal::httpClient()->get('http://localhost' . $path, ['http_errors' => FALSE, 'timeout' => 30]);
$kv = \Drupal::keyValue(Catalog::COLLECTION);
$archive = \Drupal::keyValue(Catalog::LEGACY_FPS_COLLECTION);
$listStore = \Drupal::keyValue(Catalog::LISTS_COLLECTION);
$communitySnapshot=[];
$communityIndexStore=\Drupal::keyValue('nelkano_home.compatibility_index');$communityIndex=$communityIndexStore->getAll();
if(\Drupal\nelkano_home\Service\CompatibilityCommunity::ready())foreach(array_keys(\Drupal\nelkano_home\Service\CompatibilityCommunity::schema()) as $table)$communitySnapshot[$table]=\Drupal::database()->select($table,'t')->fields('t')->execute()->fetchAll(PDO::FETCH_ASSOC);
$originalLists = [];
$original = [];
foreach (['game-boy', 'game-boy-advance'] as $system) { $original[$system] = $kv->get($system); $originalLists[$system] = $listStore->get($system); }
$archiveOriginal = $archive->get('game-boy');
$serialize = static function (array $rows, string $separator = ',', array $headers = Catalog::HEADERS): string {
  $f = fopen('php://temp', 'r+'); fputcsv($f, $headers, $separator, '"', '');
  foreach ($rows as $row) { fputcsv($f, array_map(static fn($key) => $row[$key] ?? '', $headers), $separator, '"', ''); }
  rewind($f); $result = stream_get_contents($f); fclose($f); return $result;
};
$dom = static function (string $html): DOMXPath { $document = new DOMDocument(); @$document->loadHTML($html); return new DOMXPath($document); };
$recordCount = static fn(DOMXPath $xpath) => $xpath->query('//details[@class="nk-compat-record"]')->length;
try {
  foreach (array_keys($originalLists) as $system) { $listStore->delete($system); }
  $states = ['perfecto'=>'Perfecto','jugable'=>'Jugable','arranque_confirmado'=>'Arranque confirmado','con_incidencias'=>'Con incidencias','no_arranca'=>'No arranca','no_probado'=>'No probado'];
  $expect(Catalog::statuses() === $states, 'Expected exactly the six approved shared states');
  $expect(array_values(Catalog::statuses('en')) === ['Perfect','Playable','Boot confirmed','With issues','Does not boot','Not tested'], 'English states mismatch');
  $sample = file_get_contents(DRUPAL_ROOT . '/modules/custom/nelkano_home/assets/compatibilidad-ejemplo.csv');
  $rows = Catalog::parse($sample); [$first, $second] = array_keys($rows);
  $expect(count($rows) === 2 && $rows[$second]['velocidad_porcentaje'] === NULL, 'Sample or missing speed changed');
  $expect(Catalog::parse($serialize($rows, ';')) === $rows, 'Semicolon import mismatch');
  $expect(Catalog::parse("\xEF\xBB\xBF" . $serialize($rows)) === $rows, 'UTF-8 BOM mismatch');
  $expect(Catalog::parse($serialize($rows, ',', array_reverse(Catalog::HEADERS))) === $rows, 'Header order mismatch');
  $base = Catalog::parse($serialize($rows, ',', Catalog::REQUIRED_HEADERS));
  $expect(count($base) === 2 && array_values($base)[0]['observaciones'] === '', 'Five-column imports unsupported');
  foreach ($states as $status => $label) {
    $test = $rows[$first]; $test['estado'] = $status;
    $expect(array_values(Catalog::parse($serialize([$test])))[0]['estado'] === $status, 'Canonical state rejected');
    $test['estado'] = $label;
    $expect(array_values(Catalog::parse($serialize([$test])))[0]['estado'] === $status, 'Human-readable state rejected');
  }
  foreach ([''=>'no_probado','sin_probar'=>'no_probado','gameplay_confirmado'=>'jugable','con_incidencias'=>'con_incidencias','bloqueo'=>'con_incidencias','fallo_grafico'=>'con_incidencias','demo_grafica_observada'=>'arranque_confirmado','funcion_confirmada'=>'arranque_confirmado'] as $old => $new) {
    $test = $rows[$first]; $test['estado'] = $old;
    $expect(array_values(Catalog::parse($serialize([$test])))[0]['estado'] === $new, 'Legacy normalization failed: ' . $old);
  }
  $test = $rows[$first]; $test['estado'] = 'con_incidencias'; $test['observaciones'] = 'Título visible; gameplay no confirmado. Audio pendiente.';
  $normalized = array_values(Catalog::parse($serialize([$test])))[0];
  $expect($normalized['estado'] === 'con_incidencias' && $normalized['observaciones'] === $test['observaciones'], 'Blocked menu promoted or notes changed');
  $quoted = $rows; $quoted[$first]['observaciones'] = "Exploración, \"combate\".\nSegunda línea.";
  $expect(Catalog::parse($serialize($quoted)) === $quoted, 'Multiline metadata changed');
  $zero = $rows; $zero[$second]['velocidad_porcentaje'] = 0;
  $expect(Catalog::parse($serialize($zero))[$second]['velocidad_porcentaje'] === 0.0, 'Measured zero lost');
  $decimal = $rows; $decimal[$first]['velocidad_porcentaje'] = '100,125';
  $expect(Catalog::parse($serialize($decimal))[$first]['velocidad_porcentaje'] === 100.125, 'Decimal comma or speed above 100 rejected');
  foreach (['estado'=>'unknown', 'velocidad_porcentaje'=>'NaN', 'fecha prueba'=>'2026-02-30', 'nombre'=>'', 'observaciones'=>str_repeat('á',Catalog::MAX_OBSERVATIONS+1), 'version_nelkano'=>str_repeat('x',65)] as $field => $value) {
    $bad = $rows; $bad[$first][$field] = $value;
    try { Catalog::parse($serialize($bad)); $rejected = FALSE; } catch (InvalidArgumentException $e) { $rejected = TRUE; }
    $expect($rejected, 'Invalid input accepted: ' . $field);
  }
  foreach ([str_replace('velocidad_porcentaje','fps',$sample), $serialize([$rows[$first],$rows[$first]]), "nombre,estado\na,b", str_repeat('x',Catalog::MAX_BYTES+1), "\"sin cierre"] as $invalid) {
    try { Catalog::parse($invalid); $rejected = FALSE; } catch (InvalidArgumentException $e) { $rejected = TRUE; }
    $expect($rejected, 'Invalid CSV accepted');
  }
  $other = $rows[$first]; $other['version_nelkano'] = '3.0.5';
  $expect(count(Catalog::parse($serialize([$rows[$first], $other]))) === 2, 'Versions deduplicated');
  $legacy = ['old'=>['nombre'=>'Old','estado'=>'gameplay_confirmado','fps'=>60,'dispositivo'=>'PC','fecha_prueba'=>'2026-09-17','observaciones'=>'Original note','version_nelkano'=>'old']];
  $archive->delete('game-boy'); $kv->set('game-boy',$legacy); Catalog::migrateSystem('game-boy');
  $migrated = Catalog::rows('game-boy'); $entry = array_values($migrated)[0];
  $expect($entry['estado']==='jugable' && $entry['velocidad_porcentaje']===NULL && $entry['observaciones']==='Original note' && $entry['fecha prueba']==='2026-09-17', 'Migration lost evidence');
  $expect($archive->get('game-boy')===$legacy, 'FPS archive lost');
  Catalog::migrateSystem('game-boy');
  $expect(Catalog::rows('game-boy')===$migrated && $archive->get('game-boy')===$legacy, 'Migration is not idempotent');
  $kv->delete('game-boy'); $kv->delete('game-boy-advance');
  $expect(Catalog::import('game-boy',$rows)['new']===2 && Catalog::rows('game-boy-advance')===[], 'Cross-system contamination');
  $expect(Catalog::import('game-boy',$rows)['unchanged']===2, 'Reimport duplicated rows');
  $unsafe = $rows[$first]; $unsafe['nombre']='QA <script>alert(1)</script>'; $unsafe['observaciones']='Nota QA <img src=x onerror=alert(1)>'; $unsafe['version_nelkano']='QA <script>alert(2)</script>'; $unsafe['velocidad_porcentaje']=55.0;
  Catalog::import('game-boy',Catalog::parse($serialize([$unsafe,$rows[$second]])));
  foreach (['es','en'] as $language) {
    $html=(string)$get(Catalog::path('game-boy',$language))->getBody(); $xpath=$dom($html);
    $expect(str_contains($html,'QA &lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html,'<script>alert(1)</script>'), 'Name not escaped');
    $expect(str_contains($html,'Nota QA &lt;img') && !str_contains($html,'<img src=x'), 'Notes not escaped');
    $expect(str_contains($html,'QA &lt;script&gt;alert(2)&lt;/script&gt;'), 'Version not escaped');
    $expect($recordCount($xpath)===2 && $xpath->query('//details[@class="nk-compat-record"][@open]')->length===0, 'Compact records not initially collapsed');
    $expect($xpath->query('//details/summary/span[@class="nk-compat-game"]')->length===2, 'Missing game summaries');
    $expect($xpath->query('//details//p[@class="nk-compat-observations"]')->length===1 && $xpath->query('//dd[@class="nk-compat-version"]')->length===1, 'Empty optional metadata rendered');
    $expect($xpath->query('//select[@name="estado"]/option')->length===7, 'Filters must have all and exactly six states');
    foreach(Catalog::statuses($language) as $key=>$label){$expect(str_contains($html,'value="'.$key.'"'), 'Missing shared status filter');}
    $expect(str_contains($html,$language==='es'?'55,0 %':'55.0 %') && str_contains($html,$language==='es'?'No medida':'Not measured'),'Speed labels wrong');
    $expect(str_contains($html,'Moto g15 (ejemplo)') && str_contains($html,'2026-09-17'), 'Device/date missing');
  }
  $filtered=(string)$get(Catalog::path('game-boy').'?q=Puzzle&estado=arranque_confirmado')->getBody();
  $filteredDom=$dom($filtered);
  $filteredName=$filteredDom->query('//details/summary/span[@class="nk-compat-game"]')->item(0)?->textContent??'';
  $expect($recordCount($filteredDom)===1 && $filteredName==='Puzzle de ejemplo (ficticio)', 'Search filter failed');
  $expect(str_contains((string)$get(Catalog::path('game-boy').'?q=nomatch')->getBody(),'No hay juegos con estos filtros'),'Filtered empty state missing');
  $expect(str_contains((string)$get(Catalog::path('game-boy-advance'))->getBody(),'Todavía no hay juegos registrados'),'Empty catalog missing');
  $five=[];
  foreach($states as $status=>$label){$test=$rows[$first];$test['nombre']='QA '.$status;$test['estado']=$status;$test['fecha prueba']=$status==='no_probado'?'2030-12-31':'2026-11-04';$five[]=$test;}
  Catalog::import('game-boy-advance',Catalog::parse($serialize($five)));
  foreach(['es','en'] as $language){
    $html=(string)$get(Catalog::path('game-boy-advance',$language))->getBody();$xpath=$dom($html);
    $expect($recordCount($xpath)===6,'All six states must be visible');
    $expect(str_contains($html,$language==='es'?'6 juegos registrados':'6 recorded games') && str_contains($html,$language==='es'?'5 pruebas':'5 tests'),'Untested rows counted as tests');
    $context=$xpath->query('//div[@class="nk-compat-context"]')->item(0)?->textContent??'';
    $expect($context === '' && !str_contains($html, 'Última prueba:'), 'Global device/version/date context must be removed');
    foreach($states as $status=>$label){$filtered=(string)$get(Catalog::path('game-boy-advance',$language).'?estado='.$status)->getBody();$expect($recordCount($dom($filtered))===1&&str_contains($filtered,'data-status="'.$status.'"'),'State filter failed: '.$status);}
  }
  $many=[];for($i=1;$i<=17;$i++){$test=$rows[$first];$test['nombre']='Pagination '.$i;$many[]=$test;}
  Catalog::import('game-boy-advance',Catalog::parse($serialize($many)));
  $html=(string)$get(Catalog::path('game-boy-advance').'?q=Pagination&pagina=3')->getBody();
  $expect($recordCount($dom($html))===1 && str_contains($html,'Pagination 17')&&str_contains($html,'pagina=2'),'Eight-row pagination failed');
  $html=(string)$get(Catalog::path('game-boy-advance').'?q=Pagination&pagina=999')->getBody();$expect($recordCount($dom($html))===1,'Last-page clamping failed');
  $undated=$rows;foreach($undated as &$row){$row['fecha prueba']='';$row['velocidad_porcentaje']=0;}unset($row);Catalog::import('game-boy-advance',Catalog::parse($serialize($undated)));
  $html=(string)$get(Catalog::path('game-boy-advance'))->getBody();$expect(!str_contains($html,'Última prueba:')&&str_contains($html,'0,0 %'),'Missing dates/zero speed mishandled');
  $upload=new \Drupal\nelkano_home\Controller\CompatibilityUploadController();
  $file=new \Symfony\Component\HttpFoundation\File\UploadedFile(DRUPAL_ROOT.'/modules/custom/nelkano_home/assets/compatibilidad-ejemplo.csv','example.csv','text/csv',NULL,TRUE);
  $request=new \Symfony\Component\HttpFoundation\Request([],[],[],[],['csv'=>$file]);
  $expect($upload->upload($request,'game-boy-advance')->getStatusCode()===200,'CSV upload failed');
  $expect($upload->upload(new \Symfony\Component\HttpFoundation\Request(),'game-boy-advance')->getStatusCode()===400 && count(Catalog::rows('game-boy-advance'))===2,'Failed upload replaced data');
  // Multiple independent CSVs, atomic validation, revisions and selected-list URLs.
  $legacyRows = Catalog::rows('game-boy');
  $lists = Catalog::lists('game-boy');
  $expect(count($lists) === 1 && $lists[0]['rows'] === $legacyRows, 'Legacy catalog not preserved as one list');
  $revision = Catalog::revision($lists);
  $definitions = [['id'=>'default','title'=>'Game Boy'],['id'=>'color','title'=>'Game Boy Color <script>bad()</script>']];
  $colorRows=[];for($i=1;$i<=17;$i++){$row=$rows[$first];$row['nombre']='Color QA '.$i;$colorRows[]=$row;}
  $colorRows=Catalog::parse($serialize($colorRows));
  $saved=Catalog::saveLists('game-boy',$definitions,['color'=>['filename'=>'color.csv','rows'=>$colorRows]],$revision);
  $expect(count($saved['lists'])===2&&$saved['lists'][1]['count']===17,'Second CSV not stored');
  $expect(Catalog::rows('game-boy','default')===$legacyRows&&Catalog::rows('game-boy','color')===$colorRows,'Lists merged or original rows lost');
  $expect($kv->get('game-boy')===$legacyRows,'Original legacy collection was overwritten');
  foreach(['es','en'] as $language){
    $html=(string)$get(Catalog::path('game-boy',$language).'?lista=color&q=Color&pagina=2')->getBody();$xpath=$dom($html);
    $expect($xpath->query('//nav[@class="nk-compat-tabs"]/a')->length===2,'Missing console tabs');
    $expect($xpath->query('//nav[@class="nk-compat-tabs"]/a[@aria-current="page"]')->length===1&&str_contains($html,'Game Boy Color &lt;script&gt;bad()&lt;/script&gt;'),'Tab title not escaped or selected');
    $expect($recordCount($xpath)===8&&$xpath->query('//input[@type="hidden"][@name="lista"][@value="color"]')->length===1,'List selection lost in filters');
    $next=$xpath->query('//nav[@class="nk-compat-pagination"]//a[@rel="next"]')->item(0)?->getAttribute('href')??'';
    $clear=$xpath->query('//a[@class="nk-compat-clear"]')->item(0)?->getAttribute('href')??'';
    $expect(str_contains($next,'lista=color')&&str_contains($next,'pagina=3')&&str_contains($next,'q=Color'),'Pagination lost selected list or filters');
    $expect(str_contains($clear,'lista=color')&&!str_contains($clear,'q='),'Clear filter lost selected list');
    $expect(!str_contains($html,'nk-compat-context'),'Global metadata still visible');
    $expect(str_contains((string)$get(Catalog::path('game-boy',$language).'?lista=unknown')->getBody(),'QA &lt;script&gt;alert(1)&lt;/script&gt;'),'Unknown list did not fall back to first');
  }
  try{Catalog::saveLists('game-boy',$definitions,[],$revision);$stale=FALSE;}catch(RuntimeException $e){$stale=TRUE;}
  $expect($stale&&Catalog::lists('game-boy')[1]['rows']===$colorRows,'Stale editor overwrote another import');
  foreach([
    [['id'=>'default','title'=>''],['id'=>'color','title'=>'Color']],
    [['id'=>'default','title'=>'GB'],['id'=>'default','title'=>'Duplicate']],
    [['id'=>'missing','title'=>'No file']],
    [],
  ] as $invalidDefinitions){try{Catalog::saveLists('game-boy',$invalidDefinitions,[],$saved['revision']);$invalid=FALSE;}catch(InvalidArgumentException $e){$invalid=TRUE;}$expect($invalid&&Catalog::revision(Catalog::lists('game-boy'))===$saved['revision'],'Invalid definitions changed stored data');}
  $beforeGba=Catalog::rows('game-boy-advance');
  $makeUpload=static function(array $defs,string $rev,array $files): \Symfony\Component\HttpFoundation\Request {return new \Symfony\Component\HttpFoundation\Request([],['lists'=>json_encode($defs),'revision'=>$rev],[],[],['csvs'=>$files]);};
  $validFile=static fn()=>new \Symfony\Component\HttpFoundation\File\UploadedFile(DRUPAL_ROOT.'/modules/custom/nelkano_home/assets/compatibilidad-ejemplo.csv','example.csv','text/csv',NULL,TRUE);
  $badFile=new \Symfony\Component\HttpFoundation\File\UploadedFile(__FILE__,'bad.csv','text/csv',NULL,TRUE);
  $failed=$upload->upload($makeUpload($definitions,$saved['revision'],['default'=>$validFile(),'color'=>$badFile]),'game-boy');
  $expect($failed->getStatusCode()===400&&Catalog::revision(Catalog::lists('game-boy'))===$saved['revision'],'Invalid second CSV partially replaced first');
  $reordered=[['id'=>'color','title'=>'Game Boy Color'],['id'=>'default','title'=>'Game Boy']];
  $response=$upload->upload($makeUpload($reordered,$saved['revision'],[]),'game-boy');
  $expect($response->getStatusCode()===200&&Catalog::lists('game-boy')[0]['id']==='color','Reorder/rename failed');
  $html=(string)$get(Catalog::path('game-boy'))->getBody();$expect(str_contains($html,'Color QA 1')&&!str_contains($html,'QA &lt;script&gt;alert(1)'),'Default list order ignored');
  $expect($upload->upload($makeUpload($definitions,$saved['revision'],[]),'game-boy')->getStatusCode()===409,'Stale API request not rejected');
  $response=$upload->upload($makeUpload([['id'=>'color','title'=>'GBC']],Catalog::revision(Catalog::lists('game-boy')),['color'=>$validFile()]),'game-boy');
  $expect($response->getStatusCode()===200&&count(Catalog::lists('game-boy'))===1&&count(Catalog::rows('game-boy','color'))===2,'CSV replacement or list deletion failed');
  $html=(string)$get(Catalog::path('game-boy'))->getBody();$expect(!str_contains($html,'class="nk-compat-tabs"'),'Single CSV should not show tabs');
  $expect(Catalog::rows('game-boy-advance')===$beforeGba,'Editing lists changed another system');
  // Render the actual editor with its escaped initial data and existing CSRF route.
  $switcher=\Drupal::service('account_switcher');$switcher->switchTo(\Drupal\user\Entity\User::load(1));
  $request=\Drupal::request();$previousSystem=$request->query->get('system');$request->query->set('system','game-boy');
  try{
    $form=\Drupal::formBuilder()->getForm(\Drupal\nelkano_home\Form\SystemSettingsForm::class);
    $formHtml=(string)\Drupal::service('renderer')->renderRoot($form);
    $xpath=$dom($formHtml);
    $editor=$xpath->query('//*[@data-nk-catalog-editor]')->item(0);
    $expect($editor!==NULL&&$editor->getAttribute('data-csrf-token')!==''&&$editor->getAttribute('data-revision')===Catalog::revision(Catalog::lists('game-boy')),'Admin editor missing snapshot or CSRF');
    $expect(json_decode($editor->getAttribute('data-lists'),TRUE)[0]['title']==='GBC','Admin list title not loaded');
  }finally{$previousSystem===NULL?$request->query->remove('system'):$request->query->set('system',$previousSystem);$switcher->switchBack();}
  $anonymous=$get('/admin/nelkano/sistemas/game-boy/csv');$expect($anonymous->getStatusCode()!==200,'Anonymous catalog endpoint accessible');
  $route=\Drupal::service('router.route_provider')->getRouteByName('nelkano_home.upload_compatibility');$expect($route->getRequirement('_csrf_request_header_token')==='TRUE'&&$route->getMethods()===['POST'],'Upload CSRF protection lost');
  foreach(['es','en'] as $language){foreach(SystemPages::content($language)['pages'] as $system=>$definition){$response=$get(Catalog::path($system,$language));$expect($response->getStatusCode()===($definition['enabled']?200:404),'System route changed: '.$system);if($definition['enabled']){$expect(str_contains((string)$response->getBody(),'nk-compat-filters'),'Shared layout missing for '.$system);}}}
  $expect($get('/sistemas/no-core/compatibilidad')->getStatusCode()===404,'Unknown core accessible');
  $expect($get('/admin/nelkano/sistemas/game-boy/compatibilidad')->getStatusCode()!==200,'Anonymous upload accessible');
  echo "PASS: $checks compatibility checks.\n";
}finally{
  foreach(array_reverse(array_keys($communitySnapshot)) as $table)\Drupal::database()->delete($table)->execute();
  foreach($communitySnapshot as $table=>$entries)foreach($entries as $entry)\Drupal::database()->insert($table)->fields($entry)->execute();
  $communityIndexStore->deleteAll();$communityIndexStore->setMultiple($communityIndex);
  foreach($originalLists as $system=>$data){$data===NULL?$listStore->delete($system):$listStore->set($system,$data);}
  foreach($original as $system=>$data){$data===NULL?$kv->delete($system):$kv->set($system,$data);}
  $archiveOriginal===NULL?$archive->delete('game-boy'):$archive->set('game-boy',$archiveOriginal);
}
