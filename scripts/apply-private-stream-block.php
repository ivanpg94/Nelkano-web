<?php
// Local content-only deployment helper: do not replace the running .htaccess.
if (getenv('DRUPAL_DB_HOST') !== 'database') throw new RuntimeException('Local Docker only.');
$path = '/opt/drupal/web/.htaccess';
$text = file_get_contents($path);
$rules = [
  '  RewriteRule ^sites/[^/]+/files/nelkano-stream(?:/|$) - [F,L,NC]',
  '  RewriteRule ^sites/[^/]+/files/(?:nelkano-releases|app-updates)(?:/|$) - [F,L,NC]',
  '  RewriteRule ^modules/custom/nelkano_home/emulator(?:/|$) - [F,L,NC]',
];
$missing=array_filter($rules,static fn(string $rule):bool=>!str_contains($text,trim($rule)));
if(!$missing){echo "already applied\n";return;}
$count=0;$block=implode("\n",$missing);
$next=preg_replace_callback('/^([ \t]*RewriteEngine[ \t]+on[ \t]*\r?$)/mi',static fn($m)=>$m[1]."\n".$block,$text,1,$count);
if($count!==1 || $next===NULL)throw new RuntimeException('No unique insertion point.');
if(file_get_contents($path)!==$text || file_put_contents($path,$next,LOCK_EX)!==strlen($next))throw new RuntimeException('Could not update local access rules.');
echo "private streams and controlled release downloads protected locally\n";
