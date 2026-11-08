<?php
/** Verify the minimal wrapper without running Composer/Drush against a website. */
foreach(['ok','composer-fails','drush-fails'] as $mode) {
  $base=sys_get_temp_dir().'/nelkano-minimal-deploy-'.bin2hex(random_bytes(8));mkdir($base.'/scripts',0700,true);mkdir($base.'/bin');mkdir($base.'/vendor/bin',0700,true);
  try {
    copy(__DIR__.'/deploy-hostinger.sh',$base.'/scripts/deploy-hostinger.sh');
    file_put_contents($base.'/bin/composer',"#!/bin/sh\necho composer >> \"\$TRACE\"\n[ \"\$MODE\" != composer-fails ]\n");chmod($base.'/bin/composer',0700);
    file_put_contents($base.'/vendor/bin/drush',"#!/bin/sh\necho drush-deploy >> \"\$TRACE\"\n[ \"\$MODE\" != drush-fails ]\n");chmod($base.'/vendor/bin/drush',0700);
    $env=array_merge(getenv(),['PATH'=>$base.'/bin:'.getenv('PATH'),'TRACE'=>$base.'/trace','MODE'=>$mode]);
    $p=proc_open(['bash',$base.'/scripts/deploy-hostinger.sh'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    fclose($pipes[0]);stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    $expected=$mode==='composer-fails'?['composer']:['composer','drush-deploy'];
    if(file($base.'/trace',FILE_IGNORE_NEW_LINES)!==$expected || (($exit===0)!==($mode==='ok')))throw new RuntimeException('Unexpected deployment commands: '.$mode.' '.$error);
    echo 'PASS: '.$mode."; only Composer and drush deploy.\n";
  } finally {
    if(!str_starts_with($base,sys_get_temp_dir().'/nelkano-minimal-deploy-')||is_link($base))throw new RuntimeException('Unsafe fixture path');
    $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($entries as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($base);
  }
}
