<?php
/** Local-only MySQL idle-timeout and release file integrity regression. */
if(getenv('DRUPAL_DB_HOST')!=='database')throw new RuntimeException('Local Docker only');
require_once __DIR__.'/release-file-io.php';
$options=\Drupal::database()->getConnectionOptions();$driver=get_class(\Drupal::database());
$connect=static function()use($driver,$options):PDO{$o=$options;$pdo=$driver::open($o);$pdo->exec('SET SESSION wait_timeout=2');return $pdo;};
$expired=$connect();usleep(3000000);$rejected=false;
try{$expired->query('SELECT 1');}catch(PDOException $e){$rejected=($e->errorInfo[1]??0)===2006;}
if(!$rejected)throw new RuntimeException('Did not reproduce MySQL idle timeout');
echo "PASS: control reproduces MySQL 2006 after idle timeout\n";
$root=sys_get_temp_dir().'/nelkano-release-io-'.bin2hex(random_bytes(8));mkdir($root,0700);$source=$root.'/source';$dest=$root.'/copy';
try{
 file_put_contents($source,str_repeat('release-fixture-',600000));$pdo=$connect();$pulses=0;
 $io=new NelkanoReleaseFileIo(static function()use($pdo,&$pulses){usleep(300000);$pdo->query('SELECT 1')->fetchColumn();$pulses++;});
 $hash=$io->digest($source);if($hash!==hash_file('sha256',$source)||$pulses<3)throw new RuntimeException('Hash/progress mismatch');
 $io->copy($source,$dest);if(hash_file('sha256',$dest)!==$hash||$pdo->query('SELECT 1')->fetchColumn()!=1)throw new RuntimeException('Copy or MySQL keepalive failed');
 echo "PASS: slow streamed hashes/copies preserve bytes and MySQL connection\n";
 $calls=0;$failing=new NelkanoReleaseFileIo(static function()use(&$calls){if(++$calls===2)throw new RuntimeException('fixture interrupted');});
 try{$failing->copy($source,$root.'/interrupted');throw new RuntimeException('Interruption was ignored');}catch(RuntimeException $e){if($e->getMessage()!=='fixture interrupted')throw $e;}
 if(file_exists($root.'/interrupted'))throw new RuntimeException('Partial copy retained');
 echo "PASS: interrupted copy removes only its partial output\n";
}finally{foreach(glob($root.'/*') as $path)unlink($path);rmdir($root);}
