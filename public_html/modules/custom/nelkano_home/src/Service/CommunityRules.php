<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CommunityRules {
  public const VERSION = '2026-11-24.1';
  public const FIELD_VERSION = 'field_community_rules_version';
  public const FIELD_ACCEPTED_AT = 'field_community_rules_accepted';
  public const FIELD_WITHDRAWN_AT = 'field_community_rules_withdrawn';

  public static function accepted(int $uid): bool {
    $account = $uid > 0 ? \Drupal::entityTypeManager()->getStorage('user')->load($uid) : NULL;
    return self::hasAccepted($account);
  }

  private static function hasAccepted(?UserInterface $account): bool {
    return $account && $account->hasField(self::FIELD_VERSION)
      && $account->get(self::FIELD_VERSION)->value === self::VERSION
      && (int) $account->get(self::FIELD_ACCEPTED_AT)->value > 0
      && $account->get(self::FIELD_WITHDRAWN_AT)->isEmpty();
  }

  public static function accept(int $uid, string $version): bool {
    return self::setPreference($uid, TRUE, $version);
  }

  public static function state(int $uid): array {
    return ['version' => self::VERSION, 'accepted' => self::accepted($uid)];
  }

  public static function setPreference(int $uid, bool $accepted, string $version): bool {
    if ($uid < 1 || $version !== self::VERSION) {
      return FALSE;
    }
    $lock = \Drupal::lock();
    $key = AccountErasure::lockKey($uid);
    if (!$lock->acquire($key, 30)) {
      return FALSE;
    }
    try {
      $account = \Drupal::entityTypeManager()->getStorage('user')->loadUnchanged($uid);
      if (!$account || !$account->isActive() || !$account->hasField(self::FIELD_VERSION)) {
        return FALSE;
      }
      if ($accepted && !self::hasAccepted($account)) {
        self::record($account, $version, \Drupal::time()->getRequestTime());
      }
      elseif (!$accepted && self::hasAccepted($account)) {
        $account->set(self::FIELD_WITHDRAWN_AT, \Drupal::time()->getRequestTime());
        $account->save();
      }
      return TRUE;
    }
    finally {
      $lock->release($key);
    }
  }

  /** Sets evidence on the account so it can be saved in the same operation. */
  public static function setAcceptance(UserInterface $account, string $version, int $accepted_at): void {
    $account->set(self::FIELD_VERSION, $version);
    $account->set(self::FIELD_ACCEPTED_AT, $accepted_at);
    $account->set(self::FIELD_WITHDRAWN_AT, NULL);
  }

  public static function record(UserInterface $account, string $version, int $accepted_at): void {
    self::setAcceptance($account, $version, $accepted_at);
    $account->save();
  }

  public static function requireAcceptance(int $uid): void {
    if (!self::accepted($uid)) {
      throw new AccessDeniedHttpException('Acepta las normas de Comunidad en tu perfil antes de publicar, comentar o enviar mensajes. /user');
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
