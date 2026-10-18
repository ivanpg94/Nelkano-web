<?php
/** Read-only navigation contract tests; no Drupal bootstrap or database writes. */
namespace Drupal\nelkano_home\Service {
  final class SeoMetadata {
    public static function forRequest(string $language): array { return []; }
  }
}
namespace Drupal\Core {
  final class Url {
    private function __construct(private string $url) {}
    public static function fromUserInput(string $url, array $options = []): self {
      return new self($url . '?' . http_build_query($options['query'] ?? []));
    }
    public function toString(): string { return $this->url; }
  }
}
namespace {
  $root = dirname(__DIR__);
  $docroot = is_dir($root . '/web/modules/custom/nelkano_home') ? $root . '/web' : $root . '/public_html';
  require $root . '/vendor/autoload.php';
  if (!defined('DRUPAL_ROOT')) { define('DRUPAL_ROOT', $docroot); }
  require $docroot . '/modules/custom/nelkano_home/src/Controller/NelkanoPageContextTrait.php';

  final class HeaderAccount {
    public function __construct(private bool $authenticated, private bool $admin = FALSE) {}
    public function isAuthenticated(): bool { return $this->authenticated; }
    public function getDisplayName(): string { return 'Player <test>'; }
    public function hasPermission(string $permission): bool { return $this->admin; }
  }
  final class HeaderBook {
    public bool $published = TRUE;
    public bool $accessible = TRUE;
    public function hasTranslation(string $language): bool { return FALSE; }
    public function isPublished(): bool { return $this->published; }
    public function access(string $operation, object $account): bool { return $this->accessible; }
    public function toUrl(): object { return new class {
      public function toString(): string { return '/sdk-de-nelkano'; }
    }; }
  }
  final class Drupal {
    public static ?HeaderBook $book;
    public static array $csrfRequests = [];
    public static function entityTypeManager(): object { return new class {
      public function getStorage(string $type): object { return $this; }
      public function loadByProperties(array $properties): array {
        if ($properties !== ['uuid' => 'de520020-2026-4000-8000-000000000001', 'type' => 'sdk_page']) {
          throw new \RuntimeException('Unexpected SDK lookup');
        }
        return Drupal::$book ? [Drupal::$book] : [];
      }
    }; }
    public static function service(string $id): object {
      if ($id === 'entity.repository') { return new class {
        public function getTranslationFromContext(object $book, string $language): object { return $book; }
      }; }
      if ($id === 'csrf_token') { return new class {
        public function get(string $key): string { Drupal::$csrfRequests[] = $key; return 'original-csrf-token'; }
      }; }
      throw new \RuntimeException('Unexpected service ' . $id);
    }
    public static function database(): object { return new class {
      public function schema(): object { return $this; }
      public function tableExists(string $table): bool { return FALSE; }
    }; }
  }
  final class HeaderProbe {
    use \Drupal\nelkano_home\Controller\NelkanoPageContextTrait;
    public function __construct(private HeaderAccount $account) {}
    public function currentUser(): HeaderAccount { return $this->account; }
    public function context(string $language): array { return $this->chromeContext('modules/custom/nelkano_home', $language); }
  }

  $checks = 0;
  $assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new \RuntimeException($message); }
    ++$checks;
  };
  $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($docroot . '/modules/custom/nelkano_home/templates'), ['autoescape' => 'html', 'strict_variables' => TRUE]);
  foreach (['es', 'en'] as $language) {
    foreach (['anonymous', 'player', 'creator', 'admin'] as $role) {
      Drupal::$book = new HeaderBook();
      Drupal::$csrfRequests = [];
      $authenticated = $role !== 'anonymous';
      $context = (new HeaderProbe(new HeaderAccount($authenticated, $role === 'admin')))->context($language);
      $assert(array_column($context['nav_links'], 'label') === ($language === 'es' ? ['Sistemas', 'Módulos', 'Guía de uso', 'SDK'] : ['Systems', 'Modules', 'Guide', 'SDK']), "$language/$role: public links and order");
      $assert($context['nav_modules_url'] === '/modulos' && $context['nav_sdk_url'] === '/sdk-de-nelkano', "$language/$role: reusable destinations");
      $assert(count($context['nav_account_links']) === ($authenticated ? ($role === 'admin' ? 3 : 2) : 0), "$language/$role: permitted account actions");
      $assert(!in_array('/modulos/mis-modulos', array_column($context['nav_account_links'], 'url'), TRUE), "$language/$role: no My modules entry");
      if ($authenticated) {
        $expectedAccountUrls = $language === 'es' ? ['/user', '/user/stream'] : ['/en/user', '/en/user/stream'];
        if ($role === 'admin') { $expectedAccountUrls[] = '/admin/nelkano'; }
        $assert(array_column($context['nav_account_links'], 'url') === $expectedAccountUrls, "$language/$role: profile, stream and permitted administration destinations");
      }
      $assert($context['nav_logout_url'] === ($authenticated ? '/user/logout?token=original-csrf-token' : ''), "$language/$role: original CSRF logout URL");
      $assert(Drupal::$csrfRequests === ($authenticated ? ['user/logout'] : []), "$language/$role: anonymous does not request logout token");
      $html = $twig->render('nelkano-header.html.twig', $context);
      $dom = new \DOMDocument();
      @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
      $xpath = new \DOMXPath($dom);
      $assert($xpath->query('//nav[@id="nk-main-menu"]/a')->length === 4, "$language/$role: four primary anchors");
      $assert($xpath->query('//details[@data-nk-account]')->length === ($authenticated ? 1 : 0), "$language/$role: native account disclosure");
      $assert($xpath->query('//button[@data-nk-navigation-toggle and @aria-controls="nk-main-menu" and @aria-expanded="false"]')->length === 1, "$language/$role: mobile control contract");
      $assert(!str_contains($html, 'role="menu"'), "$language/$role: disclosure uses standard keyboard semantics");
      $assert(!str_contains($html, 'Player <test>') && (!$authenticated || str_contains($html, 'Player &lt;test&gt;')), "$language/$role: user name escaped");
      $assert(str_contains($html, '/js/header.js?v='), "$language/$role: versioned interaction script");
      if (!$authenticated) {
        $assert(str_contains($html, $language === 'es' ? '>Entrar</a>' : '>Sign in</a>'), "$language: clear sign-in action");
      }
    }
  }
  foreach (['missing', 'unpublished', 'denied'] as $state) {
    Drupal::$book = $state === 'missing' ? NULL : new HeaderBook();
    if ($state === 'unpublished') { Drupal::$book->published = FALSE; }
    if ($state === 'denied') { Drupal::$book->accessible = FALSE; }
    $context = (new HeaderProbe(new HeaderAccount(FALSE)))->context('es');
    $assert($context['nav_sdk_url'] === '' && count($context['nav_links']) === 3, "$state SDK is absent from both context and primary menu");
  }
  Drupal::$book = new HeaderBook();
  $context = (new HeaderProbe(new HeaderAccount(TRUE)))->context('es');
  $context['nav_account_avatar_url'] = '/styles/nelkano_header_avatar/avatar.webp?v=original';
  $html = $twig->render('nelkano-header.html.twig', $context);
  $assert(str_contains($html, '/styles/nelkano_header_avatar/avatar.webp?v=original'), 'Existing avatar derivative URL preserved');
  echo "$checks header navigation checks passed.\n";
}
