<?php
require_once __DIR__.'/release-version-guard.php';
$newest=static function($rows,$platform){foreach(array_reverse($rows)as$row)if($row['platform']===$platform&&($row['visible']??true))return $row;return null;};
$rows=array_map(static fn($p)=>['platform'=>$p,'version'=>'3.0.6','version_code'=>16],['android','windows','sdk']);
nelkanoAssertReleaseVersions($rows,$newest);echo "PASS: coherent three-platform release\n";
foreach(['version','version_code','missing'] as $field){$bad=$rows;if($field==='missing')array_pop($bad);else $bad[2][$field]=$field==='version'?'3.0.5':15;$rejected=false;try{nelkanoAssertReleaseVersions($bad,$newest);}catch(RuntimeException $e){$rejected=true;}if(!$rejected)throw new RuntimeException('Mismatch accepted: '.$field);echo 'PASS: rejected '.$field." mismatch\n";}
