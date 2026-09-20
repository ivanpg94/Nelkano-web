<?php
namespace Drupal\nelkano_home\Service;

final class RedesignContent {
  public static function asset(string $name): string {
    return '/' . \Drupal::service('extension.list.module')->getPath('nelkano_home') . '/assets/figma/' . $name . '.svg';
  }
  public static function image(string $uri, string $fallback): string {
    return str_starts_with($uri, 'public://') ? \Drupal::service('file_url_generator')->generateString($uri) : self::asset($fallback);
  }
  public static function illustration(string $id): string {
    $aliases = ['game-boy-color'=>'game-boy','game-gear'=>'master-system','rpg-maker-mv-mz'=>'rpg-maker','rpg-maker-2000-2003'=>'rpg-maker','playstation-1'=>'playstation','neo-geo-pocket-color'=>'neo-geo-pocket'];
    $name = $aliases[$id] ?? $id;
    return is_file(dirname(__DIR__,2) . '/assets/figma/' . $name . '.svg') ? $name : 'default';
  }
  public static function categories(string $language): array {
    $labels = SystemPages::content($language)['labels'];
    $result=[];
    foreach (['basico','experimental','jugable','estable','verificado'] as $key) {
      $result[$key]=['label'=>$labels['tier_'.$key], 'description'=>$labels['tier_'.$key.'_description'], 'count'=>0];
    }
    return $result;
  }
  public static function decorate(string $id, array $page, string $language): array {
    $page['image_url']=self::image($page['image_uri'] ?? '', self::illustration($id));
    $page['url']=SystemPages::path($id,$language);
    $tier=$page['tier'] ?? 'basico';
    $page['tier']=in_array($tier,['basico','experimental','jugable','estable','verificado'],TRUE) ? $tier : 'basico';
    $page['tier_label']=self::categories($language)[$page['tier']]['label'];
    foreach (['steps','faq'] as $key) {
      if (!isset($page[$key])) {
        $page[$key]=[];
        for ($n=1;$n<=3;$n++) {
          $row=$key==='steps' ? ['title'=>$page["step_{$n}_title"]??'', 'description'=>$page["step_{$n}_text"]??''] : ['question'=>$page["faq_{$n}_question"]??'', 'answer'=>$page["faq_{$n}_answer"]??''];
          if (reset($row)!=='') {$page[$key][]=$row;}
        }
      }
    }
    return $page;
  }
  public static function featureRows(array $rows): array {
    $out=[];
    foreach ($rows as $row) {
      if (isset($row['visible']) && in_array($row['visible'], ['0',0,FALSE], TRUE)) { continue; }
      if (empty($row['title']) && empty($row['description'])) { continue; }
      $allowed=['IcoCloud','IcoGamepad','IcoPencilTouch','IcoCollections','IcoZip'];
      $icon=in_array($row['icon']??'', $allowed, TRUE) ? $row['icon'] : 'IcoCloud';
      $row['image_url']=self::image($row['image_uri']??'', $icon);
      $out[]=$row;
    }
    return $out;
  }
  /** One-time editorial migration; later admin edits are never reseeded. */
  public static function install(): void {
    if (\Drupal::state()->get('nelkano_home.figma_redesign_v1')) { return; }
    SystemPages::install();
    $seed=json_decode(file_get_contents(dirname(__DIR__,2).'/config/figma-home.json'),TRUE);
    $home=\Drupal::configFactory()->getEditable('nelkano_home.settings');
    $systems=\Drupal::configFactory()->getEditable(SystemPages::CONFIG);
    $copy=json_decode(file_get_contents(dirname(__DIR__,2).'/config/figma-system-copy.json'),TRUE);
    $featured=['chip-8','game-boy','game-boy-advance','mega-drive','playstation','nintendo-3ds'];
    foreach (['es','en'] as $language) {
      $data=SystemPages::content($language);
      $labels=json_decode(file_get_contents(dirname(__DIR__,2).'/config/figma-redesign.json'),TRUE)['labels'][$language];
      $data['labels']=array_replace($data['labels'],$labels);
      foreach ($data['pages'] as $id=>&$page) {
        if ($language==='es' && isset($copy[$id])) {$page=array_replace($page,$copy[$id]);}
        $page['show_on_home']=in_array($id,$featured,TRUE);
        $page=self::decorate($id,$page,$language);
        unset($page['image_url'],$page['url'],$page['tier_label']);
      }
      unset($page);
      $systems->set($language,$data);
      $content=$home->get($language)??[];
      foreach ($seed[$language] as $key=>$value) {$content[$key]=$value;}
      // Give legacy experience rows their previously implicit visibility.
      if (is_array($content['differentiator_items']??NULL)) {
        foreach ($content['differentiator_items'] as &$row) {$row['visible']=$row['visible']??'1';} unset($row);
      }
      $home->set($language,$content);
    }
    $home->set('langcode','es')->save(); $systems->save();
    \Drupal::state()->set('nelkano_home.figma_redesign_v1',TRUE);
  }
}
