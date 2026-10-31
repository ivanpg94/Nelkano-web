<?php

namespace Drupal\nelkano_home\Controller;

trait NelkanoPageContextTrait {

  private function analyticsSettings(): array {
    // Ivan confirmed these production accounts as his own. Other accounts remain measured.
    // A site setting can override the list without sending account IDs to Analytics.
    $excluded_uids = array_map('intval', (array) \Drupal\Core\Site\Settings::get(
      'nelkano_analytics_excluded_uids', [1, 3, 10, 12, 13],
    ));
    $account = $this->currentUser();
    return [
      'ga_id' => 'G-2VCPFJW0PT',
      'exclude_internal' => $account->isAuthenticated()
        && in_array((int) $account->id(), $excluded_uids, TRUE),
    ];
  }

  private function chromeContext(string $modulePath, string $language = 'es', string $alternateUrl = '/en', string $alternateLabel = 'English'): array {
    $authenticated = $this->currentUser()->isAuthenticated();
    $profile = $this->headerProfile();
    $name = $profile['name'];
    $logoVersion = '';
    $logoPath = DRUPAL_ROOT . '/' . $modulePath . '/assets/logo-nav.webp';
    if (is_file($logoPath)) {
      $logoVersion = '?v=' . filemtime($logoPath);
    }
    $messagesVersion = '';
    $messagesPath = DRUPAL_ROOT . '/' . $modulePath . '/js/messages.js';
    if (is_file($messagesPath)) {
      $messagesVersion = '?v=' . filemtime($messagesPath);
    }

    $modules_url = '/modulos';
    $sdk_url = '';
    $nav_links = [['label' => $language === 'en' ? 'Systems' : 'Sistemas', 'url' => $language === 'en' ? '/en/systems' : '/sistemas']];
    $nav_links[] = ['label' => $language === 'en' ? 'Modules' : 'Módulos', 'url' => $modules_url];
    $nav_links[] = ['label' => $language === 'en' ? 'Guide' : 'Guía de uso', 'url' => $language === 'en' ? '/en/guide' : '/guia'];
    $sdk_books = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'uuid' => 'de520020-2026-4000-8000-000000000001',
      'type' => 'sdk_page',
    ]);
    if ($sdk_book = reset($sdk_books)) {
      $sdk_book = $sdk_book->hasTranslation($language)
        ? $sdk_book->getTranslation($language)
        : \Drupal::service('entity.repository')->getTranslationFromContext($sdk_book, $language);
      if ($sdk_book->isPublished() && $sdk_book->access('view', $this->currentUser())) {
        $sdk_url = $sdk_book->toUrl()->toString();
        $nav_links[] = ['label' => 'SDK', 'url' => $sdk_url];
      }
    }
    $account_links = [];
    if ($authenticated) {
      $account_links[] = ['label' => $language === 'en' ? 'My profile' : 'Mi perfil', 'url' => $language === 'en' ? '/en/user' : '/user'];
      $account_links[] = ['label' => 'Streaming', 'url' => $language === 'en' ? '/en/user/stream' : '/user/stream'];
      if ($this->currentUser()->hasPermission('administer nelkano editable pages')) {
        $account_links[] = ['label' => $language === 'en' ? 'Administration' : 'Administración', 'url' => '/admin/nelkano'];
      }
    }

    return [
      'language' => $language,
      'seo_metadata' => \Drupal\nelkano_home\Service\SeoMetadata::forRequest($language),
      'analytics' => $this->analyticsSettings(),
      'logo_url' => '/' . $modulePath . '/assets/logo-nav.webp' . $logoVersion,
      'redesign_css_url' => '/' . $modulePath . '/css/redesign.css?v=' . filemtime(DRUPAL_ROOT . '/' . $modulePath . '/css/redesign.css'),
      'site_pages_css_url' => '/' . $modulePath . '/css/site-pages.css?v=' . filemtime(DRUPAL_ROOT . '/' . $modulePath . '/css/site-pages.css'),
      'header_css_url' => '/' . $modulePath . '/css/header.css?v=' . filemtime(DRUPAL_ROOT . '/' . $modulePath . '/css/header.css'),
      'header_js_url' => '/' . $modulePath . '/js/header.js?v=' . filemtime(DRUPAL_ROOT . '/' . $modulePath . '/js/header.js'),
      'footer_css_url' => '/' . $modulePath . '/css/footer.css?v=' . filemtime(DRUPAL_ROOT . '/' . $modulePath . '/css/footer.css'),
      'base_js_url' => '/' . $modulePath . '/js/messages.js' . $messagesVersion,
      'theme_js_url' => '/' . $modulePath . '/js/theme-toggle.js',
      'nav_home_url' => $language === 'en' ? '/en' : '/',
      'nav_subtitle' => $language === 'en' ? 'Multisystem emulator' : 'Emulador multisistema',
      'nav_aria_label' => $language === 'en' ? 'Main navigation' : 'Navegacion principal',
      'nav_alternate_url' => $alternateUrl,
      'nav_alternate_label' => $alternateLabel,
      'nav_alternate_flag' => $language === 'en' ? 'ES' : 'EN',
      'nav_alternate_flag_url' => '/' . $modulePath . '/assets/flags/' . ($language === 'en' ? 'es' : 'en') . '.svg',
      'theme_toggle_label' => $language === 'en' ? 'Switch color mode' : 'Cambiar modo de color',
      'nav_modules_url' => $modules_url,
      'nav_sdk_url' => $sdk_url,
      'nav_menu_open_label' => $language === 'en' ? 'Open menu' : 'Abrir menú',
      'nav_menu_close_label' => $language === 'en' ? 'Close menu' : 'Cerrar menú',
      'nav_account_menu_label' => $language === 'en' ? 'My account' : 'Mi cuenta',
      'nav_account_links' => $account_links,
      'nav_login_label' => $language === 'en' ? 'Sign in' : 'Entrar',
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
      'nav_logout_label' => $language === 'en' ? 'Log out' : 'Cerrar sesión',
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
        $profile['avatar_url'] = \Drupal\nelkano_home\Service\HeaderImages::avatarUrl($avatar_uri);
      }
    }
    catch (\Throwable) {
      return $profile;
    }

    return $profile;
  }

}
