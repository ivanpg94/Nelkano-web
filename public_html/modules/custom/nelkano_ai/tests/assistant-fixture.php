<?php
/** Synthetic, deterministic pixels, never a user's game or screenshot. */
$chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$width=160;$height=120;$raw='';
for($y=0;$y<$height;$y++){$row='';for($x=0;strlen($row)<$width*3;$x++)$row.=hash('sha256',"$y:$x",TRUE);$raw.="\0".substr($row,0,$width*3);}
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).$chunk('IDAT',gzcompress($raw)).$chunk('IEND','');
return ['identity'=>['gameId'=>'rocket','title'=>'Pokemon Team Rocket','systemId'=>'gba','romSha256'=>str_repeat('a',64),'platform'=>'android','device'=>'QA','appVersion'=>'4.0.2'],'memory'=>['edition'=>'DragonDev 2.0','location'=>'Azulona','goal'=>'Una pista','spoilers'=>'none'],'screen'=>['mimeType'=>'image/png','width'=>$width,'height'=>$height,'dataBase64'=>base64_encode($png),'capturedAt'=>time()*1000]];
