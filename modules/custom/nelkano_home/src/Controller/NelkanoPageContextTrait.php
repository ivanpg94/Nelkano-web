<?php

namespace Drupal\nelkano_home\Controller;

trait NelkanoPageContextTrait {

  private function chromeContext(string $modulePath, string $language = 'es', string $alternateUrl = '/en', string $alternateLabel = 'English'): array {
    $authenticated = $this->currentUser()->isAuthenticated();
    $profile = $this->headerProfile();
    $name = $profile['name'];

    return [
      'language' => $language,
      'logo_url' => '/' . $modulePath . '/assets/logo.png',
      'header_css_url' => '/' . $modulePath . '/css/header.css',
      'nav_home_url' => $language === 'en' ? '/en' : '/',
      'nav_subtitle' => $language === 'en' ? 'Emulator for Android + Windows' : 'Emulador para Android + Windows',
      'nav_aria_label' => $language === 'en' ? 'Main navigation' : 'Navegacion principal',
      'nav_alternate_url' => $alternateUrl,
      'nav_alternate_label' => $alternateLabel,
      'nav_account_label' => $authenticated ? ($name !== '' ? $name : ($language === 'en' ? 'My profile' : 'Mi perfil')) : 'Login',
      'nav_account_url' => $authenticated
        ? ($language === 'en' ? '/en/user' : '/user')
        : ($language === 'en' ? '/en/user/login' : '/user/login'),
      'nav_account_initial' => $authenticated ? $profile['initial'] : '',
      'nav_account_color' => $profile['color'],
      'nav_links' => $authenticated ? [
        ['label' => $language === 'en' ? 'My profile' : 'Mi perfil', 'url' => $language === 'en' ? '/en/user' : '/user'],
        ['label' => 'Streaming', 'url' => $language === 'en' ? '/en/user/stream' : '/user/stream'],
      ] : [],
    ];
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

  private function headerProfile(): array {
    $fallback = trim((string) $this->currentUser()->getDisplayName());
    $profile = [
      'name' => $fallback,
      'initial' => mb_strtoupper(mb_substr($fallback !== '' ? $fallback : 'N', 0, 1)),
      'color' => '#a414ff',
    ];

    if (!$this->currentUser()->isAuthenticated()) {
      return $profile;
    }

    try {
      $schema = \Drupal::database()->schema();
      if (!$schema->tableExists('nelkano_profile')) {
        return $profile;
      }
      $row = \Drupal::database()->select('nelkano_profile', 'p')
        ->fields('p', ['display_name', 'avatar_color'])
        ->condition('uid', (int) $this->currentUser()->id())
        ->execute()
        ->fetchAssoc() ?: [];
      $name = trim((string) ($row['display_name'] ?? ''));
      if ($name !== '') {
        $profile['name'] = $name;
        $profile['initial'] = mb_strtoupper(mb_substr($name, 0, 1));
      }
      if (!empty($row['avatar_color'])) {
        $profile['color'] = (string) $row['avatar_color'];
      }
    }
    catch (\Throwable) {
      return $profile;
    }

    return $profile;
  }

}
