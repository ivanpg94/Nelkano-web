<?php
declare(strict_types=1);
require (is_dir(__DIR__ . '/../public_html') ? __DIR__ . '/../public_html' : __DIR__ . '/../web') . '/modules/custom/nelkano_home/src/Service/SocialPresence.php';
use Drupal\nelkano_home\Service\SocialPresence;
class PresenceMemory { public array $data=[]; public function get($key,$default=[]) {return $this->data[$key]??$default;} public function setWithExpire($key,$value,$ttl){$this->data[$key]=$value;} public function delete($key){unset($this->data[$key]);} }
class PresencePreferences { public array $data=[]; public function get($module,$uid,$key){return $this->data[$uid]??NULL;} public function set($module,$uid,$key,$value){$this->data[$uid]=$value;} }
function check($value,$label){if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
$store=new PresenceMemory();$prefs=new PresencePreferences();$social=new SocialPresence($store,$prefs,1000);
$payload=['clientId'=>'device_1234','online'=>TRUE,'gameTitle'=>'Pokémon Cristal','systemId'=>'gbc'];
$social->update(1,$payload);check(!$social->describe(1)['online'],'sharing is disabled by default');
$social->setSharing(1,TRUE);$social->update(1,$payload);check($social->describe(1)['gameTitle']==='Pokémon Cristal','accepted friend receives public game title');
check(!$social->describe(2)['online'],'other accounts remain isolated');
$expired=new SocialPresence($store,$prefs,1091);check(!$expired->describe(1)['online'],'presence expires without another heartbeat');
$social->update(1,[...$payload,'clientId'=>'device_5678']);$social->update(1,['clientId'=>'device_1234','online'=>FALSE]);check($social->describe(1)['online'],'one device logout keeps another connected device');
$social->setSharing(1,FALSE);check(!$social->describe(1)['online']&&!$store->data,'disabling privacy setting clears activity immediately');
foreach ([['online'=>'true','clientId'=>'device_1234'],['online'=>TRUE,'clientId'=>'!'],[...$payload,'gameTitle'=>str_repeat('a',129)]] as $bad){$rejected=FALSE;try{$social->update(1,$bad);}catch(InvalidArgumentException){$rejected=TRUE;}check($rejected,'invalid activity rejected');}
echo "Social presence: 9 checks passed\n";
