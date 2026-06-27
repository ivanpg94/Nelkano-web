<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

final class HomeController extends ControllerBase {

  use NelkanoPageContextTrait;

  private const GA_MEASUREMENT_ID = 'G-2VCPFJW0PT';
  private const SOCIAL_IMAGE_FILENAME = 'logo-social-v2.png';

  public function __construct(
    private readonly ConfigFactoryInterface $homeConfigFactory,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('extension.list.module'),
    );
  }

  public function spanish(): Response {
    return $this->build('es');
  }

  public function english(): Response {
    return $this->build('en');
  }

  public function legalNoticeSpanish(): Response {
    return $this->buildLegal('es', 'legal_notice');
  }

  public function privacyCookiesSpanish(): Response {
    return $this->buildLegal('es', 'privacy_cookies');
  }

  public function legalNoticeEnglish(): Response {
    return $this->buildLegal('en', 'legal_notice');
  }

  public function privacyCookiesEnglish(): Response {
    return $this->buildLegal('en', 'privacy_cookies');
  }

  public function releasesSpanish(): Response {
    return $this->buildDocs('es', 'releases');
  }

  public function releasesEnglish(): Response {
    return $this->buildDocs('en', 'releases');
  }

  public function securityPrivacySpanish(): Response {
    return $this->buildDocs('es', 'security');
  }

  public function securityPrivacyEnglish(): Response {
    return $this->buildDocs('en', 'security');
  }

  public function notFound(): Response {
    return $this->buildErrorPage(404);
  }

  public function accessDenied(): Response {
    return $this->buildErrorPage(403);
  }

  public function userStream(): Response {
    $module_path = $this->moduleExtensionList->getPath('nelkano_home');
    $language = $this->requestLanguage();
    $template = file_get_contents(DRUPAL_ROOT . '/' . $module_path . '/templates/nelkano-stream-standalone.html.twig');
    $stream_js_path = DRUPAL_ROOT . '/' . $module_path . '/js/stream-viewer.js';
    $stream_js_version = is_file($stream_js_path) ? (string) filemtime($stream_js_path) : (string) time();
    $html = \Drupal::service('twig')->createTemplate($template)->render([
      'base_css_url' => '/' . $module_path . '/css/base.css',
      'header_css_url' => '/' . $module_path . '/css/header.css',
      'stream_css_url' => '/' . $module_path . '/css/stream.css',
      'stream_js_url' => '/' . $module_path . '/js/stream-viewer.js?v=' . $stream_js_version,
      'stream_ws_url' => $this->streamWebSocketUrl(),
      'stream_active_url' => '/api/nelkano/stream/session/active',
      'stream_ice_servers' => $this->streamIceServersJson(),
      'stream_ice_policy' => $this->streamIcePolicy(),
    ] + $this->chromeContext(
      $module_path,
      $language,
      $language === 'en' ? '/user/stream' : '/en/user/stream',
      $language === 'en' ? 'Espanol' : 'English',
    ));

    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
  }

  public function sitemap(): Response {
    $base_url = \Drupal::request()->getSchemeAndHttpHost();
    $now = gmdate('Y-m-d');
    $urls = [
      ['loc' => $base_url . '/', 'priority' => '1.0'],
      ['loc' => $base_url . '/en', 'priority' => '0.9'],
      ['loc' => $base_url . '/aviso-legal', 'priority' => '0.4'],
      ['loc' => $base_url . '/privacidad-cookies', 'priority' => '0.4'],
      ['loc' => $base_url . '/versiones', 'priority' => '0.6'],
      ['loc' => $base_url . '/seguridad-privacidad', 'priority' => '0.5'],
      ['loc' => $base_url . '/contacto', 'priority' => '0.3'],
      ['loc' => $base_url . '/en/legal-notice', 'priority' => '0.3'],
      ['loc' => $base_url . '/en/privacy-cookies', 'priority' => '0.3'],
      ['loc' => $base_url . '/en/releases', 'priority' => '0.5'],
      ['loc' => $base_url . '/en/security-privacy', 'priority' => '0.4'],
    ];

    $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
    $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $url) {
      $xml[] = '  <url>';
      $xml[] = '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . '</loc>';
      $xml[] = '    <lastmod>' . $now . '</lastmod>';
      $xml[] = '    <changefreq>weekly</changefreq>';
      $xml[] = '    <priority>' . $url['priority'] . '</priority>';
      $xml[] = '  </url>';
    }
    $xml[] = '</urlset>';

    return new Response(implode("\n", $xml), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
  }

  private function build(string $language): Response {
    $config = $this->homeConfigFactory->get('nelkano_home.settings');
    $content = $config->get($language) ?? [];
    $content['status_items'] = $this->parseRows($content['status_items'] ?? '', ['system', 'status', 'description']);
    $content['platform_items'] = $this->parseRows($content['platform_items'] ?? '', ['title', 'description']);
    $content['differentiator_items'] = $this->parseRows($content['differentiator_items'] ?? '', ['title', 'description']);
    $content['vision_items'] = $this->parseLines($content['vision_items'] ?? '');
    $content['faq_items'] = $this->parseRows($content['faq_items'] ?? '', ['question', 'answer']);
    $content['trust_items'] = $this->parseRows($content['trust_items'] ?? '', ['title', 'description', 'url']);
    $module_path = $this->moduleExtensionList->getPath('nelkano_home');
    $android_download = $this->resolveDownload($content['android_url'] ?? '', $module_path, 'apk');
    $show_secondary_download = trim((string) ($content['windows_title'] ?? '')) !== ''
      || trim((string) ($content['windows_description'] ?? '')) !== ''
      || trim((string) ($content['windows_url'] ?? '')) !== '';
    $windows_download = $show_secondary_download
      ? $this->resolveDownload($content['windows_url'] ?? '', $module_path, 'exe')
      : ['url' => '', 'meta' => []];
    $content['android_download_url'] = $android_download['url'];
    $content['android_download_meta'] = $android_download['meta'];
    $content['windows_download_url'] = $windows_download['url'];
    $content['windows_download_meta'] = $windows_download['meta'];
    $seo = $this->buildSeo($language, $content);
    $template = file_get_contents(DRUPAL_ROOT . '/' . $module_path . '/templates/nelkano-home-standalone.html.twig');
    $html = \Drupal::service('twig')->createTemplate($template)->render([
      'content' => $content,
      'css_inline' => $this->loadInlineCss($module_path),
      'seo' => $seo,
      'analytics' => $this->analyticsSettings(),
    ] + $this->chromeContext(
      $module_path,
      $language,
      $language === 'es' ? '/en' : '/',
      $content['nav_language'] ?? ($language === 'es' ? 'English' : 'Espanol'),
    ));

    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => $this->currentUser()->isAuthenticated() ? 'no-store, private' : 'public, max-age=3600',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
  }

  private function buildLegal(string $language, string $pageKey): Response {
    $module_path = $this->moduleExtensionList->getPath('nelkano_home');
    $page = $this->legalPage($language, $pageKey);
    $template = file_get_contents(DRUPAL_ROOT . '/' . $module_path . '/templates/nelkano-legal-standalone.html.twig');
    $html = \Drupal::service('twig')->createTemplate($template)->render([
      'page' => $page,
      'social_image_url' => $this->socialImageUrl($module_path),
      'css_inline' => $this->loadInlineCss($module_path),
      'analytics' => $this->analyticsSettings(),
    ] + $this->chromeContext(
      $module_path,
      $language,
      $page['alternate_path'],
      $language === 'es' ? 'English' : 'Espanol',
    ));

    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'public, max-age=3600',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
  }

  private function buildDocs(string $language, string $pageKey): Response {
    $module_path = $this->moduleExtensionList->getPath('nelkano_home');
    $page = $this->docsPage($language, $pageKey);
    $template = file_get_contents(DRUPAL_ROOT . '/' . $module_path . '/templates/nelkano-docs-standalone.html.twig');
    $html = \Drupal::service('twig')->createTemplate($template)->render([
      'page' => $page,
      'social_image_url' => $this->socialImageUrl($module_path),
      'css_inline' => $this->loadInlineCss($module_path),
      'analytics' => $this->analyticsSettings(),
    ] + $this->chromeContext(
      $module_path,
      $language,
      $page['alternate_path'],
      $language === 'es' ? 'English' : 'Espanol',
    ));

    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'public, max-age=3600',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
  }

  private function buildErrorPage(int $statusCode): Response {
    $module_path = $this->moduleExtensionList->getPath('nelkano_home');
    $language = $this->errorLanguage();
    $is_not_found = $statusCode === 404;
    $home_url = $language === 'en' ? '/en' : '/';
    $page = [
      'status' => (string) $statusCode,
      'title' => $is_not_found
        ? ($language === 'en' ? 'Page not found - Nelkano' : 'Pagina no encontrada - Nelkano')
        : ($language === 'en' ? 'Access denied - Nelkano' : 'Acceso denegado - Nelkano'),
      'eyebrow' => $is_not_found
        ? ($language === 'en' ? '404 error' : 'Error 404')
        : ($language === 'en' ? '403 error' : 'Error 403'),
      'heading' => $is_not_found
        ? ($language === 'en' ? 'This page does not exist' : 'Esta pagina no existe')
        : ($language === 'en' ? 'You cannot access this page' : 'No puedes acceder a esta pagina'),
      'intro' => $is_not_found
        ? ($language === 'en'
          ? 'The Nelkano page you are looking for may have moved, changed name or never existed.'
          : 'La pagina de Nelkano que buscas puede haberse movido, cambiado de nombre o no haber existido nunca.')
        : ($language === 'en'
          ? 'This area requires permission or an active Nelkano session. Sign in with the right account or return to the public site.'
          : 'Esta zona requiere permisos o una sesion activa de Nelkano. Inicia sesion con la cuenta correcta o vuelve a la web publica.'),
      'primary_label' => $language === 'en' ? 'Go to home' : 'Ir al inicio',
      'primary_url' => $home_url,
      'secondary_label' => $language === 'en' ? 'Contact' : 'Contacto',
      'secondary_url' => $language === 'en' ? '/en/contact' : '/contacto',
    ];
    if ($statusCode === 403) {
      $page['secondary_label'] = $language === 'en' ? 'Log in' : 'Iniciar sesion';
      $page['secondary_url'] = $language === 'en' ? '/en/user/login' : '/user/login';
    }

    $template = file_get_contents(DRUPAL_ROOT . '/' . $module_path . '/templates/nelkano-error-standalone.html.twig');
    $html = \Drupal::service('twig')->createTemplate($template)->render([
      'page' => $page,
      'css_inline' => $this->loadInlineCss($module_path),
      'analytics' => $this->analyticsSettings(),
    ] + $this->chromeContext(
      $module_path,
      $language,
      $language === 'en' ? '/pagina-no-encontrada' : '/en',
      $language === 'en' ? 'Espanol' : 'English',
    ));

    return new Response($html, $statusCode, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
  }

  private function errorLanguage(): string {
    $request_stack = \Drupal::requestStack();
    $main_request = $request_stack->getMainRequest();
    $path = $main_request ? $main_request->getPathInfo() : \Drupal::request()->getPathInfo();
    return str_starts_with($path, '/en') ? 'en' : 'es';
  }

  private function parseRows(string $value, array $keys): array {
    $rows = [];

    foreach (preg_split('/\R/', trim($value)) ?: [] as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }

      $parts = array_map('trim', explode('|', $line, count($keys)));
      $row = [];
      foreach ($keys as $index => $key) {
        $row[$key] = $parts[$index] ?? '';
      }
      $rows[] = $row;
    }

    return $rows;
  }

  private function legalPage(string $language, string $pageKey): array {
    $base_url = \Drupal::request()->getSchemeAndHttpHost();
    $pages = [
      'es' => [
        'legal_notice' => [
          'path' => '/aviso-legal',
          'alternate_path' => '/en/legal-notice',
          'title' => 'Aviso legal - Nelkano',
          'description' => 'Aviso legal de Nelkano: titularidad, condiciones de uso, propiedad intelectual, descargas y responsabilidad.',
          'eyebrow' => 'Aviso legal',
          'heading' => 'Aviso legal',
          'intro' => 'Esta pagina recoge las condiciones basicas de uso de la web y de las descargas publicadas para Nelkano.',
          'sections' => [
            [
              'title' => 'Datos del titular',
              'paragraphs' => [
                'Nelkano es una pagina informativa y de descarga de un proyecto de software gratuito. Actualmente no se venden productos o servicios desde la web, no se contratan planes y no se procesan pagos.',
                'Contacto legal y de privacidad: contacto@nelkano.com. Si el proyecto incorpora venta, contratacion, publicidad, formularios comerciales u otra actividad economica, esta seccion debera ampliarse con los datos identificativos que correspondan.',
              ],
            ],
            [
              'title' => 'Objeto del sitio',
              'paragraphs' => [
                'Nelkano es una web informativa y de descarga de una aplicacion de emulacion para Android. La web no ejecuta juegos en el navegador y no incluye ROMs, BIOS, firmware, saves, claves ni contenido protegido de terceros.',
                'El objetivo es ofrecer informacion clara sobre el estado del proyecto, publicar builds disponibles y facilitar el acceso a documentacion o avisos relevantes.',
              ],
            ],
            [
              'title' => 'Condiciones de uso',
              'paragraphs' => [
                'El usuario debe usar la web y las aplicaciones descargadas de forma licita, responsable y respetando la normativa aplicable, los derechos de propiedad intelectual e industrial y los derechos de terceros.',
                'Nelkano no autoriza ni promueve la descarga, distribucion o uso de copias no autorizadas de videojuegos, BIOS, firmware, marcas, imagenes o cualquier otro contenido protegido. El usuario es responsable de disponer de derechos suficientes sobre el software, copias de seguridad o archivos que cargue en la aplicacion.',
              ],
            ],
            [
              'title' => 'Descargas y compatibilidad',
              'paragraphs' => [
                'Las descargas publicadas pueden encontrarse en fase beta o desarrollo activo. Aunque se trabaja para mejorar estabilidad, compatibilidad y rendimiento, no se garantiza que todos los juegos, dispositivos, mandos o sistemas funcionen correctamente.',
                'La informacion sobre sistemas compatibles y compatibilidad debe entenderse como una descripcion del estado del proyecto en cada momento, no como una garantia permanente de funcionamiento.',
              ],
            ],
            [
              'title' => 'RPG Maker 2000/2003',
              'paragraphs' => [
                'El soporte para RPG Maker 2000/2003 se plantea como un core propio de Nelkano, en desarrollo progresivo, para integrarlo dentro de las aplicaciones nativas sin incluir runtimes externos.',
                'Nelkano no incluye juegos, RTP, assets comerciales ni contenido protegido de RPG Maker. El usuario debe usar solo proyectos y archivos sobre los que tenga derechos suficientes.',
              ],
            ],
            [
              'title' => 'Propiedad intelectual',
              'paragraphs' => [
                'El codigo, textos, diseno, logotipos, estructura y elementos propios de Nelkano pertenecen a sus titulares o se usan con la autorizacion correspondiente. Las marcas de consolas, sistemas y videojuegos citadas pertenecen a sus respectivos propietarios.',
                'Las menciones a sistemas clasicos se realizan con finalidad descriptiva de compatibilidad tecnica. Nelkano no esta afiliado, patrocinado, autorizado ni aprobado por Nintendo ni por otros titulares de marcas o derechos salvo que se indique expresamente.',
              ],
            ],
            [
              'title' => 'Responsabilidad',
              'paragraphs' => [
                'El titular no se responsabiliza del uso indebido de la web, de las aplicaciones o de archivos aportados por el usuario. Tampoco se responsabiliza de danos derivados de instalaciones fuera de las fuentes oficiales, modificaciones no autorizadas o uso en dispositivos no compatibles.',
                'Los enlaces externos, si existen, se facilitan como referencia. El titular no controla de forma permanente sus contenidos y retirara o corregira enlaces cuando tenga conocimiento efectivo de que pueden ser ilicitos o inadecuados.',
              ],
            ],
            [
              'title' => 'Ley aplicable',
              'paragraphs' => [
                'Esta pagina se ha redactado tomando como referencia la normativa espanola y europea aplicable a servicios de la sociedad de la informacion, proteccion de datos y cookies. Debe mantenerse actualizada si cambia el responsable, el modelo de servicio, las descargas, la publicidad, la analitica o las funciones de la aplicacion.',
              ],
            ],
          ],
        ],
        'privacy_cookies' => [
          'path' => '/privacidad-cookies',
          'alternate_path' => '/en/privacy-cookies',
          'title' => 'Privacidad y cookies - Nelkano',
          'description' => 'Politica de privacidad y cookies de Nelkano: datos tratados, finalidades, derechos y uso de cookies.',
          'eyebrow' => 'Privacidad y cookies',
          'heading' => 'Politica de privacidad y cookies',
          'intro' => 'Esta politica explica que datos puede tratar la web de Nelkano y como se gestionan las cookies.',
          'sections' => [
            [
              'title' => 'Responsable del tratamiento',
              'paragraphs' => [
                'Nelkano es una landing informativa y gratuita. No se venden productos o servicios desde esta web y no se procesan pagos.',
                'Contacto de privacidad: contacto@nelkano.com. El tratamiento de datos personales se limita principalmente a la navegacion tecnica, seguridad del servidor, descargas, registro de cuenta, verificacion por correo y comunicaciones que el usuario envie mediante la pagina de contacto o canales externos. Si se activan pagos o funciones comerciales, esta informacion debera actualizarse antes de su uso publico.',
              ],
            ],
            [
              'title' => 'Datos tratados y finalidades',
              'paragraphs' => [
                'La web puede tratar datos tecnicos de navegacion, como direccion IP, fecha y hora, identificadores de solicitud, agente de usuario y registros de seguridad, con la finalidad de servir la pagina, prevenir abusos, diagnosticar errores y mantener la disponibilidad.',
                'Si el usuario contacta por correo u otro canal indicado, se trataran los datos incluidos en esa comunicacion para responder la consulta, gestionar incidencias o atender solicitudes relacionadas con el proyecto.',
                'Las descargas publicas de la landing no requieren crear cuenta ni introducir datos personales. La aplicacion puede guardar en el dispositivo biblioteca local, rutas o nombres de archivos elegidos por el usuario, sistema asociado, configuracion, guardados, save states y datos tecnicos necesarios para abrir archivos compatibles.',
                'La sincronizacion con Google Drive es opcional y se vincula desde los ajustes de la aplicacion mediante la pantalla de autorizacion de Google. Al vincularla, Nelkano usa el permiso limitado drive.file para crear o acceder a los archivos del proyecto en Drive, no para leer todo el contenido de la cuenta.',
                'Cuando el usuario sube datos a Drive, la aplicacion crea o usa una carpeta de sincronizacion del proyecto, normalmente llamada Nelkano Sync, y guarda alli el manifiesto de biblioteca, archivos compatibles seleccionados por el usuario, guardados y save states. Esos datos se almacenan en la cuenta de Google del usuario; Nelkano no los recibe ni los almacena en servidores propios.',
                'La vinculacion puede deshacerse cerrando sesion en la aplicacion o revocando el acceso desde la cuenta de Google. La opcion Eliminar datos en la nube borra la carpeta de sincronizacion de Nelkano en Drive y conserva los datos locales del dispositivo.',
              ],
            ],
            [
              'title' => 'Base juridica',
              'paragraphs' => [
                'El tratamiento tecnico necesario para mostrar la web y proteger el servicio se basa en el interes legitimo de mantener la seguridad, integridad y disponibilidad del sitio.',
                'Las comunicaciones iniciadas por el usuario se tratan para atender su solicitud. El registro se usa para crear la cuenta, verificar el correo y permitir acceso a funciones de usuario. Las cookies o tecnologias no necesarias, como analitica o marketing, solo deben activarse cuando exista una base valida y, cuando sea exigible, consentimiento previo.',
              ],
            ],
            [
              'title' => 'Conservacion y destinatarios',
              'paragraphs' => [
                'Los registros tecnicos deben conservarse durante el tiempo necesario para seguridad, diagnostico y cumplimiento legal. Las comunicaciones y datos de verificacion de cuenta se conservaran mientras sean necesarios para responder, operar la cuenta de usuario y durante los plazos exigibles por responsabilidades legales. Los datos locales de la aplicacion permanecen en el dispositivo hasta que el usuario los borra, desinstala la aplicacion o usa las opciones de limpieza disponibles.',
                'Los datos pueden ser tratados por proveedores tecnicos de alojamiento, infraestructura, seguridad o mantenimiento, y por autoridades publicas cuando exista obligacion legal.',
              ],
            ],
            [
              'title' => 'Derechos de las personas',
              'paragraphs' => [
                'Las personas pueden solicitar acceso, rectificacion, supresion, oposicion, limitacion, portabilidad cuando proceda y retirada del consentimiento cuando el tratamiento dependa de el escribiendo a contacto@nelkano.com.',
                'Tambien pueden presentar una reclamacion ante la Agencia Espanola de Proteccion de Datos si consideran que el tratamiento no se ajusta a la normativa. Para borrar datos locales, el usuario puede eliminar la biblioteca/guardados desde la aplicacion cuando exista esa opcion, borrar los datos de la app o desinstalarla; para Google Drive, puede usar Eliminar datos en la nube, borrar la carpeta de sincronizacion o revocar el acceso desde su cuenta de Google.',
              ],
            ],
            [
              'title' => 'Cookies',
              'paragraphs' => [
                'En la landing publica se usan cookies tecnicas o necesarias para funcionamiento, seguridad o administracion de Drupal, especialmente sesiones de administracion. Estas cookies no requieren consentimiento cuando son estrictamente necesarias.',
                'La analitica de Google Analytics 4 solo se carga si el usuario acepta la medicion en el aviso de cookies. Si se rechaza, la web no carga Google Analytics para esa sesion de navegador.',
                'Cuando se acepta la analitica, se pueden medir visitas de pagina y eventos agregados como clics en descargas de Android. Estos datos ayudan a entender el uso de la web y mejorar el proyecto; no se usan para vender datos personales.',
                'La decision puede cambiarse desde el enlace Configurar cookies disponible en el pie de pagina.',
                'El usuario puede borrar o bloquear cookies desde la configuracion de su navegador. Al hacerlo, algunas funciones tecnicas de administracion o sesion podrian dejar de funcionar correctamente.',
              ],
            ],
            [
              'title' => 'Cambios de la politica',
              'paragraphs' => [
                'Esta politica puede actualizarse para reflejar cambios tecnicos, legales o de producto. La version publicada debe corresponder siempre con el funcionamiento real de la web.',
              ],
            ],
          ],
        ],
      ],
      'en' => [
        'legal_notice' => [
          'path' => '/en/legal-notice',
          'alternate_path' => '/aviso-legal',
          'title' => 'Legal notice - Nelkano',
          'description' => 'Legal notice for Nelkano: ownership, terms of use, intellectual property, downloads and liability.',
          'eyebrow' => 'Legal notice',
          'heading' => 'Legal notice',
          'intro' => 'This page sets out the basic terms for using the website and the downloads published for Nelkano.',
          'sections' => [
            [
              'title' => 'Website owner',
              'paragraphs' => [
                'Nelkano is an informational and download page for a free software project. The website currently does not sell products or services, offer paid plans or process payments.',
                'Legal and privacy contact: contacto@nelkano.com. If the project adds sales, subscriptions, advertising, commercial forms or any other economic activity, this section must be expanded with the required identifying information.',
              ],
            ],
            [
              'title' => 'Purpose of the website',
              'paragraphs' => [
                'Nelkano is an informational and download website for an emulation app for Android. The website does not run games in the browser and does not include ROMs, BIOS files, firmware, saves, keys or protected third-party content.',
                'Its purpose is to provide clear project status information, publish available builds and make relevant documentation or notices accessible.',
              ],
            ],
            [
              'title' => 'Terms of use',
              'paragraphs' => [
                'Users must use the website and downloaded applications lawfully, responsibly and in compliance with applicable rules, intellectual property rights and third-party rights.',
                'Nelkano does not authorize or promote downloading, distributing or using unauthorized copies of games, BIOS files, firmware, trademarks, images or any other protected content. Users are responsible for having sufficient rights over any software, backups or files they load into the app.',
              ],
            ],
            [
              'title' => 'Downloads and compatibility',
              'paragraphs' => [
                'Published downloads may be beta or actively developed builds. Although stability, compatibility and performance are continuously improved, not every game, device, controller or system is guaranteed to work correctly.',
                'Compatible system and compatibility information should be understood as a description of the project status at a given time, not as a permanent operating guarantee.',
              ],
            ],
            [
              'title' => 'RPG Maker 2000/2003',
              'paragraphs' => [
                'RPG Maker 2000/2003 support is planned as Nelkano own core, developed progressively, so it can be integrated into the native applications without bundling external runtimes.',
                'Nelkano does not include games, RTP, commercial RPG Maker assets or protected content. Users must only use projects and files they have sufficient rights to use.',
              ],
            ],
            [
              'title' => 'Intellectual property',
              'paragraphs' => [
                'Nelkano code, text, design, logos, structure and original materials belong to their respective owners or are used with permission. Console, system and game trademarks belong to their respective owners.',
                'References to classic systems are descriptive of technical compatibility. Nelkano is not affiliated with, sponsored, authorized or approved by Nintendo or any other trademark or rights holder unless expressly stated.',
              ],
            ],
            [
              'title' => 'Liability',
              'paragraphs' => [
                'The owner is not responsible for misuse of the website, applications or files provided by users. The owner is also not responsible for damage caused by installations from unofficial sources, unauthorized modifications or unsupported devices.',
                'External links, if any, are provided for reference. Their content is not permanently controlled and links will be removed or corrected when there is actual knowledge that they may be unlawful or inappropriate.',
              ],
            ],
          ],
        ],
        'privacy_cookies' => [
          'path' => '/en/privacy-cookies',
          'alternate_path' => '/privacidad-cookies',
          'title' => 'Privacy and cookies - Nelkano',
          'description' => 'Privacy and cookies policy for Nelkano: data processing, purposes, rights and cookie use.',
          'eyebrow' => 'Privacy and cookies',
          'heading' => 'Privacy and cookies policy',
          'intro' => 'This policy explains what data the Nelkano website may process and how cookies are handled.',
          'sections' => [
            [
              'title' => 'Controller',
              'paragraphs' => [
                'Nelkano is a free informational landing page. The website does not sell products or services or process payments.',
                'Privacy contact: contacto@nelkano.com. Personal data processing is mainly limited to technical browsing, server security, downloads, account registration, email verification and communications sent by users through the contact page or external channels. If payments or commercial features are enabled, this information must be updated before public use.',
              ],
            ],
            [
              'title' => 'Data processed and purposes',
              'paragraphs' => [
                'The website may process technical browsing data such as IP address, date and time, request identifiers, user agent and security logs to serve the page, prevent abuse, diagnose errors and maintain availability.',
                'If users contact the project by email or another stated channel, the data included in that communication will be processed to answer the request, manage issues or handle project-related queries.',
                'Public downloads on this landing page do not require an account or personal data. The app may store on the device the local library, paths or names of files chosen by the user, associated system, settings, saves, save states and technical data needed to open compatible files.',
                'Google Drive synchronization is optional and is linked from the app settings through the Google authorization screen. When linked, Nelkano uses the limited drive.file permission to create or access project files in Drive, not to read the full contents of the account.',
                'When users upload data to Drive, the app creates or uses a project sync folder, usually named Nelkano Sync, and stores the library manifest, compatible files selected by the user, saves and save states there. Those files are stored in the user Google account; Nelkano does not receive or store them on its own servers.',
                'Linking can be undone by signing out in the app or revoking access from the Google account. The Delete cloud data option removes the Nelkano sync folder from Drive and keeps local device data intact.',
              ],
            ],
            [
              'title' => 'Legal basis',
              'paragraphs' => [
                'Technical processing required to display and protect the website is based on the legitimate interest in maintaining security, integrity and availability.',
                'User-initiated communications are processed to answer the request. Registration is used to create the account, verify email and allow access to user features. Non-essential cookies or technologies, such as analytics or marketing, should only be enabled with a valid legal basis and, when required, prior consent.',
              ],
            ],
            [
              'title' => 'Retention and recipients',
              'paragraphs' => [
                'Technical logs should be retained only as long as necessary for security, diagnostics and legal compliance. Communications and account verification data will be kept while needed to respond, operate the user account and for legally required liability periods. Local app data remains on the device until the user deletes it, uninstalls the app or uses available cleanup options.',
                'Data may be processed by hosting, infrastructure, security or maintenance providers, and by public authorities where legally required.',
              ],
            ],
            [
              'title' => 'Data subject rights',
              'paragraphs' => [
                'Individuals may request access, rectification, erasure, objection, restriction, portability where applicable and withdrawal of consent when processing depends on it by writing to contacto@nelkano.com.',
                'They may also lodge a complaint with the Spanish Data Protection Agency if they consider that processing does not comply with the law. To delete local data, users can remove the library/saves from the app when available, clear app data or uninstall it; for Google Drive, they can use Delete cloud data, delete the sync folder or revoke access from their Google account.',
              ],
            ],
            [
              'title' => 'Cookies',
              'paragraphs' => [
                'The public landing page uses technical or strictly necessary cookies for Drupal operation, security or administration, especially administration sessions. Strictly necessary cookies do not require consent.',
                'Google Analytics 4 only loads if the user accepts measurement in the cookie notice. If rejected, the website does not load Google Analytics for that browser session.',
                'When analytics are accepted, page views and aggregated events such as Android download clicks may be measured. This helps understand website usage and improve the project; it is not used to sell personal data.',
                'The decision can be changed from the Cookie settings link available in the footer.',
                'Users can delete or block cookies in their browser settings. Doing so may affect technical administration or session features.',
              ],
            ],
            [
              'title' => 'Policy changes',
              'paragraphs' => [
                'This policy may be updated to reflect technical, legal or product changes. The published version should always match the website actual behavior.',
              ],
            ],
          ],
        ],
      ],
    ];

    $page = $this->configuredLegalPage($pages[$language][$pageKey], $language, $pageKey);
    $page['canonical'] = $base_url . $page['path'];
    $page['alternate'] = $base_url . $page['alternate_path'];
    $page['alternate_lang'] = $language === 'es' ? 'en' : 'es';
    $page['default_url'] = $language === 'es' ? $page['canonical'] : $page['alternate'];
    return $page;
  }

  private function configuredLegalPage(array $page, string $language, string $pageKey): array {
    $content = $this->homeConfigFactory->get('nelkano_home.docs')->get($language) ?? [];
    $title = trim((string) ($content[$pageKey . '_seo_title'] ?? ''));
    $description = trim((string) ($content[$pageKey . '_seo_description'] ?? ''));
    $eyebrow = trim((string) ($content[$pageKey . '_eyebrow'] ?? ''));
    $heading = trim((string) ($content[$pageKey . '_title'] ?? ''));
    $intro = trim((string) ($content[$pageKey . '_intro'] ?? ''));
    $sections = $this->parseLegalSections($content[$pageKey . '_sections'] ?? '');

    if ($title !== '') {
      $page['title'] = $title;
    }
    if ($description !== '') {
      $page['description'] = $description;
    }
    if ($eyebrow !== '') {
      $page['eyebrow'] = $eyebrow;
    }
    if ($heading !== '') {
      $page['heading'] = $heading;
    }
    if ($intro !== '') {
      $page['intro'] = $intro;
    }
    if ($sections !== []) {
      $page['sections'] = $sections;
    }

    return $page;
  }

  private function parseLegalSections(string $value): array {
    $sections = [];
    foreach (preg_split('/\R/', trim($value)) ?: [] as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      [$title, $paragraphs] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
      if ($title === '') {
        continue;
      }
      $sections[] = [
        'title' => $title,
        'paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\s*\|\|\s*/', $paragraphs) ?: []))),
      ];
    }
    return $sections;
  }

  private function docsPage(string $language, string $pageKey): array {
    $base_url = \Drupal::request()->getSchemeAndHttpHost();
    $config = $this->homeConfigFactory->get('nelkano_home.docs');
    $content = $config->get($language) ?? [];
    $paths = [
      'releases' => [
        'es' => '/versiones',
        'en' => '/en/releases',
      ],
      'security' => [
        'es' => '/seguridad-privacidad',
        'en' => '/en/security-privacy',
      ],
    ];

    $prefix = $pageKey;
    $page = [
      'type' => $pageKey,
      'path' => $paths[$pageKey][$language],
      'alternate_path' => $paths[$pageKey][$language === 'es' ? 'en' : 'es'],
      'title' => trim((string) ($content[$prefix . '_seo_title'] ?? 'Nelkano')),
      'description' => trim((string) ($content[$prefix . '_seo_description'] ?? '')),
      'eyebrow' => trim((string) ($content[$prefix . '_eyebrow'] ?? 'Nelkano')),
      'heading' => trim((string) ($content[$prefix . '_title'] ?? 'Nelkano')),
      'intro' => trim((string) ($content[$prefix . '_intro'] ?? '')),
    ];

    if ($pageKey === 'releases') {
      $filename = trim((string) ($content['releases_filename'] ?? ''));
      $download_path = $filename !== '' ? DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('nelkano_home') . '/emulator/' . $filename : '';
      $download_meta = is_file($download_path) ? $this->downloadMeta($download_path) : [];
      $page['release'] = [
        'version' => trim((string) ($content['releases_version'] ?? '')),
        'filename' => $filename,
        'date' => trim((string) ($content['releases_date'] ?? '')),
        'requirements' => trim((string) ($content['releases_requirements'] ?? '')),
        'changes' => $this->parseLines($content['releases_changes'] ?? ''),
        'notice' => trim((string) ($content['releases_notice'] ?? '')),
        'url' => $filename !== '' ? '/' . $this->moduleExtensionList->getPath('nelkano_home') . '/emulator/' . rawurlencode($filename) : '',
        'meta' => $download_meta,
      ];
    }
    else {
      $page['sections'] = $this->parseRows($content['security_sections'] ?? '', ['title', 'description']);
    }

    $page['canonical'] = $base_url . $page['path'];
    $page['alternate'] = $base_url . $page['alternate_path'];
    $page['alternate_lang'] = $language === 'es' ? 'en' : 'es';
    $page['default_url'] = $language === 'es' ? $page['canonical'] : $page['alternate'];
    $page['locale'] = $language === 'es' ? 'es_ES' : 'en_US';
    $page['alternate_locale'] = $language === 'es' ? 'en_US' : 'es_ES';
    return $page;
  }

  private function parseLines(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', trim($value)) ?: [])));
  }

  private function analyticsSettings(): array {
    return [
      'ga_id' => self::GA_MEASUREMENT_ID,
    ];
  }

  private function resolveDownload(string $configuredUrl, string $modulePath, string $extension): array {
    $trimmed = trim($configuredUrl);
    if ($trimmed !== '' && $trimmed !== '#') {
      $relative_path = rawurldecode(parse_url($trimmed, PHP_URL_PATH) ?: '');
      $local_prefix = '/' . $modulePath . '/emulator/';
      $local_path = str_starts_with($relative_path, $local_prefix)
        ? DRUPAL_ROOT . $relative_path
        : '';

      return [
        'url' => $trimmed,
        'meta' => $local_path !== '' && is_file($local_path) ? $this->downloadMeta($local_path) : [],
      ];
    }

    $matches = glob(DRUPAL_ROOT . '/' . $modulePath . '/emulator/*.' . $extension) ?: [];
    if ($matches === []) {
      return [
        'url' => '',
        'meta' => [],
      ];
    }

    usort($matches, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));
    $path = $matches[0];
    return [
      'url' => '/' . $modulePath . '/emulator/' . rawurlencode(basename($path)),
      'meta' => $this->downloadMeta($path),
    ];
  }

  private function downloadMeta(string $path): array {
    $filename = basename($path);
    $version = $this->publicVersion();
    if (preg_match('/v([0-9][A-Za-z0-9.\-]*)/', $filename, $matches) === 1) {
      $version = $matches[1];
    }

    return [
      'filename' => $filename,
      'version' => $version,
      'size' => $this->formatBytes((int) filesize($path)),
      'sha256' => strtoupper(hash_file('sha256', $path)),
    ];
  }

  private function publicVersion(): string {
    $version = trim((string) ($this->homeConfigFactory->get('nelkano_home.docs')->get('es.releases_version') ?? ''));
    return $version !== '' ? $version : '1.0.0-beta';
  }

  private function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = max(0, $bytes);
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
      $value /= 1024;
      $index++;
    }

    return ($index === 0 ? (string) $value : number_format($value, 1)) . ' ' . $units[$index];
  }

  private function loadInlineCss(string $modulePath): string {
    $css = (file_get_contents(DRUPAL_ROOT . '/' . $modulePath . '/css/base.css') ?: '')
      . "\n" . (file_get_contents(DRUPAL_ROOT . '/' . $modulePath . '/css/header.css') ?: '')
      . "\n" . (file_get_contents(DRUPAL_ROOT . '/' . $modulePath . '/css/footer.css') ?: '')
      . "\n" . (file_get_contents(DRUPAL_ROOT . '/' . $modulePath . '/css/landing.css') ?: '');
    $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
    $css = preg_replace('/\s+/', ' ', $css) ?? $css;
    $css = str_replace([' {', '{ ', '; ', ': ', ', '], ['{', '{', ';', ':', ','], $css);
    return trim($css);
  }

  private function socialImageUrl(string $modulePath): string {
    return \Drupal::request()->getSchemeAndHttpHost() . '/' . $modulePath . '/assets/' . self::SOCIAL_IMAGE_FILENAME;
  }

  private function streamWebSocketUrl(): string {
    $request = \Drupal::request();
    $host = strtolower($request->getHost());
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
      return 'ws://localhost:3001/ws/rtc';
    }

    $scheme = $request->isSecure() ? 'wss' : 'ws';
    return $scheme . '://' . $request->getHost() . '/ws/rtc';
  }

  private function streamIceServersJson(): string {
    $raw = trim((string) getenv('NELKANO_STREAM_ICE_SERVERS'));
    if ($raw !== '') {
      json_decode($raw, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $raw;
      }
    }

    return json_encode([
      ['urls' => 'stun:stun.l.google.com:19302'],
    ], JSON_UNESCAPED_SLASHES);
  }

  private function streamIcePolicy(): string {
    $policy = strtolower(trim((string) getenv('NELKANO_STREAM_ICE_POLICY')));
    return in_array($policy, ['all', 'relay'], TRUE) ? $policy : 'all';
  }

  private function buildSeo(string $language, array $content): array {
    $request = \Drupal::request();
    $base_url = $request->getSchemeAndHttpHost();
    $canonical_path = $language === 'es' ? '/' : '/en';
    $canonical = $base_url . $canonical_path;
    $alternate = $base_url . ($language === 'es' ? '/en' : '/');
    $social_image = $base_url . '/' . $this->moduleExtensionList->getPath('nelkano_home') . '/assets/' . self::SOCIAL_IMAGE_FILENAME;
    $title = trim($content['seo_title'] ?? '') ?: trim($content['hero_title'] ?? 'Nelkano');
    $description = trim($content['seo_description'] ?? '') ?: trim($content['hero_description'] ?? '');
    $keywords = trim($content['seo_keywords'] ?? '');
    $locale = $language === 'es' ? 'es_ES' : 'en_US';
    $alternate_locale = $language === 'es' ? 'en_US' : 'es_ES';
    $app_category = $language === 'es' ? 'Emulador de videojuegos' : 'Video game emulator';

    $faq_entities = [];
    foreach ($content['faq_items'] ?? [] as $item) {
      if (($item['question'] ?? '') === '' || ($item['answer'] ?? '') === '') {
        continue;
      }
      $faq_entities[] = [
        '@type' => 'Question',
        'name' => $item['question'],
        'acceptedAnswer' => [
          '@type' => 'Answer',
          'text' => $item['answer'],
        ],
      ];
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@graph' => [
        [
          '@type' => 'WebSite',
          'name' => 'Nelkano',
          'url' => $base_url . '/',
          'inLanguage' => $language,
          'description' => $description,
        ],
        [
          '@type' => 'SoftwareApplication',
          'name' => 'Nelkano',
          'applicationCategory' => $app_category,
        'operatingSystem' => 'Android',
          'offers' => [
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'EUR',
          ],
          'softwareVersion' => $this->publicVersion(),
          'description' => $description,
          'url' => $canonical,
        ],
      ],
    ];

    if ($faq_entities !== []) {
      $schema['@graph'][] = [
        '@type' => 'FAQPage',
        'mainEntity' => $faq_entities,
      ];
    }

    $head = [
      [['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => $description]], 'nelkano_description'],
      [['#tag' => 'meta', '#attributes' => ['name' => 'robots', 'content' => 'index, follow, max-image-preview:large']], 'nelkano_robots'],
      [['#tag' => 'link', '#attributes' => ['rel' => 'canonical', 'href' => $canonical]], 'nelkano_canonical'],
      [['#tag' => 'link', '#attributes' => ['rel' => 'alternate', 'hreflang' => $language, 'href' => $canonical]], 'nelkano_alternate_current'],
      [['#tag' => 'link', '#attributes' => ['rel' => 'alternate', 'hreflang' => $language === 'es' ? 'en' : 'es', 'href' => $alternate]], 'nelkano_alternate_other'],
      [['#tag' => 'link', '#attributes' => ['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $base_url . '/']], 'nelkano_alternate_default'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:type', 'content' => 'website']], 'nelkano_og_type'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:site_name', 'content' => 'Nelkano']], 'nelkano_og_site_name'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:title', 'content' => $title]], 'nelkano_og_title'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:description', 'content' => $description]], 'nelkano_og_description'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:url', 'content' => $canonical]], 'nelkano_og_url'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:image', 'content' => $social_image]], 'nelkano_og_image'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:image:secure_url', 'content' => $social_image]], 'nelkano_og_image_secure'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:image:type', 'content' => 'image/png']], 'nelkano_og_image_type'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:image:width', 'content' => '1200']], 'nelkano_og_image_width'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:image:height', 'content' => '630']], 'nelkano_og_image_height'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:image:alt', 'content' => 'Nelkano']], 'nelkano_og_image_alt'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:locale', 'content' => $locale]], 'nelkano_og_locale'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:locale:alternate', 'content' => $alternate_locale]], 'nelkano_og_locale_alternate'],
      [['#tag' => 'meta', '#attributes' => ['name' => 'twitter:card', 'content' => 'summary_large_image']], 'nelkano_twitter_card'],
      [['#tag' => 'meta', '#attributes' => ['name' => 'twitter:title', 'content' => $title]], 'nelkano_twitter_title'],
      [['#tag' => 'meta', '#attributes' => ['name' => 'twitter:description', 'content' => $description]], 'nelkano_twitter_description'],
      [['#tag' => 'meta', '#attributes' => ['name' => 'twitter:image', 'content' => $social_image]], 'nelkano_twitter_image'],
      [['#tag' => 'meta', '#attributes' => ['name' => 'twitter:image:alt', 'content' => 'Nelkano']], 'nelkano_twitter_image_alt'],
      [['#tag' => 'script', '#attributes' => ['type' => 'application/ld+json'], '#value' => json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], 'nelkano_json_ld'],
    ];

    if ($keywords !== '') {
      $head[] = [['#tag' => 'meta', '#attributes' => ['name' => 'keywords', 'content' => $keywords]], 'nelkano_keywords'];
    }

    return [
      'title' => $title,
      'description' => $description,
      'keywords' => $keywords,
      'canonical' => $canonical,
      'alternate' => $alternate,
      'alternate_lang' => $language === 'es' ? 'en' : 'es',
      'social_image' => $social_image,
      'locale' => $locale,
      'alternate_locale' => $alternate_locale,
      'schema_json' => json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'html_head' => $head,
    ];
  }

}
