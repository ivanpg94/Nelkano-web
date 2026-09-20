<?php

namespace Drupal\nelkano_home\Service;

use Drupal\Component\Serialization\Yaml;

/** Editable system catalogue shared by the home, detail pages and admin. */
final class SystemPages {

  public const CONFIG = 'nelkano_home.systems';

  public static function defaults(): array {
    static $defaults;
    return $defaults ??= Yaml::decode(file_get_contents(dirname(__DIR__, 2) . '/config/install/nelkano_home.systems.yml'));
  }

  public static function content(string $language): array {
    $data = \Drupal::config(self::CONFIG)->get($language) ?? self::defaults()[$language];
    $new = json_decode(file_get_contents(dirname(__DIR__, 2) . '/config/figma-redesign.json'), TRUE);
    $data['labels'] += $new['labels'][$language];
    foreach ($data['pages'] as $id => &$page) {
      $page += $new['pages'][$id] ?? ['tier' => 'basico', 'status_note' => '', 'image_uri' => '', 'related_ids' => ''];
    }
    unset($page);
    return $data;
  }

  public static function path(string $id, string $language): string {
    return ($language === 'en' ? '/en/systems/' : '/sistemas/') . rawurlencode($id);
  }

  public static function lines(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), static fn(string $line): bool => $line !== ''));
  }

  public static function exists(string $id): bool {
    foreach (['es','en'] as $language) {
      if (isset(self::content($language)['pages'][$id])) {return TRUE;}
    }
    return FALSE;
  }

  /** Create blank bilingual editorial pages, hidden until explicitly published. */
  public static function add(string $id, string $name): void {
    $name=trim($name);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$id) || strlen($id)>64 || $name==='' || mb_strlen($name)>255) {
      throw new \InvalidArgumentException('Nombre o identificador de sistema no válido.');
    }
    $lock=\Drupal::lock();
    if (!$lock->acquire('nelkano_system_catalog',10)) {throw new \RuntimeException('El catálogo se está actualizando. Inténtalo de nuevo.');}
    try {
      if (self::exists($id)) {throw new \InvalidArgumentException('Ya existe un sistema con esa URL.');}
      $config=\Drupal::configFactory()->getEditable(self::CONFIG);
      foreach (['es','en'] as $language) {
        $page=[];
        // Match the complete schema without copying another system's content.
        foreach (self::defaults()[$language]['pages']['chip-8'] as $field=>$value) {
          $page[$field]=is_bool($value)?FALSE:(is_array($value)?[]:'');
        }
        $page=array_replace($page,['enabled'=>FALSE,'show_on_home'=>FALSE,'card_title'=>$name,'short_name'=>$name,'title'=>$name,'tier'=>'basico','status_note'=>'','image_uri'=>'','related_ids'=>'','steps'=>[],'faq'=>[]]);
        $config->set($language.'.pages.'.$id,$page);
      }
      $config->save();
    } finally {$lock->release('nelkano_system_catalog');}
  }

  /** Hide or restore publication in both languages; retain all editorial data. */
  public static function setVisibility(string $id, bool $visible): void {
    if (!self::exists($id)) {throw new \InvalidArgumentException('Sistema desconocido.');}
    $config=\Drupal::configFactory()->getEditable(self::CONFIG);
    foreach (['es','en'] as $language) {
      if (is_array($config->get($language.'.pages.'.$id))) {$config->set($language.'.pages.'.$id.'.enabled',$visible);}
    }
    $config->save();
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['config:nelkano_home.settings','config:'.self::CONFIG]);
  }

  /** Seed once, retaining existing card text/order and leaving later edits alone. */
  public static function install(): void {
    $config = \Drupal::configFactory()->getEditable(self::CONFIG);
    if ($config->get('langcode') === NULL) {
      $config->set('langcode', 'es');
    }
    $defaults = self::defaults();
    $aliases = ['chip-8', 'game-boy', 'game-boy-color', 'game-boy-advance', 'nes', 'rpg-maker-mv-mz', 'rpg-maker-2000-2003', 'nintendo-ds'];
    $names = ['chip-8', 'game boy', 'game boy color', 'game boy advance', 'nes', 'rpg maker mv/mz', 'rpg maker 2000/2003', 'nintendo ds'];
    foreach (['es', 'en'] as $language) {
      if ($config->get($language) !== NULL) {
        continue;
      }
      $data = $defaults[$language];
      $legacy = \Drupal::config('nelkano_home.settings')->get($language . '.status_items') ?? [];
      if (is_string($legacy)) {
        $legacy = array_map(static function (string $line): array {
          $parts = array_pad(explode('|', $line, 3), 3, '');
          return array_combine(['system', 'status', 'description'], $parts);
        }, self::lines($legacy));
      }
      $ordered = [];
      foreach ($legacy as $row) {
        $name = trim((string) ($row['system'] ?? ''));
        if ($name === '') {
          continue;
        }
        $index = array_search(mb_strtolower($name), $names, TRUE);
        $id = $index !== FALSE ? $aliases[$index] : 'system-' . substr(hash('sha256', $name), 0, 12);
        $page = $data['pages'][$id] ?? $data['pages']['chip-8'];
        if (!isset($data['pages'][$id])) {
          foreach ($page as $key => $value) {
            $page[$key] = is_bool($value) ? TRUE : (is_array($value) ? [] : '');
          }
          $page['title'] = $name;
          $page['overview'] = (string) ($row['description'] ?? '');
        }
        $page['card_title'] = $name;
        $page['status'] = (string) ($row['status'] ?? '');
        $page['summary'] = (string) ($row['description'] ?? '');
        $ordered[$id] = $page;
      }
      $data['pages'] = $ordered + $data['pages'];
      $config->set($language, $data);
    }
    $config->save(TRUE);
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['config:nelkano_home.settings', 'config:' . self::CONFIG]);
  }
}
