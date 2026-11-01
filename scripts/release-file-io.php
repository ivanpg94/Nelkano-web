<?php
/** Bounded file I/O with progress callbacks; no credentials or database dependency. */
final class NelkanoReleaseFileIo {
  private Closure $progress;
  public function __construct(callable $progress) { $this->progress=Closure::fromCallable($progress); }
  public function digest(string $path): string {
    $input=fopen($path,'rb');if(!$input) throw new RuntimeException('Cannot read release artifact');
    $hash=hash_init('sha256');
    try { while(!feof($input)) { ($this->progress)();$chunk=fread($input,1048576);if($chunk===false)throw new RuntimeException('Cannot hash release artifact');hash_update($hash,$chunk); } }
    finally { fclose($input); }
    return hash_final($hash);
  }
  public function copy(string $source,string $destination): bool {
    $input=fopen($source,'rb');if(!$input)throw new RuntimeException('Cannot read release copy source');
    $output=null;$created=false;$complete=false;
    try {
      $output=fopen($destination,'xb');if(!$output)throw new RuntimeException('Release destination already exists or cannot be created');$created=true;
      while(!feof($input)) {
        ($this->progress)();$chunk=fread($input,1048576);if($chunk===false)throw new RuntimeException('Release copy read failed');
        for($offset=0;$offset<strlen($chunk);){$written=fwrite($output,substr($chunk,$offset));if(!$written)throw new RuntimeException('Release copy write failed');$offset+=$written;}
      }
      if(!fflush($output))throw new RuntimeException('Release copy flush failed');$complete=true;return true;
    } finally { fclose($input);if(is_resource($output))fclose($output);if($created&&!$complete&&is_file($destination))unlink($destination); }
  }
}
