<?php

namespace Drupal\nelkano_home\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\RendererInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProfileController extends ControllerBase {

  use NelkanoPageContextTrait;

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly RendererInterface $renderer,
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('extension.list.module'),
      $container->get('renderer'),
      $container->get('database'),
    );
  }

  public function current(): Response {
    $language = $this->requestLanguage();
    if (!$this->currentUser()->isAuthenticated()) {
      return new RedirectResponse($language === 'en' ? '/en/user/login' : '/user/login');
    }

    $account = User::load((int) $this->currentUser()->id());
    if (!$account instanceof UserInterface) {
      return new RedirectResponse($language === 'en' ? '/en/user/login' : '/user/login');
    }

    return $this->view($account);
  }

  public function view(UserInterface $user): Response {
    $language = $this->requestLanguage();
    if (!$this->currentUser()->isAuthenticated()) {
      return new RedirectResponse($language === 'en' ? '/en/user/login' : '/user/login');
    }

    $viewer = User::load((int) $this->currentUser()->id());
    if (!$viewer instanceof UserInterface) {
      return new RedirectResponse($language === 'en' ? '/en/user/login' : '/user/login');
    }

    $template = file_get_contents(DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('nelkano_home') . '/templates/nelkano-profile-standalone.html.twig');
    $messages = ['#type' => 'status_messages'];
    $data = $this->viewData($user, $viewer, $language === 'en' ? 'profile' : 'perfil', $language);
    $html = \Drupal::service('twig')->createTemplate($template)->render($data + [
      'messages' => $this->renderer->renderRoot($messages),
      'header_css_url' => '/' . $this->moduleExtensionList->getPath('nelkano_home') . '/css/header.css',
      'account_css_url' => '/' . $this->moduleExtensionList->getPath('nelkano_home') . '/css/account.css',
      'section_html' => $this->renderSection($data),
    ] + $this->chromeContext(
      $this->moduleExtensionList->getPath('nelkano_home'),
      $language,
      $language === 'en' ? '/user' : '/en/user',
      $language === 'en' ? 'Espanol' : 'English',
    ));

    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
  }

  public function edit(UserInterface $user): Response {
    return $this->view($user);
  }

  public function section(string $section, Request $request): Response {
    if (!$this->currentUser()->isAuthenticated()) {
      return new Response('', 403);
    }

    $viewer = User::load((int) $this->currentUser()->id());
    if (!$viewer instanceof UserInterface) {
      return new Response('', 403);
    }

    $language = $this->requestLanguage();
    $section = $language === 'en' && $section === 'friends' ? 'amigos' : ($language === 'en' ? 'perfil' : $section);
    $html = $this->renderSection($this->viewData($viewer, $viewer, $section, $language));
    return new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
    ]);
  }

  private function renderForm(string $class, mixed ...$args): string {
    $form = $this->formBuilder()->getForm($class, ...$args);
    return (string) $this->renderer->renderRoot($form);
  }

  private function renderSection(array $data): string {
    $template = file_get_contents(DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('nelkano_home') . '/templates/nelkano-profile-section.html.twig');
    return \Drupal::service('twig')->createTemplate($template)->render($data);
  }

  private function viewData(UserInterface $user, UserInterface $viewer, string $section = 'perfil', string $language = 'es'): array {
    $this->touchOnline((int) $viewer->id());
    $profile = $this->profileData($user);
    $viewer_profile = $this->profileData($viewer);
    $is_owner = (int) $viewer->id() === (int) $user->id();
    $relationship = $is_owner ? NULL : $this->relationship((int) $viewer->id(), (int) $user->id());

    return [
      'section' => $section,
      'language' => $language,
      'profile' => $profile,
      'viewer' => $viewer_profile,
      'is_owner' => $is_owner,
      'relationship' => $relationship,
      'online' => $this->isOnline((int) $user->id()),
      'member_for' => $this->memberFor((int) $user->getCreatedTime(), $language),
      'friends' => $this->friends((int) $user->id()),
      'incoming' => $is_owner ? $this->pendingIncoming((int) $user->id()) : [],
      'outgoing' => $is_owner ? $this->pendingOutgoing((int) $user->id()) : [],
      'account_form' => $is_owner ? $this->renderForm('Drupal\nelkano_home\Form\AccountSettingsForm', $user, $language) : '',
      'profile_form' => $is_owner ? $this->renderForm('Drupal\nelkano_home\Form\ProfileSettingsForm', $user, $language) : '',
      'invite_form' => $is_owner ? $this->renderForm('Drupal\nelkano_home\Form\FriendInviteForm') : '',
      'send_form' => (!$is_owner && !$relationship) ? $this->renderForm('Drupal\nelkano_home\Form\FriendSendForm', $user) : '',
      'activity' => $is_owner ? $this->activitySummary((int) $user->id(), $language) : $this->emptyActivitySummary(),
    ];
  }

  private function activitySummary(int $uid, string $language): array {
    if (!$this->database->schema()->tableExists('nelkano_library_item')) {
      return $this->emptyActivitySummary();
    }

    $summary_query = $this->database->select('nelkano_library_item', 'i')
      ->condition('uid', $uid)
      ->condition('deleted_at', 0);
    $summary_query->addExpression('COALESCE(SUM(total_play_ms), 0)', 'total_play_ms');
    $summary_query->addExpression('COALESCE(SUM(play_count), 0)', 'session_count');
    $summary_query->addExpression('COUNT(DISTINCT system)', 'platform_count');
    $summary_query->addExpression('SUM(CASE WHEN last_played_at > 0 THEN 1 ELSE 0 END)', 'recent_count');
    $summary = $summary_query->execute()->fetchAssoc() ?: [];

    $system_query = $this->database->select('nelkano_library_item', 'i')
      ->condition('uid', $uid)
      ->condition('deleted_at', 0)
      ->condition('last_played_at', 0, '>')
      ->groupBy('system')
      ->orderBy('total_play_ms', 'DESC');
    $system_query->addField('i', 'system');
    $system_query->addExpression('COALESCE(SUM(total_play_ms), 0)', 'total_play_ms');
    $system_query->addExpression('COUNT(id)', 'item_count');
    $system_query->addExpression('COALESCE(SUM(play_count), 0)', 'session_count');
    $system_rows = $system_query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $systems = [];
    foreach ($system_rows as $row) {
      $item_count = (int) ($row['item_count'] ?? 0);
      $systems[] = [
        'label' => $this->systemLabel((string) $row['system'], $language),
        'meta' => $this->systemMeta($item_count, (int) ($row['session_count'] ?? 0), $language),
        'play_time' => $this->formatDuration((int) $row['total_play_ms']),
      ];
    }

    return [
      'session_count' => (int) ($summary['session_count'] ?? 0),
      'recent_count' => (int) ($summary['recent_count'] ?? 0),
      'total_play' => $this->formatDuration((int) ($summary['total_play_ms'] ?? 0)),
      'platform_count' => (int) ($summary['platform_count'] ?? 0),
      'systems' => $systems,
    ];
  }

  private function emptyActivitySummary(): array {
    return [
      'session_count' => 0,
      'recent_count' => 0,
      'total_play' => '0 h',
      'platform_count' => 0,
      'systems' => [],
    ];
  }

  private function systemLabel(string $system, string $language): string {
    $labels = [
      'CHIP8' => 'CHIP-8',
      'GAME_BOY' => 'Game Boy',
      'GBC' => 'Game Boy Color',
      'GBA' => 'Game Boy Advance',
      'NES' => 'NES',
      'NDS' => 'Nintendo DS',
    ];
    return $labels[strtoupper($system)] ?? ($language === 'en' ? 'Compatible system' : 'Sistema compatible');
  }

  private function systemMeta(int $item_count, int $session_count, string $language): string {
    if ($language === 'en') {
      $files = $item_count === 1 ? '1 compatible file' : $item_count . ' compatible files';
      $sessions = $session_count === 1 ? '1 session' : $session_count . ' sessions';
      return $files . ' · ' . $sessions;
    }
    $files = $item_count === 1 ? '1 archivo compatible' : $item_count . ' archivos compatibles';
    $sessions = $session_count === 1 ? '1 sesion' : $session_count . ' sesiones';
    return $files . ' · ' . $sessions;
  }

  private function formatDuration(int $millis): string {
    $minutes = (int) floor(max(0, $millis) / 60000);
    if ($minutes < 60) {
      return $minutes . ' min';
    }
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    return $remaining > 0 ? $hours . ' h ' . $remaining . ' min' : $hours . ' h';
  }

  private function touchOnline(int $uid): void {
    \Drupal::service('user.data')->set('nelkano_home', $uid, 'last_seen', \Drupal::time()->getRequestTime());
  }

  private function isOnline(int $uid): bool {
    $last_seen = (int) (\Drupal::service('user.data')->get('nelkano_home', $uid, 'last_seen') ?? 0);
    return $last_seen >= \Drupal::time()->getRequestTime() - 300;
  }

  private function profileData(UserInterface $account): array {
    $uid = (int) $account->id();
    $row = $this->database->select('nelkano_profile', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: [];
    $name = trim((string) ($row['display_name'] ?? '')) ?: $account->getDisplayName();
    $status = trim((string) ($row['status_text'] ?? ''));
    $avatar_uri = trim((string) ($row['avatar_file_uri'] ?? ''));

    return [
      'uid' => $uid,
      'name' => $name,
      'email' => $account->getEmail(),
      'status_text' => $status ?: 'Preparando la siguiente partida.',
      'avatar_color' => $row['avatar_color'] ?? '#a414ff',
      'avatar_url' => $avatar_uri !== '' ? \Drupal::service('file_url_generator')->generateString($avatar_uri) : '',
      'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
      'url' => '/user/' . $uid,
    ];
  }

  private function relationship(int $uidA, int $uidB): ?array {
    $row = $this->database->select('nelkano_friendship', 'f')
      ->fields('f')
      ->condition('requester_uid', [$uidA, $uidB], 'IN')
      ->condition('recipient_uid', [$uidA, $uidB], 'IN')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  private function friends(int $uid): array {
    $rows = $this->database->select('nelkano_friendship', 'f')
      ->fields('f')
      ->condition('status', 'accepted')
      ->condition($this->database->condition('OR')
        ->condition('requester_uid', $uid)
        ->condition('recipient_uid', $uid))
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);

    return $this->relationshipsWithProfiles($rows, $uid);
  }

  private function pendingIncoming(int $uid): array {
    $rows = $this->database->select('nelkano_friendship', 'f')
      ->fields('f')
      ->condition('status', 'pending')
      ->condition('recipient_uid', $uid)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    return $this->relationshipsWithProfiles($rows, $uid);
  }

  private function pendingOutgoing(int $uid): array {
    $rows = $this->database->select('nelkano_friendship', 'f')
      ->fields('f')
      ->condition('status', 'pending')
      ->condition('requester_uid', $uid)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    return $this->relationshipsWithProfiles($rows, $uid);
  }

  private function relationshipsWithProfiles(array $rows, int $uid): array {
    $items = [];
    foreach ($rows as $row) {
      $other_uid = (int) $row['requester_uid'] === $uid ? (int) $row['recipient_uid'] : (int) $row['requester_uid'];
      $account = User::load($other_uid);
      if (!$account instanceof UserInterface) {
        continue;
      }
      $items[] = [
        'id' => (int) $row['id'],
        'status' => $row['status'],
        'other' => $this->profileData($account),
        'online' => $this->isOnline($other_uid),
        'accept_form' => $this->renderForm('Drupal\nelkano_home\Form\FriendActionForm', (int) $row['id'], 'accept'),
        'reject_form' => $this->renderForm('Drupal\nelkano_home\Form\FriendActionForm', (int) $row['id'], 'reject'),
        'cancel_form' => $this->renderForm('Drupal\nelkano_home\Form\FriendActionForm', (int) $row['id'], 'cancel'),
      ];
    }
    return $items;
  }

  private function memberFor(int $created, string $language): string {
    $seconds = max(0, \Drupal::time()->getRequestTime() - $created);
    $days = (int) floor($seconds / 86400);
    if ($days <= 0) {
      return $language === 'en' ? 'today' : 'Hoy';
    }
    if ($days === 1) {
      return $language === 'en' ? '1 day' : '1 dia';
    }
    if ($days < 30) {
      return $language === 'en' ? $days . ' days' : $days . ' dias';
    }
    $months = (int) floor($days / 30);
    return $months === 1 ? ($language === 'en' ? '1 month' : '1 mes') : ($language === 'en' ? $months . ' months' : $months . ' meses');
  }

}
