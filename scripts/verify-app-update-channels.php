<?php
/** Refuse a deployment whose published applications cannot offer their updates. */
use Drupal\nelkano_home\Controller\AppUpdatesController;
use Drupal\nelkano_home\Service\ReleaseArtifact;
$rows=\Drupal::config('nelkano_home.docs')->get('es.releases_items')??[];
$controller=AppUpdatesController::create(\Drupal::getContainer());
$checked=[];
foreach(ReleaseArtifact::PLATFORMS as $platform=>$definition){
  $current=ReleaseArtifact::newest($rows,$platform);if(!$current)continue;
  $response=json_decode($controller->latest($definition[2])->getContent(),TRUE,512,JSON_THROW_ON_ERROR);
  $latest=$response['latest']??NULL;
  if(!is_array($latest)||$latest['version']!==$current['version']||(int)$latest['version_code']!==(int)$current['version_code']||$latest['format']!==AppUpdatesController::PLATFORMS[$definition[2]][1]||empty($latest['download_url'])||$latest['bytes']<1||!preg_match('/^[a-f0-9]{64}$/D',$latest['sha256']))throw new RuntimeException('Published update channel is unavailable: '.$definition[2]);
  $checked[]=['platform'=>$definition[2],'version'=>$latest['version'],'code'=>$latest['version_code'],'format'=>$latest['format']];
}
echo json_encode(['updateChannelsVerified'=>$checked],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
