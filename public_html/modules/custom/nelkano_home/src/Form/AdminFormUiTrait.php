<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Url;

trait AdminFormUiTrait {

  private function applyNelkanoAdminChrome(array &$form, string $activeKey, string $title, string $description, string $publicPath, string $activeLanguage): void {
    $module_path = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    $form['#attached']['library'][] = 'nelkano_home/admin_forms';
    $form['#attributes']['class'][] = 'nk-admin-config-form';
    $form['#prefix'] = $this->nelkanoAdminHeader($module_path) . '<div class="nk-admin-app"><div class="nk-admin-body">' . $this->nelkanoAdminSidebar($activeKey) . '<div class="nk-admin-workspace"><section class="nk-admin-panel">';
    $form['#suffix'] = '</section></div></div></div>';

    $form['admin_header'] = [
      '#weight' => -1000,
      '#markup' => '<div class="nk-admin-panel-head">'
        . '<div><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
        . '<p>' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div>'
        . '<div class="nk-admin-head-actions">' . $this->nelkanoAdminLanguageLinks($activeLanguage)
        . '<a class="nk-admin-public-link" href="' . htmlspecialchars($publicPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener">Ver pagina</a></div>'
        . '</div>',
    ];

  }

  private function nelkanoAdminHeader(string $modulePath): string {
    $account = \Drupal::currentUser();
    $name = trim((string) $account->getDisplayName());
    $initial = mb_strtoupper(mb_substr($name !== '' ? $name : 'N', 0, 1));
    $template = file_get_contents(DRUPAL_ROOT . '/' . $modulePath . '/templates/nelkano-header.html.twig');

    return \Drupal::service('twig')->createTemplate($template)->render([
      'language' => 'es',
      'logo_url' => '/' . $modulePath . '/assets/logo.png',
      'nav_home_url' => '/',
      'nav_subtitle' => 'Emulador para Android',
      'nav_aria_label' => 'Navegacion principal',
      'nav_alternate_url' => '',
      'nav_is_authenticated' => TRUE,
      'nav_account_label' => $name,
      'nav_profile_label' => 'Mi perfil',
      'nav_account_url' => '/user',
      'nav_account_initial' => $initial,
      'nav_account_color' => '#a414ff',
      'nav_account_avatar_url' => '',
      'nav_logout_url' => Url::fromUserInput('/user/logout', [
        'query' => ['token' => \Drupal::service('csrf_token')->get('user/logout')],
      ])->toString(),
      'nav_logout_label' => 'Cerrar sesion',
      'nav_links' => [
        ['label' => 'Admin', 'url' => Url::fromRoute('nelkano_home.admin_home')->toString()],
      ],
    ]);
  }

  private function nelkanoAdminLanguageLinks(string $activeLanguage): string {
    $route_name = \Drupal::routeMatch()->getRouteName() ?: 'nelkano_home.admin_home';
    $route_params = \Drupal::routeMatch()->getRawParameters()->all();
    $links = '';
    foreach (['es' => 'ES', 'en' => 'EN'] as $langcode => $label) {
      $active = $activeLanguage === $langcode ? ' is-active' : '';
      $url = Url::fromRoute($route_name, $route_params, ['query' => ['admin_lang' => $langcode]])->toString();
      $links .= '<a class="nk-admin-lang-link' . $active . '" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $label . '</a>';
    }
    return '<div class="nk-admin-language-switch" aria-label="Idioma del formulario">' . $links . '</div>';
  }

  private function activeAdminLanguage(): string {
    return \Drupal::request()->query->get('admin_lang') === 'en' ? 'en' : 'es';
  }

  private function adminLabel(string $label, string $language): string {
    $spanish = [
      'Navigation' => 'Navegacion',
      'Hero' => 'Cabecera principal',
      'Downloads' => 'Descargas',
      'About' => 'Informacion',
      'Status' => 'Estado actual',
      'Platforms' => 'Plataformas',
      'Differentiators' => 'Diferenciadores',
      'Vision' => 'Vision',
      'FAQ' => 'Preguntas frecuentes',
      'Trust' => 'Proyecto verificable',
      'Footer' => 'Pie de pagina',
      'Language switch label' => 'Etiqueta del cambio de idioma',
      'SEO title' => 'Titulo SEO',
      'SEO description' => 'Descripcion SEO',
      'SEO keywords' => 'Palabras clave SEO',
      'Badge' => 'Etiqueta',
      'Title' => 'Titulo',
      'Description' => 'Descripcion',
      'Primary CTA label' => 'Texto del boton principal',
      'Primary CTA URL' => 'URL del boton principal',
      'Secondary CTA label' => 'Texto del boton secundario',
      'Secondary CTA URL' => 'URL del boton secundario',
      'Section title' => 'Titulo de seccion',
      'Android title' => 'Titulo Android',
      'Android channel' => 'Canal Android',
      'Android description' => 'Descripcion Android',
      'Android URL' => 'URL Android',
      'Secondary download title' => 'Titulo descarga secundaria',
      'Secondary download channel' => 'Canal descarga secundaria',
      'Secondary download description' => 'Descripcion descarga secundaria',
      'Secondary download URL' => 'URL descarga secundaria',
      'Eyebrow' => 'Etiqueta',
      'Status cards' => 'Tarjetas de estado',
      'Platform cards' => 'Tarjetas de plataforma',
      'Differentiator cards' => 'Tarjetas diferenciadoras',
      'Vision bullets' => 'Puntos de vision',
      'FAQ items' => 'Preguntas y respuestas',
      'Trust links' => 'Elementos verificables',
      'Compatibility rows' => 'Filas de compatibilidad',
      'System' => 'Sistema',
      'Question' => 'Pregunta',
      'Answer' => 'Respuesta',
      'Text' => 'Texto',
      'Paragraphs' => 'Parrafos',
      'Works' => 'Funciona',
      'Limitations' => 'Limitaciones',
      'Last review' => 'Ultima revision',
      'Delete' => 'Eliminar',
      'Add row' => 'Anadir fila',
      'Primary text' => 'Texto principal',
      'Secondary text' => 'Texto secundario',
      'Public version' => 'Version publica',
      'APK filename' => 'Nombre del APK',
      'Publication date' => 'Fecha de publicacion',
      'Requirements' => 'Requisitos',
      'Changelog items' => 'Cambios',
      'Release rows' => 'Versiones publicadas',
      'Add one row per release. Put each change on its own line.' => 'Anade una fila por version. Escribe cada cambio en una linea distinta.',
      'Download notice' => 'Aviso de descarga',
      'Security sections' => 'Secciones de seguridad',
      'Titulo SEO' => 'Titulo SEO',
      'Descripcion SEO' => 'Descripcion SEO',
      'Etiqueta' => 'Etiqueta',
      'Titulo visible' => 'Titulo visible',
      'Introduccion' => 'Introduccion',
      'Contenido estructurado' => 'Contenido estructurado',
      'Show section' => 'Mostrar seccion',
    ];
    $english = [
      'Titulo SEO' => 'SEO title',
      'Descripcion SEO' => 'SEO description',
      'Etiqueta' => 'Eyebrow',
      'Titulo visible' => 'Visible title',
      'Introduccion' => 'Introduction',
      'Contenido estructurado' => 'Structured content',
      'Privacidad y cookies' => 'Privacy and cookies',
      'Aviso legal' => 'Legal notice',
      'Versiones' => 'Releases',
      'Seguridad y privacidad' => 'Security and privacy',
      'Espanol' => 'Spanish',
    ];
    return $language === 'es' ? ($spanish[$label] ?? $label) : ($english[$label] ?? $label);
  }

  private function adminDescription(?string $description, string $language): ?string {
    if ($description === NULL) {
      return NULL;
    }
    $spanish = [
      'If empty or #, the newest .apk in modules/custom/nelkano_home/emulator is used.' => 'Si esta vacio o es #, se usara el .apk mas reciente de modules/custom/nelkano_home/emulator.',
      'Leave title, description and URL empty to hide the secondary download card.' => 'Deja titulo, descripcion y URL vacios para ocultar la tarjeta secundaria.',
      'One per line: System|Status|Description' => 'Una linea por elemento: Sistema|Estado|Descripcion',
      'One per line: Title|Description' => 'Una linea por elemento: Titulo|Descripcion',
      'One item per line.' => 'Un elemento por linea.',
      'One per line: Question|Answer' => 'Una linea por elemento: Pregunta|Respuesta',
      'One per line: Title|Description|URL' => 'Una linea por elemento: Titulo|Descripcion|URL',
      'One per line: Title|Paragraph 1||Paragraph 2' => 'Una linea por seccion: Titulo|Parrafo 1||Parrafo 2',
      'Una linea por seccion: Titulo|Parrafo 1||Parrafo 2' => 'Una linea por seccion: Titulo|Parrafo 1||Parrafo 2',
      'Add one row per system.' => 'Anade una fila por sistema.',
      'Add one row per card.' => 'Anade una fila por tarjeta.',
      'Add one row per bullet.' => 'Anade una fila por punto.',
      'Add one row per question.' => 'Anade una fila por pregunta.',
      'Add one row per link.' => 'Anade una fila por enlace.',
      'Add one row per change.' => 'Anade una fila por cambio.',
      'Add one row per release. Put each change on its own line.' => 'Anade una fila por version. Escribe cada cambio en una linea distinta.',
      'Add one row per section.' => 'Anade una fila por seccion.',
      'Add one row per section. Put each paragraph on its own line.' => 'Anade una fila por seccion. Escribe cada parrafo en una linea distinta.',
    ];
    $english = [
      'Una linea por seccion: Titulo|Parrafo 1||Parrafo 2' => 'One section per line: Title|Paragraph 1||Paragraph 2',
    ];
    return $language === 'es' ? ($spanish[$description] ?? $description) : ($english[$description] ?? $description);
  }

  private function nelkanoAdminSidebar(string $activeKey): string {
    $items = [
      'home' => ['Home', 'Gestiona la pagina principal de Nelkano.', 'H', Url::fromRoute('nelkano_home.admin_home')->toString()],
      'privacy_cookies' => ['Privacidad y cookies', 'Configura la politica de privacidad y cookies.', 'P', Url::fromRoute('nelkano_home.admin_privacy_cookies')->toString()],
      'legal_notice' => ['Aviso legal', 'Edita los terminos y condiciones de uso.', 'A', Url::fromRoute('nelkano_home.admin_legal_notice')->toString()],
      'releases' => ['Versiones', 'Administra las versiones publicadas de la web.', 'V', Url::fromRoute('nelkano_home.admin_releases')->toString()],
      'security' => ['Seguridad y privacidad', 'Configura opciones de seguridad y datos.', 'S', Url::fromRoute('nelkano_home.admin_security_privacy')->toString()],
    ];

    $html = '<aside class="nk-admin-sidebar"><h2>Paginas editables</h2><nav aria-label="Paginas editables de Nelkano">';
    foreach ($items as $key => [$label, $description, $icon, $url]) {
      $active = $key === $activeKey ? ' is-active' : '';
      $html .= '<a class="nk-admin-nav-item' . $active . '" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
        . '<span class="nk-admin-nav-icon">' . htmlspecialchars($icon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
        . '<span><strong>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><small>' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></span>'
        . '</a>';
    }
    return $html . '</nav></aside>';
  }

}
