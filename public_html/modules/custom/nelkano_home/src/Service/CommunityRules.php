<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CommunityRules {
  public const VERSION = '2026-11-24.1';

  public static function accepted(int $uid): bool {
    return $uid > 0 && self::VERSION === (\Drupal::service('user.data')->get('nelkano_home', $uid, 'community_rules')['version'] ?? NULL);
  }

  public static function accept(int $uid, string $version): bool {
    if ($uid < 1 || $version !== self::VERSION) {
      return FALSE;
    }
    if (!self::accepted($uid)) {
      \Drupal::service('user.data')->set('nelkano_home', $uid, 'community_rules', [
        'version' => self::VERSION,
        'accepted_at' => \Drupal::time()->getRequestTime(),
      ]);
    }
    return TRUE;
  }

  public static function requireAcceptance(int $uid): void {
    if (!self::accepted($uid)) {
      throw new AccessDeniedHttpException('Debes aceptar las normas de Comunidad antes de publicar, comentar o enviar mensajes. /comunidad/normas');
    }
  }

  public static function copy(bool $english): array {
    return $english ? [
      'Community rules',
      'These rules apply to posts, comments, images, profiles and messages, including group chats.',
      'No harassment, bullying, hate, threats or incitement to violence. No sexual or exploitative content, child sexual abuse material or attempts to groom minors.',
      'Do not impersonate people, send spam, promote scams, distribute malware, or disclose someone else’s personal information without permission.',
      'Share only content you own or have permission to share. Respect copyright, trademarks and privacy. You keep your rights and allow Nelkano to display your content to the audience you choose until you remove it or delete your account.',
      'Use Report on a post, comment, or profile and Block to stop contact from an account. To report unlawful content or a copyright violation, email contacto@nelkano.com with a link or identifier and an explanation.',
      'Nelkano reviews reports and may limit visibility, remove content, or restrict or suspend accounts. For a decision about your content or account, you can ask for an explanation or review at contacto@nelkano.com. If someone is in immediate danger, contact emergency services.',
    ] : [
      'Normas de Comunidad',
      'Estas normas se aplican a publicaciones, comentarios, imágenes, perfiles y mensajes, incluidos los grupos.',
      'Se prohíben el acoso, el odio, las amenazas y la incitación a la violencia. También se prohíben el contenido sexual o de explotación, el material de abuso sexual infantil y los intentos de captar a menores.',
      'No suplantes a otras personas, envíes spam, promuevas estafas, distribuyas software malicioso ni difundas datos personales de otras personas sin permiso.',
      'Comparte solo contenido propio o que tengas permiso para compartir. Respeta los derechos de autor, las marcas y la privacidad. Conservas tus derechos y autorizas a Nelkano a mostrar el contenido a la audiencia que elijas hasta que lo retires o borres tu cuenta.',
      'Usa Denunciar en una publicación, comentario o perfil y Bloquear para impedir que una cuenta contacte contigo. Para avisar de contenido ilícito o de una infracción de derechos de autor, escribe a contacto@nelkano.com e incluye el enlace o identificador y el motivo.',
      'Nelkano revisa las denuncias y puede limitar la visibilidad, retirar contenido o restringir o suspender cuentas. Si la decisión afecta a tu contenido o cuenta, puedes pedir una explicación o revisión en contacto@nelkano.com. Si alguien corre peligro inmediato, contacta con emergencias.',
    ];
  }
}
