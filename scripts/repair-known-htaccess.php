<?php
/** Repair only the reviewed legacy omission; unknown or staged edits are untouched. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=realpath($argv[1]??dirname(__DIR__));
if (!$root) throw new RuntimeException('Repository not found');
$git=static function(array $args) use($root): string {
  $p=proc_open(array_merge(['git','--no-optional-locks','-C',$root],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if (!is_resource($p)) throw new RuntimeException('Cannot inspect canonical htaccess');
  fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  if(proc_close($p)!==0) throw new RuntimeException('Git inspection failed: '.$error);
  return $out;
};
if(trim($git(['diff','--cached','--name-only']))!=='') throw new RuntimeException('Index contains edits; no repair performed');
$file=$root.'/public_html/.htaccess';
if(!is_file($file)||is_link($file)) throw new RuntimeException('Missing or linked htaccess; no repair performed');
$canonical=$git(['show','HEAD:public_html/.htaccess']);$actual=file_get_contents($file);
$normalize=static fn(string $s):string=>str_replace("\r\n","\n",$s);
if($normalize($actual)===$normalize($canonical)) return;
$block=<<<'RULES'
  # Legacy streaming buffers must never be served as public static files.
  RewriteRule ^sites/[^/]+/files/nelkano-stream(?:/|$) - [F,L,NC]
  # All release binaries use the latest-only download controllers.
  RewriteRule ^sites/[^/]+/files/(?:nelkano-releases|app-updates)(?:/|$) - [F,L,NC]
  RewriteRule ^modules/custom/nelkano_home/emulator(?:/|$) - [F,L,NC]
RULES;
$block.="\n";$canonicalNormalized=$normalize($canonical);
if(substr_count($canonicalNormalized,$block)!==1 || $normalize($actual)!==str_replace($block,'',$canonicalNormalized)) {
  throw new RuntimeException('Unrecognized htaccess edits preserved; review git diff -- public_html/.htaccess');
}
$directory=$root.'/private/deploy-recovery';
if(is_link($root.'/private')||is_link($directory)) throw new RuntimeException('Linked recovery directory refused');
if(!is_dir($directory)&&!mkdir($directory,0700,true)) throw new RuntimeException('Cannot preserve legacy htaccess');
$hash=hash('sha256',$actual);$backup=$directory.'/htaccess-'.$hash.'.txt';
if(!file_exists($backup)) {
  $handle=fopen($backup,'x');if(!$handle) throw new RuntimeException('Cannot create recovery copy');
  chmod($backup,0600);$written=fwrite($handle,$actual);fclose($handle);
  if($written!==strlen($actual)) throw new RuntimeException('Incomplete recovery copy');
}
if(!hash_equals($hash,hash_file('sha256',$backup))||!hash_equals($hash,hash_file('sha256',$file))) throw new RuntimeException('Recovery/source hash mismatch');
$tmp=tempnam(dirname($file),'.htaccess-nelkano-');
try {
  if(file_put_contents($tmp,$canonical)!==strlen($canonical)||!chmod($tmp,fileperms($file)&0777)||!rename($tmp,$file)) throw new RuntimeException('Cannot restore reviewed security rules');
} finally {if(is_file($tmp)) unlink($tmp);}
echo "Restored the five reviewed htaccess protection lines; previous bytes preserved in private/deploy-recovery.\n";
