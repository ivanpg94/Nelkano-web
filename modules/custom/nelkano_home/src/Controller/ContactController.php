<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

final class ContactController extends ControllerBase {

  use NelkanoPageContextTrait;

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('extension.list.module'));
  }

  public function page(): Response {
    $language = $this->requestLanguage();
    $module_path = $this->moduleExtensionList->getPath('nelkano_home');
    $template = file_get_contents(DRUPAL_ROOT . '/' . $module_path . '/templates/nelkano-contact-standalone.html.twig');
    $renderer = \Drupal::service('renderer');
    $messages = ['#type' => 'status_messages'];
    $form = $this->formBuilder()->getForm('Drupal\nelkano_home\Form\NelkanoContactForm', $language);
    $rendered_messages = $renderer->renderRoot($messages);
    $rendered_form = $renderer->renderRoot($form);
    $html = \Drupal::service('twig')->createTemplate($template)->render([
      'messages' => $rendered_messages,
      'form' => $rendered_form,
      'base_css_url' => '/' . $module_path . '/css/base.css',
      'contact_css_url' => '/' . $module_path . '/css/contact.css',
      'page_title' => $language === 'en' ? 'Contact' : 'Contacto',
      'contact_kicker' => $language === 'en' ? 'Support and suggestions' : 'Soporte y propuestas',
      'contact_form_kicker' => $language === 'en' ? 'Clear details help' : 'Los detalles ayudan',
      'contact_intro' => $language === 'en'
        ? 'Tell me what you need and help me improve Nelkano with clear details.'
        : 'Cuentame que necesitas y ayudame a mejorar Nelkano con informacion clara.',
      'contact_points' => $language === 'en'
        ? ['Technical issues: include platform, version and steps.', 'Suggestions: explain the use case you want to improve.', 'Do not send ROMs, BIOS or protected content.']
        : ['Errores tecnicos: incluye plataforma, version y pasos.', 'Propuestas: explica el caso de uso que quieres mejorar.', 'No envies ROMs, BIOS ni contenido protegido.'],
    ] + $this->chromeContext(
      $module_path,
      $language,
      $language === 'en' ? '/contacto' : '/en/contact',
      $language === 'en' ? 'Espanol' : 'English',
    ));

    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
  }

}
