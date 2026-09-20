<?php
/** Local integration coverage for header image derivatives and refreshes. */
use Drupal\nelkano_home\Service\HeaderImages;
use Drupal\image\Entity\ImageStyle;
if (getenv('DRUPAL_DB_HOST') !== 'database') { throw new RuntimeException('Local Docker only.'); }
$checks = 0;
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) { throw new RuntimeException($message); } ++$checks; };
$style = ImageStyle::load('nelkano_header_avatar');
$assert($style !== NULL, 'Image style installed');
$uri = 'public://qa-header-' . bin2hex(random_bytes(8)) . '.png';
$cacheId = 'nelkano_header_avatar:' . hash('sha256', $uri);
$fixture = imagecreatetruecolor(800, 600);
$draw = static function (int $red) use ($fixture, $uri): void {
  imagefilledrectangle($fixture, 0, 0, 799, 599, imagecolorallocate($fixture, $red, 50, 110));
  imagepng($fixture, $uri);
};
try {
  $draw(200);
  $original = hash_file('sha256', $uri);
  $mtime = filemtime($uri);
  $url = HeaderImages::avatarUrl($uri);
  $derivative = $style->buildUri($uri);
  $assert(str_contains($url, '/styles/nelkano_header_avatar/') && str_contains($url, 'v='), 'Derivative URL with fingerprint');
  $size = getimagesize($derivative);
  $assert($size[0] === 128 && $size[1] === 96 && $size['mime'] === 'image/webp', 'Small WebP retains aspect ratio');
  $assert(hash_file('sha256', $uri) === $original, 'Original unmodified');
  $firstDerivative = hash_file('sha256', $derivative);
  $assert(HeaderImages::avatarUrl($uri) === $url, 'Stable URL for unchanged image');
  $assert(hash_file('sha256', $derivative) === $firstDerivative, 'Stable derivative');
  $draw(40);
  touch($uri, $mtime);
  clearstatcache(TRUE, $uri);
  $replacement = hash_file('sha256', $uri);
  $nextUrl = HeaderImages::avatarUrl($uri);
  $assert($nextUrl !== $url, 'Same-path replacement invalidates browser cache even with same timestamp');
  $assert(hash_file('sha256', $derivative) !== $firstDerivative, 'Same-path replacement regenerates thumbnail');
  $assert(hash_file('sha256', $uri) === $replacement, 'Replacement original unmodified');
  $assert(HeaderImages::avatarUrl('public://missing-qa-avatar.png') === \Drupal::service('file_url_generator')->generateString('public://missing-qa-avatar.png'), 'Missing source falls back safely');
  $assert(HeaderImages::avatarUrl('https://example.invalid/avatar.jpg') === 'https://example.invalid/avatar.jpg', 'Remote images not fetched');
  $http = \Drupal::httpClient();
  $response = $http->get($nextUrl, ['timeout' => 15]);
  $assert($response->getStatusCode() === 200 && str_contains($response->getHeaderLine('Content-Type'), 'image/webp'), 'Derivative served as WebP');
  foreach (['/', '/en', '/sistemas', '/guia', '/contacto', '/aviso-legal', '/privacidad-cookies', '/versiones', '/seguridad-privacidad', '/user/login', '/user/register'] as $route) {
    $response = $http->get('http://localhost' . $route, ['http_errors' => FALSE, 'timeout' => 15]);
    $assert($response->getStatusCode() === 200, 'Page responds: ' . $route);
    $assert(str_contains((string) $response->getBody(), '/assets/logo-nav.webp'), 'Small logo used: ' . $route);
  }
  $logo = DRUPAL_ROOT . '/modules/custom/nelkano_home/assets/logo-nav.webp';
  $assert(filesize($logo) < 8000 && getimagesize($logo)['mime'] === 'image/webp', 'Lightweight header logo');
  $row = \Drupal::database()->select('nelkano_profile', 'p')->fields('p', ['uid', 'avatar_file_uri'])->condition('avatar_file_uri', 'public://%', 'LIKE')->range(0, 1)->execute()->fetchAssoc();
  if ($row) {
    $avatar = $row['avatar_file_uri'];
    $before = hash_file('sha256', $avatar);
    $avatarUrl = HeaderImages::avatarUrl($avatar);
    $smallAvatar = $style->buildUri($avatar);
    $assert(is_file($smallAvatar) && filesize($smallAvatar) < 8000, 'Existing avatar optimized');
    $assert(hash_file('sha256', $avatar) === $before, 'Existing avatar original preserved');
    echo 'Existing avatar: ' . filesize($avatar) . ' -> ' . filesize($smallAvatar) . " bytes\n";
    $accountSwitcher = \Drupal::service('account_switcher');
    $accountSwitcher->switchTo(\Drupal\user\Entity\User::load($row['uid']));
    try {
      $probe = new class {
        use \Drupal\nelkano_home\Controller\NelkanoPageContextTrait;
        public function currentUser() { return \Drupal::currentUser(); }
        public function context(): array { return $this->chromeContext('modules/custom/nelkano_home'); }
      };
      $context = $probe->context();
      $assert($context['nav_account_avatar_url'] === $avatarUrl, 'Authenticated header uses derivative');
    }
    finally { $accountSwitcher->switchBack(); }
  }
  echo "$checks checks passed.\n";
}
finally {
  $style->flush($uri);
  if (is_file($uri)) { unlink($uri); }
  \Drupal::cache()->delete($cacheId);
  imagedestroy($fixture);
}
