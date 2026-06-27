<?php

namespace Drupal\nelkano_home\Controller;

trait NelkanoPageContextTrait {

  private function chromeContext(string $modulePath, string $language = 'es', string $alternateUrl = '/en', string $alternateLabel = 'English'): array {
    $authenticated = $this->currentUser()->isAuthenticated();
    $profile = $this->headerProfile();
    $name = $profile['name'];
    $logoVersion = '';
    $logoPath = DRUPAL_ROOT . '/' . $modulePath . '/assets/logo.png';
    if (is_file($logoPath)) {
      $logoVersion = '?v=' . filemtime($logoPath);
    }
    $messagesVersion = '';
    $messagesPath = DRUPAL_ROOT . '/' . $modulePath . '/js/messages.js';
    if (is_file($messagesPath)) {
      $messagesVersion = '?v=' . filemtime($messagesPath);
    }

    $nav_links = [];
    if ($authenticated && $this->currentUser()->hasPermission('administer nelkano editable pages')) {
      $nav_links[] = ['label' => 'Admin', 'url' => '/admin/nelkano'];
    }
    if ($authenticated) {
      $nav_links[] = ['label' => 'Streaming', 'url' => $language === 'en' ? '/en/user/stream' : '/user/stream'];
    }

    return [
      'language' => $language,
      'analytics' => ['ga_id' => 'G-2VCPFJW0PT'],
      'logo_url' => '/' . $modulePath . '/assets/logo.png' . $logoVersion,
      'header_css_url' => '/' . $modulePath . '/css/header.css',
      'footer_css_url' => '/' . $modulePath . '/css/footer.css',
      'base_js_url' => '/' . $modulePath . '/js/messages.js' . $messagesVersion,
      'theme_js_url' => '/' . $modulePath . '/js/theme-toggle.js',
      'nav_home_url' => $language === 'en' ? '/en' : '/',
      'nav_subtitle' => $language === 'en' ? 'Emulator for Android' : 'Emulador para Android',
      'nav_aria_label' => $language === 'en' ? 'Main navigation' : 'Navegacion principal',
      'nav_alternate_url' => $alternateUrl,
      'nav_alternate_label' => $alternateLabel,
      'nav_alternate_flag' => $language === 'en' ? 'ES' : 'EN',
      'nav_alternate_flag_url' => '/' . $modulePath . '/assets/flags/' . ($language === 'en' ? 'es' : 'en') . '.svg',
      'theme_toggle_label' => $language === 'en' ? 'Switch color mode' : 'Cambiar modo de color',
      'nav_is_authenticated' => $authenticated,
      'nav_account_label' => $authenticated ? ($name !== '' ? $name : ($language === 'en' ? 'My profile' : 'Mi perfil')) : 'Login',
      'nav_profile_label' => $language === 'en' ? 'My profile' : 'Mi perfil',
      'nav_account_url' => $authenticated
        ? ($language === 'en' ? '/en/user' : '/user')
        : ($language === 'en' ? '/en/user/login' : '/user/login'),
      'nav_account_initial' => $authenticated ? $profile['initial'] : '',
      'nav_account_color' => $profile['color'],
      'nav_account_avatar_url' => $authenticated ? $profile['avatar_url'] : '',
      'nav_logout_url' => $authenticated ? $this->logoutUrl() : '',
      'nav_logout_label' => $language === 'en' ? 'Log out' : 'Cerrar sesion',
      'nav_links' => $nav_links,
    ];
  }

  private function userMenuContext(string $language = 'es', string $active = 'profile', bool $sections = FALSE): array {
    if (!$this->currentUser()->isAuthenticated()) {
      return [
        'user_menu_items' => [],
        'user_menu_label' => '',
        'user_menu_section_endpoint' => '',
      ];
    }

    $profile_url = $language === 'en' ? '/en/user' : '/user';
    $friends_section = $language === 'en' ? 'friends' : 'amigos';

    return [
      'user_menu_label' => $language === 'en' ? 'User menu' : 'Menu de usuario',
      'user_menu_section_endpoint' => $sections ? ($language === 'en' ? '/en/user/section' : '/user/section') : '',
      'user_menu_items' => [
        [
          'label' => $language === 'en' ? 'Profile' : 'Perfil',
          'url' => $profile_url,
          'section' => $sections ? ($language === 'en' ? 'profile' : 'perfil') : '',
          'active' => $active === 'profile',
        ],
        [
          'label' => $language === 'en' ? 'Friends' : 'Amigos',
          'url' => $profile_url . '#friends',
          'section' => $sections ? $friends_section : '',
          'active' => $active === 'friends',
        ],
      ],
    ];
  }

  private function requestLanguage(): string {
    return str_starts_with(\Drupal::request()->getPathInfo(), '/en') ? 'en' : 'es';
  }

  private function logoutUrl(): string {
    return \Drupal\Core\Url::fromUserInput('/user/logout', [
      'query' => [
        'token' => \Drupal::service('csrf_token')->get('user/logout'),
      ],
    ])->toString();
  }

  private function headerProfile(): array {
    $fallback = trim((string) $this->currentUser()->getDisplayName());
    $profile = [
      'name' => $fallback,
      'initial' => mb_strtoupper(mb_substr($fallback !== '' ? $fallback : 'N', 0, 1)),
      'color' => '#a414ff',
      'avatar_url' => '',
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
        ->fields('p', ['display_name', 'avatar_color', 'avatar_file_uri'])
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
      $avatar_uri = trim((string) ($row['avatar_file_uri'] ?? ''));
      if ($avatar_uri !== '') {
        $profile['avatar_url'] = \Drupal::service('file_url_generator')->generateString($avatar_uri);
      }
    }
    catch (\Throwable) {
      return $profile;
    }

    return $profile;
  }

}
