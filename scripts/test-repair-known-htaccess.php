<?php
/** Real local Git fixtures: exact omission repaired, unknown/staged content preserved. */
$script=__DIR__.'/repair-known-htaccess.php';$canonical=file_get_contents(dirname(__DIR__).'/public_html/.htaccess');
$missing=preg_replace('/^  # Legacy streaming buffers[^\n]*\n  RewriteRule [^\n]*\n  # All release binaries[^\n]*\n  RewriteRule [^\n]*\n  RewriteRule [^\n]*\n/m','',str_replace("\r\n","\n",$canonical));
if($missing===$canonical)throw new RuntimeException('Fixture did not remove protection block');
$run=static function(array $cmd,string $cwd):array{$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out.$err];};
foreach(['clean','legacy','unknown','staged'] as $mode){
 $root=sys_get_temp_dir().'/nelkano-htaccess-'.bin2hex(random_bytes(8));mkdir($root.'/public_html',0700,true);
 try{
  file_put_contents($root.'/public_html/.htaccess',$canonical);file_put_contents($root.'/.gitignore',"/private/\n");
  foreach([['git','init','-q'],['git','config','user.name','Fixture'],['git','config','user.email','fixture@example.invalid'],['git','add','.'],['git','commit','-qm','canonical']] as $cmd){[$code,$out]=$run($cmd,$root);if($code)throw new RuntimeException($out);}
  $actual=$mode==='legacy'||$mode==='staged'?$missing:($mode==='unknown'?$missing."# hosting rule to preserve\n":$canonical);
  file_put_contents($root.'/public_html/.htaccess',$actual);
  if($mode==='staged')$run(['git','add','public_html/.htaccess'],$root);
  [$code,$out]=$run([PHP_BINARY,$script,$root],$root);
  $expectSuccess=in_array($mode,['clean','legacy'],true);
  if(($code===0)!==$expectSuccess)throw new RuntimeException($mode.': '.$out);
  if(file_get_contents($root.'/public_html/.htaccess')!==($expectSuccess?$canonical:$actual))throw new RuntimeException('Wrong file contents: '.$mode);
  $backups=glob($root.'/private/deploy-recovery/*.txt');
  if($mode==='legacy'){
   if(count($backups)!==1||file_get_contents($backups[0])!==$actual)throw new RuntimeException('Previous bytes not preserved');
   [$code,$out]=$run(['git','status','--porcelain'],$root);if($code||trim($out)!=='')throw new RuntimeException('Repair did not leave a clean checkout');
   [$code,$out]=$run([PHP_BINARY,$script,$root],$root);if($code||count(glob($root.'/private/deploy-recovery/*.txt'))!==1)throw new RuntimeException('Repair is not idempotent');
  }elseif($backups)throw new RuntimeException('Unexpected backup');
  echo 'PASS: htaccess '.$mode."\n";
 }finally{
  if(!str_starts_with($root,sys_get_temp_dir().'/nelkano-htaccess-')||is_link($root))throw new RuntimeException('Unsafe fixture cleanup');
  foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);
 }
}
echo "SUCCESS: four actual Git htaccess scenarios.\n";
