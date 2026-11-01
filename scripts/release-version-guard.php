<?php
/** Android, Windows Desktop and Studio are one numbered product release. */
function nelkanoAssertReleaseVersions(array $rows,callable $newest): void {
  $identity=null;
  foreach(['android','windows','sdk'] as $platform){
    $row=$newest($rows,$platform);
    if(!$row)throw new RuntimeException('Missing published product release: '.$platform);
    $current=[(string)$row['version'],(int)($row['version_code']??0)];
    if($current[1]<1 || ($identity!==null && $current!==$identity))throw new RuntimeException('Android, Desktop and SDK must publish the same version and version code; mismatch at '.$platform);
    $identity=$current;
  }
}
