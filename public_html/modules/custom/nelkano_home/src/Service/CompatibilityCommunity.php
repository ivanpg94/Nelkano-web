<?php

namespace Drupal\nelkano_home\Service;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Permanent game identities and account-owned experiences, independent of CSV batches. */
final class CompatibilityCommunity {
  public const GAMES = 'nelkano_compat_game';
  public const VARIANTS = 'nelkano_compat_variant';
  public const RATINGS = 'nelkano_compat_rating';
  private const SYSTEMS = ['chip8'=>'chip-8','gb'=>'game-boy','gbc'=>'game-boy','gba'=>'game-boy-advance','nes'=>'nes','famicom'=>'nes','sms'=>'master-system','gg'=>'master-system','ngp'=>'neo-geo-pocket','ngpc'=>'neo-geo-pocket','md'=>'mega-drive','nds'=>'nintendo-ds','snes'=>'snes','ps1'=>'playstation','psp'=>'playstation-portable','3ds'=>'nintendo-3ds','gamecube'=>'nintendo-gamecube','rpgmaker'=>'rpg-maker','switch'=>'nintendo-switch'];

  public static function schema(): array {
    $str = static fn($length) => ['type'=>'varchar','length'=>$length,'not null'=>TRUE,'default'=>''];
    $int = ['type'=>'int','unsigned'=>TRUE,'not null'=>TRUE,'default'=>0];
    return [
      self::GAMES => ['description'=>'Stable compatibility games','fields'=>[
        'id'=>$str(64),'page_id'=>$str(64),'system_id'=>$str(32),'machine_id'=>$str(80),'title'=>$str(255),'verified'=>$int,'created'=>$int,
      ],'primary key'=>['id'],'indexes'=>['page'=>['page_id']]],
      self::VARIANTS => ['description'=>'Content fingerprints and reviewed game associations','fields'=>[
        'id'=>$str(64),'game_id'=>$str(64),'page_id'=>$str(64),'system_id'=>$str(32),'rom_sha256'=>$str(64),'verified'=>$int,
      ],'primary key'=>['id'],'indexes'=>['game'=>['game_id'],'page'=>['page_id']]],
      self::RATINGS => ['description'=>'One editable experience per account, variant, app version and platform','fields'=>[
        'id'=>$str(64),'uid'=>$int,'game_id'=>$str(64),'variant_id'=>$str(64),'version'=>$str(64),'platform'=>$str(16),'device'=>$str(128),
        'result'=>$str(16),'issues'=>$int,'comment'=>['type'=>'text','size'=>'normal','not null'=>TRUE],
        'visible'=>['type'=>'int','unsigned'=>TRUE,'not null'=>TRUE,'default'=>1],'revision'=>$int,'updated'=>$int,
      ],'primary key'=>['id'],'indexes'=>['game'=>['game_id','visible'],'account'=>['uid'],'variant'=>['variant_id']]],
    ];
  }

  public static function ready(): bool { return \Drupal::database()->schema()->tableExists(self::RATINGS); }
  public static function pageId(string $system): string {
    if (!isset(self::SYSTEMS[$system])) throw new HttpException(400, 'unsupported-system');
    return self::SYSTEMS[$system];
  }
  public static function machine(string $title): string {
    $ascii = \Drupal::service('transliteration')->transliterate($title, 'en');
    return trim(substr(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii)), 0, 80), '-') ?: 'game';
  }
  private static function gameKey(string $page, string $system, string $machine): string { return hash('sha256', "$page\n$system\n$machine"); }
  private static function defaultSystem(string $page): string { return array_search($page, self::SYSTEMS, TRUE) ?: $page; }
  public static function rowKey(string $page, array $row): string {
    return self::gameKey($page, $row['sistema_id'] ?: self::defaultSystem($page), $row['juego_id'] ?: self::machine($row['nombre']));
  }
  private static function locked(callable $work): mixed {
    $lock=\Drupal::lock(); $name='nelkano_compatibility_community';
    if (!$lock->acquire($name, 60)) throw new HttpException(409, 'compatibility-busy');
    try {
      $transaction=\Drupal::database()->startTransaction();
      try { $result=$work(); unset($transaction); return $result; }
      catch(\Throwable $e){$transaction->rollBack();throw $e;}
    }
    finally { $lock->release($name); }
  }

  /** Called inside the same transaction/lock as publishing the corresponding CSVs. */
  public static function indexLists(string $page, array $lists): void {
    if (!self::ready()) return;
    $index=\Drupal::keyValue('nelkano_home.compatibility_index');
    $revision=CompatibilityCatalog::revision($lists);
    if($index->get($page)===$revision)return;
    $db=\Drupal::database();
    foreach ($lists as $list) foreach ($list['rows'] as $row) {
      $row += ['sistema_id'=>'','juego_id'=>'','rom_sha256'=>''];
      $system=$row['sistema_id'] ?: self::defaultSystem($page);
      if ($row['sistema_id'] !== '' && self::pageId($system) !== $page) throw new \InvalidArgumentException('El sistema_id del CSV no pertenece a esta página.');
      $machine=$row['juego_id'] ?: self::machine($row['nombre']);
      $id=self::rowKey($page,$row);
      $db->merge(self::GAMES)->insertFields(['created'=>time()])->key('id',$id)->fields(['page_id'=>$page,'system_id'=>$system,'machine_id'=>$machine,'title'=>$row['nombre'],'verified'=>1])->execute();
      if ($row['rom_sha256'] !== '') {
        $variant=hash('sha256', "$page\n".$row['rom_sha256']);
        $existing=$db->select(self::VARIANTS,'v')->fields('v')->condition('id',$variant)->execute()->fetchAssoc();
        if ($existing && $existing['verified'] && $existing['game_id'] !== $id) throw new \InvalidArgumentException('Una huella ROM ya está asociada a otro juego. Conserva su juego_id.');
        $db->merge(self::VARIANTS)->key('id',$variant)->fields(['game_id'=>$id,'page_id'=>$page,'system_id'=>$system,'rom_sha256'=>$row['rom_sha256'],'verified'=>1])->execute();
        // Only a trusted CSV or explicit moderator can establish a global association.
        $db->update(self::RATINGS)->fields(['game_id'=>$id])->condition('variant_id',$variant)->execute();
      }
    }
    $index->set($page,$revision);
  }
  public static function publishLists(string $page,array $lists,callable $publish): mixed {
    return self::locked(function() use($page,$lists,$publish) {self::indexLists($page,$lists);return $publish();});
  }
  private static function identity(array $input): array {
    foreach (['system_id'=>32,'rom_sha256'=>64,'title'=>255,'version'=>64,'platform'=>16] as $key=>$limit)
      if (!is_string($input[$key]??NULL) || trim($input[$key])==='' || mb_strlen($input[$key])>$limit) throw new HttpException(400,'invalid-'.$key);
    if (!preg_match('/^[a-f0-9]{64}$/D',$input['rom_sha256'])) throw new HttpException(400,'invalid-rom-sha256');
    if (!in_array($input['platform'],['android','windows','steamos'],TRUE)) throw new HttpException(400,'invalid-platform');
    $page=self::pageId($input['system_id']);
    if (empty(SystemPages::content('es')['pages'][$page]['enabled'])) throw new HttpException(404,'system-unpublished');
    return $input+['page_id'=>$page];
  }
  private static function variantId(array $identity): string { return hash('sha256',self::pageId($identity['system_id'])."\n".$identity['rom_sha256']); }
  private static function ratingId(int $uid,array $identity): string { return hash('sha256', "$uid\n".self::variantId($identity)."\n".$identity['version']."\n".$identity['platform']); }
  private static function game(string $id): ?array {
    return \Drupal::database()->select(self::GAMES,'g')->fields('g')->condition('id',$id)->execute()->fetchAssoc() ?: NULL;
  }
  public static function resolve(int $uid,array $input): array {
    $identity=self::identity($input);
    return self::locked(function() use($uid,$identity) {
      $page=$identity['page_id'];self::indexLists($page,CompatibilityCatalog::lists($page));
      $db=\Drupal::database();$variant=$db->select(self::VARIANTS,'v')->fields('v')->condition('id',self::variantId($identity))->execute()->fetchAssoc();
      $rating=$db->select(self::RATINGS,'r')->fields('r')->condition('id',self::ratingId($uid,$identity))->condition('uid',$uid)->execute()->fetchAssoc();
      $matched=$variant && $variant['verified'] ? self::game($variant['game_id']) : NULL;
      $candidates=[];
      if (!$matched) {
        $query=$db->select(self::GAMES,'g')->fields('g',['id','title'])->condition('page_id',$page)->condition('verified',1);
        $words=array_filter(explode('-',self::machine($identity['title'])),static fn($word)=>strlen($word)>2);
        if ($words) { $or=$query->orConditionGroup();foreach(array_slice($words,0,6) as $word)$or->condition('title','%'.$db->escapeLike($word).'%','LIKE');$query->condition($or); }
        $candidates=$query->orderBy('title')->range(0,20)->execute()->fetchAll(\PDO::FETCH_ASSOC);
      }
      return ['matched'=>$matched?['id'=>$matched['id'],'title'=>$matched['title']]:NULL,'candidates'=>$candidates,
        'rating'=>$rating?array_intersect_key($rating,array_flip(['result','issues','comment','device','revision','game_id'])):NULL];
    });
  }
  public static function rate(int $uid,array $input): array {
    $identity=self::identity($input);
    if (!in_array($input['result']??NULL,['completed','playing','blocked'],TRUE) || !is_bool($input['issues']??NULL)
      || !is_string($input['comment']??NULL) || mb_strlen($input['comment'])>2000
      || !is_string($input['device']??NULL) || mb_strlen($input['device'])>128
      || !is_int($input['revision']??NULL) || $input['revision']<0) throw new HttpException(400,'invalid-experience');
    return self::locked(function() use($uid,$identity,$input) {
      $db=\Drupal::database();$page=$identity['page_id'];self::indexLists($page,CompatibilityCatalog::lists($page));
      $variantId=self::variantId($identity);$id=self::ratingId($uid,$identity);
      $current=$db->select(self::RATINGS,'r')->fields('r')->condition('id',$id)->execute()->fetchAssoc();
      $variant=$db->select(self::VARIANTS,'v')->fields('v')->condition('id',$variantId)->execute()->fetchAssoc();
      // A repeated identical write is safe after a lost HTTP response.
      if ($current && (int)$current['revision'] === $input['revision']+1 && $current['result']===$input['result'] && (bool)$current['issues']===$input['issues'] && $current['comment']===$input['comment'] && $current['device']===$input['device'] && (empty($input['game_id']) || $input['game_id']===$current['game_id'])) return ['saved'=>TRUE,'revision'=>(int)$current['revision'],'game_id'=>$current['game_id']];
      if ((int)($current['revision']??0)!==$input['revision']) throw new HttpException(409,'experience-changed');
      $game=$variant && $variant['verified'] ? self::game($variant['game_id']) : NULL;
      if (!$game && !empty($input['game_id'])) {
        if (!is_string($input['game_id'])) throw new HttpException(400,'invalid-game');
        $game=self::game($input['game_id']);
        if (!$game || $game['page_id']!==$page || !$game['verified']) throw new HttpException(400,'invalid-game');
      }
      if (!$game) {
        $gameId=self::gameKey($page,$identity['system_id'],'rom-'.$identity['rom_sha256']);
        $db->merge(self::GAMES)->insertFields(['id'=>$gameId,'page_id'=>$page,'system_id'=>$identity['system_id'],'machine_id'=>'rom-'.$identity['rom_sha256'],'title'=>$identity['title'],'verified'=>0,'created'=>time()])->key('id',$gameId)->execute();
        $game=self::game($gameId);
      }
      if (!$variant) $db->insert(self::VARIANTS)->fields(['id'=>$variantId,'game_id'=>$game['id'],'page_id'=>$page,'system_id'=>$identity['system_id'],'rom_sha256'=>$identity['rom_sha256'],'verified'=>0])->execute();
      $db->merge(self::RATINGS)->insertFields(['visible'=>1])->key('id',$id)->fields(['uid'=>$uid,'game_id'=>$game['id'],'variant_id'=>$variantId,'version'=>$identity['version'],'platform'=>$identity['platform'],'device'=>$input['device'],'result'=>$input['result'],'issues'=>(int)$input['issues'],'comment'=>$input['comment'],'revision'=>$input['revision']+1,'updated'=>time()])->execute();
      return ['saved'=>TRUE,'revision'=>$input['revision']+1,'game_id'=>$game['id'],'url'=>CompatibilityCatalog::path($page)];
    });
  }

  /** Read only. CSV rows remain technical evidence; community-only games have no invented test. */
  public static function gamesForPage(string $page): array {
    if (!self::ready()) return [];
    $db=\Drupal::database();$query=$db->select(self::RATINGS,'r');$query->join(self::GAMES,'g','g.id=r.game_id');
    $query->fields('g',['id','title','system_id']);
    $query->addExpression('COUNT(r.id)','total');
    $query->addExpression("COUNT(DISTINCT CASE WHEN r.result = 'completed' THEN r.uid END)",'completed');
    foreach(['g.id','g.title','g.system_id'] as $field)$query->groupBy($field);
    $games=$query->condition('g.page_id',$page)->condition('r.visible',1)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $result=[];
    foreach($games as $game) $result[$game['id']]=['id'=>$game['id'],'title'=>$game['title'],'system_id'=>$game['system_id'],'completed'=>(int)$game['completed'],'total'=>(int)$game['total'],'entries'=>[]];
    return $result;
  }
  /** Load comments only for the visible page, with a bounded 20 entries per game. */
  public static function loadExperiences(array &$cards): void {
    if(!self::ready())return;
    $db=\Drupal::database();
    foreach($cards as &$card) {
      if(empty($card['community']))continue;
      $q=$db->select(self::RATINGS,'r');$q->leftJoin(self::VARIANTS,'v','v.id=r.variant_id');
      $q->fields('r',['id','result','issues','comment','version','platform','device','updated'])->addField('v','verified','variant_verified');
      $card['community']['entries']=$q->condition('r.game_id',$card['community']['id'])->condition('r.visible',1)->orderBy('r.updated','DESC')->range(0,20)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    unset($card);
  }
  public static function cards(string $page,array $lists,string $selectedId,array $tests): array {
    $community=self::gamesForPage($page);$cards=[];$placed=[];$systems=[];
    foreach($lists as $list) foreach($list['rows'] as $row) {
      $row+=['sistema_id'=>'','juego_id'=>'','rom_sha256'=>''];
      $placed[self::rowKey($page,$row)]=$list['id'];
      $systems[$list['id']][]=$row['sistema_id'] ?: self::defaultSystem($page);
    }
    foreach($tests as $test) {
      $key=self::rowKey($page,$test);
      if(!isset($cards[$key]))$cards[$key]=$test+['id'=>$key,'has_ai'=>TRUE,'tests'=>[],'community'=>$community[$key]??NULL];
      $cards[$key]['tests'][]=$test;
    }
    foreach($community as $key=>$entry) {
      if(isset($placed[$key]))continue;
      $target=$lists[0]['id']??'';
      foreach($systems as $id=>$consoleIds)if(in_array($entry['system_id'],$consoleIds,TRUE)){$target=$id;break;}
      if($target!==$selectedId)continue;
      $cards[$key]=array_fill_keys(CompatibilityCatalog::HEADERS,'')+['id'=>$key,'has_ai'=>FALSE,'tests'=>[],'community'=>$entry];
      $cards[$key]['nombre']=$entry['title'];$cards[$key]['estado']='no_probado';$cards[$key]['velocidad_porcentaje']=NULL;
    }
    return array_values($cards);
  }
  public static function hasExperiences(string $page): bool {
    if (!self::ready()) return FALSE;
    $q=\Drupal::database()->select(self::RATINGS,'r');$q->join(self::GAMES,'g','g.id=r.game_id');
    return (bool)$q->condition('g.page_id',$page)->condition('r.visible',1)->range(0,1)->countQuery()->execute()->fetchField();
  }
  public static function purge(int $uid): void {
    if(!self::ready())return;
    self::locked(function()use($uid){
      $db=\Drupal::database();$db->delete(self::RATINGS)->condition('uid',$uid)->execute();
      $usedVariants=$db->select(self::RATINGS,'r')->fields('r',['variant_id']);
      $db->delete(self::VARIANTS)->condition('verified',0)->condition('id',$usedVariants,'NOT IN')->execute();
      $usedGames=$db->select(self::RATINGS,'r')->fields('r',['game_id']);
      $variantGames=$db->select(self::VARIANTS,'v')->fields('v',['game_id']);
      $db->delete(self::GAMES)->condition('verified',0)->condition('id',$usedGames,'NOT IN')->condition('id',$variantGames,'NOT IN')->execute();
    });
  }

  public static function moderation(string $page): array {
    if (!self::ready()) return [];
    $db=\Drupal::database();$q=$db->select(self::RATINGS,'r');$q->join(self::GAMES,'g','g.id=r.game_id');$q->leftJoin(self::VARIANTS,'v','v.id=r.variant_id');
    $q->fields('r',['id','variant_id','game_id','comment','result','visible','revision','updated']);$q->addField('g','title');$q->addField('v','verified');
    return $q->condition('g.page_id',$page)->orderBy('r.updated','DESC')->range(0,100)->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }
  public static function moderate(string $page,array $input): void {
    self::locked(function() use($page,$input){
      $db=\Drupal::database();$rating=$db->select(self::RATINGS,'r')->fields('r')->condition('id',$input['id']??'')->execute()->fetchAssoc();
      if (!$rating || self::game($rating['game_id'])['page_id']!==$page) throw new HttpException(404,'experience-not-found');
      if ((string)$rating['revision']!==(string)($input['revision']??'')) throw new HttpException(409,'experience-changed');
      if (($input['action']??'')==='associate') {
        $target=self::game($input['game_id']??'');if(!$target || $target['page_id']!==$page || !$target['verified'])throw new HttpException(400,'invalid-game');
        $variant=$db->select(self::VARIANTS,'v')->fields('v')->condition('id',$rating['variant_id'])->execute()->fetchAssoc();
        if($variant['verified']&&$variant['game_id']!==$target['id'])throw new HttpException(409,'variant-already-verified');
        $db->update(self::VARIANTS)->fields(['game_id'=>$target['id'],'verified'=>1])->condition('id',$rating['variant_id'])->execute();
        $db->update(self::RATINGS)->fields(['game_id'=>$target['id']])->expression('revision','revision+1')->condition('variant_id',$rating['variant_id'])->execute();
      }
      elseif (($input['action']??'')==='visibility' && is_bool($input['visible']??NULL)) $db->update(self::RATINGS)->fields(['visible'=>(int)$input['visible']])->expression('revision','revision+1')->condition('id',$rating['id'])->execute();
      else throw new HttpException(400,'invalid-moderation-action');
    });
  }
}
