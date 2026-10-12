<?php

declare(strict_types=1);
namespace Drupal\nelkano_modules\Service;

/** Author-declared emulated systems; IDs match the new app's GameSystems. */
final class ModuleSystems {
  public static function options(): array {
    return ['chip8' => 'CHIP-8', 'gb' => 'GB · Game Boy', 'gbc' => 'GBC · Game Boy Color',
      'gba' => 'GBA · Game Boy Advance', 'nes' => 'NES / Famicom', 'snes' => 'SNES · Super Nintendo',
      'sms' => 'Master System', 'gg' => 'Game Gear', 'md' => 'Mega Drive', 'ngp' => 'Neo Geo Pocket',
      'nds' => 'NDS · Nintendo DS', '3ds' => 'Nintendo 3DS', 'ps1' => 'PlayStation',
      'psp' => 'PSP', 'gamecube' => 'GameCube', 'switch' => 'Nintendo Switch', 'rpgmaker' => 'RPG Maker'];
  }

  public static function validate(mixed $values): array {
    if (!is_array($values) || !array_is_list($values)) { throw new \InvalidArgumentException('Selecciona los sistemas compatibles.'); }
    $options = self::options();
    foreach ($values as $value) {
      if (!is_string($value) || !isset($options[$value])) { throw new \InvalidArgumentException('Selecciona un sistema válido.'); }
    }
    // Stable order regardless of checkbox submission order.
    return array_values(array_intersect(array_keys($options), $values));
  }
}
