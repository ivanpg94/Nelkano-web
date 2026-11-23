<?php

namespace Drupal\nelkano_usage\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\nelkano_home\Form\AdminFormUiTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Receives opt-in gameplay samples and reports them to site administrators. */
final class UsageController extends ControllerBase {

  use AdminFormUiTrait;

  private const PRODUCTION_HOST = 'nelkano.com';
  private const FLOOD_EVENT = 'nelkano_usage.receive';
  private const SYSTEMS = [
    'chip8' => 'CHIP-8', 'gb' => 'Game Boy', 'gbc' => 'Game Boy Color',
    'gba' => 'Game Boy Advance', 'nes' => 'NES / Famicom', 'fds' => 'Famicom Disk System',
    'sms' => 'Master System', 'gg' => 'Game Gear', 'sg1000' => 'SG-1000',
    'ngp' => 'Neo Geo Pocket', 'ngpc' => 'Neo Geo Pocket Color',
    'md' => 'Mega Drive', 'nds' => 'Nintendo DS', '3ds' => 'Nintendo 3DS',
    'snes' => 'Super Nintendo', 'ps1' => 'PlayStation', 'psp' => 'PSP',
    'gamecube' => 'GameCube', 'rpgmaker' => 'RPG Maker', 'steam' => 'Steam', 'switch' => 'Nintendo Switch', 'other' => 'Otros sistemas',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly FloodInterface $flood,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'), $container->get('state'),
      $container->get('datetime.time'), $container->get('flood'),
    );
  }

  public function receive(Request $request, bool $production = FALSE): JsonResponse {
    if ($production) {
      if ($request->getHost() !== self::PRODUCTION_HOST || !$request->isSecure() || !$this->state->get('nelkano_usage.production_enabled', TRUE)) {
        throw new NotFoundHttpException();
      }
      // Rate-limit a network without putting its address in the usage dataset.
      $network = hash_hmac('sha256', (string) $request->getClientIp(), Settings::getHashSalt());
      if (!$this->flood->isAllowed(self::FLOOD_EVENT, 180, 60, $network)) {
        return new JsonResponse(['ok' => FALSE], 429, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
      }
      $this->flood->register(self::FLOOD_EVENT, 60, $network);
    }
    elseif (!in_array($request->getHost(), ['localhost', '127.0.0.1'], TRUE) || !$this->state->get('nelkano_usage.dev_enabled', FALSE)) {
      throw new NotFoundHttpException();
    }
    $body = $request->getContent();
    if ($request->getContentTypeFormat() !== 'json' || strlen($body) > 32768) {
      return new JsonResponse(['ok' => FALSE], 400);
    }
    try {
      $payload = json_decode($body, TRUE, 10, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return new JsonResponse(['ok' => FALSE], 400);
    }

    $samples = $payload['samples'] ?? NULL;
    if (($payload['schema'] ?? NULL) !== 1 || !is_array($samples) || !array_is_list($samples) || count($samples) < 1 || count($samples) > 60) {
      return new JsonResponse(['ok' => FALSE], 400);
    }

    $now = $this->time->getRequestTime();
    $oldest = intdiv($now - 86400 * 89, 3600) * 3600;
    $newest = intdiv($now, 3600) * 3600 + 3600;
    $rows = [];
    foreach ($samples as $sample) {
      if (!is_array($sample) ||
          count($sample) !== (array_key_exists('returning', $sample) ? 7 : 6) ||
          (($production || array_key_exists('returning', $sample)) && !is_bool($sample['returning'] ?? NULL)) ||
          !self::token($sample['id'] ?? NULL) || !self::token($sample['installation_day'] ?? NULL) ||
          !is_string($sample['system'] ?? NULL) || !isset(self::SYSTEMS[$sample['system']]) ||
          !is_int($sample['hour'] ?? NULL) || $sample['hour'] % 3600 !== 0 ||
          $sample['hour'] < $oldest || $sample['hour'] > $newest ||
          !is_int($sample['sessions'] ?? NULL) || $sample['sessions'] < 0 || $sample['sessions'] > 1 ||
          !is_int($sample['active_ms'] ?? NULL) || $sample['active_ms'] < 0 || $sample['active_ms'] > 3600000 ||
          ($sample['sessions'] === 0 && $sample['active_ms'] === 0)) {
        return new JsonResponse(['ok' => FALSE], 400);
      }
      $rows[] = [
        'event_id' => $sample['id'],
        'day_token' => $sample['installation_day'],
        'play_day' => $this->localHour($sample['hour'])->format('Y-m-d'),
        'play_hour' => $sample['hour'],
        'system_id' => $sample['system'],
        'sessions' => $sample['sessions'],
        'active_ms' => $sample['active_ms'],
        'is_returning' => isset($sample['returning']) ? (int) $sample['returning'] : NULL,
        'received_at' => $now,
      ];
    }
    foreach ($rows as $row) {
      // Insert-only merge makes retries idempotent, including concurrent requests.
      $this->database->merge('nelkano_usage_sample')
        ->key('event_id', $row['event_id'])
        ->insertFields($row)
        ->execute();
    }
    return new JsonResponse(['ok' => TRUE], 200, ['Cache-Control' => 'no-store']);
  }

  public function dashboard(Request $request): array {
    $days = $request->query->get('days') === '30' ? 30 : 7;
    $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
    $first = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $last = $today->format('Y-m-d');
    $range = fn () => $this->database->select('nelkano_usage_sample', 'usage')
      ->condition('play_day', [$first, $last], 'BETWEEN');

    $by_day = $range()->fields('usage', ['play_day']);
    $by_day->addExpression('COUNT(DISTINCT day_token)', 'installations');
    $by_day->addExpression('COUNT(DISTINCT CASE WHEN is_returning = 1 THEN day_token END)', 'returning');
    $by_day->addExpression('COUNT(DISTINCT CASE WHEN is_returning IS NOT NULL THEN day_token END)', 'classified');
    $by_day->addExpression('SUM(active_ms)', 'active_ms');
    $by_day->groupBy('play_day')->orderBy('play_day', 'DESC');
    $daily_rows = [];
    $today_installations = 0;
    $returning_today = NULL;
    $total_millis = 0;
    foreach ($by_day->execute() as $day) {
      $returning = (int) $day->classified > 0 ? (int) $day->returning : NULL;
      $daily_rows[] = [$day->play_day, $day->installations, $returning ?? '—', self::duration((int) $day->active_ms)];
      $total_millis += (int) $day->active_ms;
      if ($day->play_day === $last) {
        $today_installations = (int) $day->installations;
        $returning_today = $returning;
      }
    }

    $by_system = $range()->fields('usage', ['system_id']);
    $by_system->addExpression('SUM(sessions)', 'openings');
    $by_system->addExpression('SUM(active_ms)', 'active_ms');
    $by_system->groupBy('system_id')->orderBy('active_ms', 'DESC')->orderBy('system_id');
    $system_rows = [];
    foreach ($by_system->execute() as $system) {
      $system_rows[] = [
        self::SYSTEMS[$system->system_id] ?? $system->system_id,
        (int) $system->active_ms > 0 ? self::duration((int) $system->active_ms) : 'Sin tiempo recibido',
        $system->openings,
      ];
    }

    $hours = array_fill(0, 24, 0);
    $by_hour = $range()->fields('usage', ['play_hour']);
    $by_hour->addExpression('SUM(active_ms)', 'active_ms');
    $by_hour->groupBy('play_hour');
    foreach ($by_hour->execute() as $sample) {
      $hour = (int) $this->localHour((int) $sample->play_hour)->format('G');
      $hours[$hour] += (int) $sample->active_ms;
    }
    $hour_rows = [];
    foreach ($hours as $hour => $millis) {
      if ($millis > 0) {
        $hour_rows[] = [sprintf('%02d:00–%02d:00', $hour, ($hour + 1) % 24), self::duration($millis)];
      }
    }

    $latest = $range();
    $latest->addExpression('MAX(received_at)', 'received');
    $received = (int) $latest->execute()->fetchField();
    $module_path = \Drupal::service('extension.list.module')->getPath('nelkano_home');
    return [
      '#theme' => 'nelkano_usage_dashboard',
      '#attached' => ['library' => ['nelkano_usage/admin']],
      '#cache' => ['max-age' => 0],
      '#prefix' => $this->nelkanoAdminPrefix($module_path, 'usage', 'nk-redesign-admin nk-admin-data'),
      '#suffix' => '</section></div></div></div>',
      '#development' => $request->getHost() !== self::PRODUCTION_HOST,
      '#installations' => $today_installations,
      '#returning_today' => $returning_today,
      '#total_time' => self::duration($total_millis),
      '#days' => $days,
      '#periods' => array_map(fn (int $number) => [
        'days' => $number,
        'url' => Url::fromRoute('nelkano_usage.dashboard', [], ['query' => ['days' => $number]])->toString(),
      ], [7, 30]),
      '#systems' => self::table('Actividad por sistema', ['Sistema', 'Tiempo de juego', 'Aperturas de juego'], $system_rows),
      '#daily' => self::table('Actividad diaria', ['Día', 'Instalaciones activas', 'Volvieron a jugar', 'Tiempo de juego'], $daily_rows),
      '#hourly' => self::table('Actividad por hora del día (Madrid)', ['Franja horaria', 'Tiempo de juego'], $hour_rows),
      '#last_received' => $received > 0 ? $this->localHour($received)->format('d/m/Y H:i:s') : NULL,
    ];
  }

  private function localHour(int $unix_time): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $unix_time))->setTimezone(new \DateTimeZone('Europe/Madrid'));
  }

  private static function token(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1;
  }

  private static function duration(int $millis): string {
    $seconds = intdiv($millis, 1000);
    if ($seconds === 0 && $millis > 0) {
      return '< 1 s';
    }
    return $seconds < 60 ? "$seconds s" : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
  }

  private static function table(string $caption, array $header, array $rows): array {
    return [
      '#type' => 'table', '#caption' => $caption, '#header' => $header,
      '#rows' => $rows, '#empty' => 'Sin actividad en el periodo seleccionado.',
    ];
  }

}
