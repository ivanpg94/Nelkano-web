<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
use Drupal\nelkano_home\Controller\NelkanoPageContextTrait;

trait AdminFormUiTrait {

  use NelkanoPageContextTrait;

  private function applyNelkanoAdminChrome(array &$form, string $activeKey, string $title, string $description, string $publicPath, string $activeLanguage): void {
    $module_path = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    $form['#attached']['library'][] = 'nelkano_home/admin_forms';
    $form['#attributes']['class'][] = 'nk-admin-config-form';
    $redesigned = in_array($activeKey, ['home','systems','privacy_cookies','legal_notice','releases','security','all','guide','seo','sdk'], TRUE);
    $form['#prefix'] = $this->nelkanoAdminPrefix($module_path, $activeKey, $redesigned ? 'nk-redesign-admin' : '');
    $form['#suffix'] = '</section></div></div></div>';
    if ($redesigned) {
      $title = ($activeLanguage === 'es' ? 'Editar: ' : 'Edit: ') . $title;
      $form['#attached']['library'][] = 'nelkano_home/redesign_admin';
    }

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

  /** Presentation groups retain the original input names and saved values. */
  private function groupAdminFields(array &$section, array $groups, array $parents): void {
    foreach ($groups as $key => $group) {
      $container = ['#type'=>'container','#attributes'=>['class'=>['r-field-grid','r-form-group',$group['class']??'']]];
      $container['heading'] = ['#type'=>'html_tag','#tag'=>'h3','#value'=>$group['title'],'#attributes'=>['class'=>['r-form-group-title']]];
      foreach ($group['fields'] as $field) {
        if (!isset($section[$field])) {continue;}
        $container[$field] = $section[$field];
        $container[$field]['#parents'] = array_merge($parents, [$field]);
        unset($section[$field]);
      }
      $section['layout_'.$key] = $container;
    }
  }

  /** Shared trusted wrapper: Twig escapes account data and the sidebar escapes URLs. */
  private function nelkanoAdminPrefix(string $modulePath, string $activeKey, string $appClasses = '', string $panelClasses = ''): Markup {
    return Markup::create($this->nelkanoAdminHeader($modulePath)
      . '<div class="nk-admin-app ' . htmlspecialchars($appClasses, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><div class="nk-admin-body">'
      . $this->nelkanoAdminSidebar($activeKey)
      . '<div class="nk-admin-workspace"><section class="nk-admin-panel ' . htmlspecialchars($panelClasses, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">');
  }

  private function nelkanoAdminHeader(string $modulePath): string {
    $template = file_get_contents(DRUPAL_ROOT . '/' . $modulePath . '/templates/nelkano-header.html.twig');
    return \Drupal::service('twig')->createTemplate($template)->render($this->chromeContext($modulePath, 'es', '', '') + ['header_js_attached' => TRUE]);
  }

  private function nelkanoAdminLanguageLinks(string $activeLanguage): string {
    $route_name = \Drupal::routeMatch()->getRouteName() ?: 'nelkano_home.admin_home';
    $route_params = \Drupal::routeMatch()->getRawParameters()->all();
    $links = '';
    foreach (['es' => 'ES', 'en' => 'EN'] as $langcode => $label) {
      $active = $activeLanguage === $langcode ? ' is-active' : '';
      $query = ['admin_lang' => $langcode];
      if ($route_name === 'nelkano_home.admin_systems' && ($system = \Drupal::request()->query->getString('system')) !== '') {$query['system'] = $system;}
      if ($route_name === 'nelkano_home.admin_sdk') {
        foreach (['page', 'new', 'parent'] as $key) {
          if (\Drupal::request()->query->has($key)) { $query[$key] = \Drupal::request()->query->getString($key); }
        }
      }
      $url = Url::fromRoute($route_name, $route_params, ['query' => $query])->toString();
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
      'Extensions' => 'Amplía Nelkano',
      'Modules card title' => 'Título de la tarjeta de módulos',
      'Modules card description' => 'Descripción de la tarjeta de módulos',
      'Modules button label' => 'Texto del botón de módulos',
      'SDK card title' => 'Título de la tarjeta del SDK',
      'SDK card description' => 'Descripción de la tarjeta del SDK',
      'SDK button label' => 'Texto del botón del SDK',
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
      'home' => ['Home', 'H', Url::fromRoute('nelkano_home.admin_home')->toString()],
      'systems' => ['Sistemas', 'C', Url::fromRoute('nelkano_home.admin_systems')->toString()],
      'privacy_cookies' => ['Privacidad y cookies', 'P', Url::fromRoute('nelkano_home.admin_privacy_cookies')->toString()],
      'legal_notice' => ['Aviso legal', 'A', Url::fromRoute('nelkano_home.admin_legal_notice')->toString()],
      'releases' => ['Versiones', 'V', Url::fromRoute('nelkano_home.admin_releases')->toString()],
      'security' => ['Seguridad y privacidad', 'S', Url::fromRoute('nelkano_home.admin_security_privacy')->toString()],
    ];

    $redesigned = in_array($activeKey, ['home','systems','privacy_cookies','legal_notice','releases','security','all','guide','seo','sdk'], TRUE)
      || in_array(\Drupal::routeMatch()->getRouteName(), ['nelkano_home.admin_error_reports', 'nelkano_home.admin_backlog'], TRUE);
    if ($redesigned) {$items['guide']=['Guía de uso','G',Url::fromRoute('nelkano_home.admin_guide')->toString()];}
    if ($redesigned) {$items['seo']=['SEO','SEO',Url::fromRoute('nelkano_home.admin_seo')->toString()];}
    if (\Drupal::moduleHandler()->moduleExists('book_import_export') && \Drupal::currentUser()->hasPermission('administer nelkano sdk')) {
      $items['sdk'] = ['SDK', 'SDK', Url::fromRoute('nelkano_home.admin_sdk')->toString()];
    }
    $icons=['sdk'=>'IcoBook','home'=>'IcoHome','systems'=>'IcoGrid','privacy_cookies'=>'IcoCookie','legal_notice'=>'IcoScales','releases'=>'IcoTag','security'=>'IcoShield','guide'=>'IcoBook','seo'=>'IcoTag'];
    $html = '<aside class="nk-admin-sidebar"><h2>Paginas editables</h2><nav aria-label="Paginas editables de Nelkano">';
    foreach ($items as $key => [$label, $icon, $url]) {
      $active = $key === $activeKey ? ' is-active' : '';
      $html .= '<a class="nk-admin-nav-item' . $active . '" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
        . '<span class="nk-admin-nav-icon">' . ($redesigned ? '<img src="' . \Drupal\nelkano_home\Service\RedesignContent::asset($icons[$key]) . '" width="16" height="16" alt="">' : htmlspecialchars($icon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</span>'
        . '<span><strong>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></span>'
        . '</a>';
    }
    $report_url = Url::fromRoute('nelkano_home.admin_error_reports')->toString();
    $report_active = $activeKey === 'error_reports' ? ' is-active' : '';
    $html .= '</nav><h2 class="nk-admin-sidebar-section">Datos del emulador</h2><nav aria-label="Datos del emulador">'
      . '<a class="nk-admin-nav-item' . $report_active . '" href="' . htmlspecialchars($report_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
      . '<span class="nk-admin-nav-icon">R</span>'
      . '<span><strong>Reportes de errores</strong></span>'
      . '</a>';
    if (\Drupal::currentUser()->hasPermission('administer nelkano backlog')) {
      $backlog_url = Url::fromRoute('nelkano_home.admin_backlog')->toString();
      $active = $activeKey === 'backlog' ? ' is-active' : '';
      $html .= '<a class="nk-admin-nav-item' . $active . '" href="' . htmlspecialchars($backlog_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><span class="nk-admin-nav-icon">HU</span><span><strong>Backlog · HU</strong></span></a>';
    }
    return $html . '</nav></aside>';
  }

}
