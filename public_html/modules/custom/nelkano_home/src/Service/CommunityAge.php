<?php

declare(strict_types=1);

namespace Drupal\nelkano_home\Service;

use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CommunityAge {

  public const FIELD = 'field_nelkano_birth_date';
  public const MISSING = 'missing';
  public const UNDERAGE = 'underage';
  public const ADULT = 'adult';

  public static function today(): string {
    return (new \DateTimeImmutable('@' . \Drupal::time()->getRequestTime()))
      ->setTimezone(new \DateTimeZone('Europe/Madrid'))->format('Y-m-d');
  }

  public static function isValid(mixed $value): bool {
    if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)) {
      return FALSE;
    }
    return $parts[1] >= 1900 && $value <= self::today() && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
  }

  public static function statusForDate(mixed $value): string {
    if (!self::isValid($value)) {
      return self::MISSING;
    }
    $birthday = new \DateTimeImmutable($value, new \DateTimeZone('Europe/Madrid'));
    return $birthday->modify('+18 years')->format('Y-m-d') <= self::today() ? self::ADULT : self::UNDERAGE;
  }

  public static function birthDate(?UserInterface $account): string {
    return $account && $account->hasField(self::FIELD) ? (string) $account->get(self::FIELD)->value : '';
  }

  public static function status(int $uid): string {
    return self::statusForDate(self::birthDate($uid > 0 ? User::load($uid) : NULL));
  }

  public static function requireAdult(int $uid): void {
    $status = self::status($uid);
    if ($status !== self::ADULT) {
      throw new AccessDeniedHttpException(self::message($status));
    }
  }

  public static function message(string $status, bool $en = FALSE): string {
    return $status === self::UNDERAGE
      ? ($en ? 'Community is available when you turn 18.' : 'Todavía no tienes 18 años. Podrás entrar en Comunidad cuando los cumplas.')
      : ($en ? 'Add your date of birth to your profile to access Community.' : 'Añade tu fecha de nacimiento en tu perfil para acceder a Comunidad.');
  }

  public static function input(bool $en, bool $required = FALSE): array {
    return [
      '#type' => 'date',
      '#title' => $en ? 'Date of birth' : 'Fecha de nacimiento',
      '#required' => $required,
      '#attributes' => ['autocomplete' => 'bday', 'min' => '1900-01-01', 'max' => self::today()],
      '#description' => $en ? 'Private. Used to determine whether you can access Community.' : 'Es privada y se usa para determinar si puedes acceder a Comunidad.',
    ];
  }

  public static function saveOwnDate(int $uid, string $date): bool {
    if ((int) \Drupal::currentUser()->id() !== $uid || $uid < 1 || !self::isValid($date)) {
      return FALSE;
    }
    $lock = \Drupal::lock();
    $key = 'nelkano_birth_date:' . $uid;
    if (!$lock->acquire($key)) {
      return FALSE;
    }
    try {
      $account = \Drupal::entityTypeManager()->getStorage('user')->loadUnchanged($uid);
      if (!$account instanceof UserInterface || self::birthDate($account) !== '') {
        return FALSE;
      }
      $account->set(self::FIELD, $date)->save();
      return TRUE;
    }
    finally {
      $lock->release($key);
    }
  }

}
