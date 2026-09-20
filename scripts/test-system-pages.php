<?php

/** Run only against local Docker: drush php:script scripts/test-system-pages.php. */
use Drupal\nelkano_home\Service\SystemPages;
use Drupal\Core\Form\FormState;

if (getenv('DRUPAL_DB_HOST') !== 'database') {
  throw new RuntimeException('This test is restricted to the local Docker environment.');
}
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
};
$get = static fn(string $path) => \Drupal::httpClient()->get('http://localhost' . $path, ['http_errors' => FALSE, 'timeout' => 15]);
$config = \Drupal::configFactory()->getEditable(SystemPages::CONFIG);
$original = $config->getRawData();
try {
  $schema = \Drupal::service('config.typed')->createFromNameAndData(SystemPages::CONFIG, $original);
  $expect(count($schema->validate()) === 0, 'Invalid configuration schema');
  $home = (string) $get('/')->getBody();
  foreach (['es', 'en'] as $language) {
    $data = SystemPages::content($language);
    foreach ($data['pages'] as $id => $page) {
      $response = $get(SystemPages::path($id, $language));
      $expect($response->getStatusCode() === ($page['enabled'] ? 200 : 404), 'Wrong HTTP status: ' . $language . '/' . $id);
      if ($page['enabled']) {
        $html = (string) $response->getBody();
        $expect(str_contains($html, '<h1>' . htmlspecialchars($page['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'), 'Missing title: ' . $id);
        $expect(str_contains($html, 'hreflang="' . $language . '"'), 'Missing language metadata');
        $expect(str_contains($response->getHeaderLine('Cache-Control'), 'no-store'), 'Page could retain stale editorial copy');
        if ($language === 'es' && $page['show_on_home']) {
          $expect(str_contains($home, 'href="' . SystemPages::path($id, $language) . '"'), 'Missing home link: ' . $id);
        }
      }
    }
  }
  $expect($get('/sistemas/not-a-core')->getStatusCode() === 404, 'Unknown core must return 404');
  // Exercise the same saver used by the admin, including all shared labels and
  // every per-system field. The real browser also verifies a full form POST.
  $data = $original['es'];
  $data['pages']['game-boy']['title'] = 'QA <script>alert(1)</script>';
  $data['pages']['game-boy']['summary'] = 'QA-card-live';
  $data['pages']['game-boy']['features'] = "QA-line-one\nQA-line-two";
  $data['pages']['game-boy']['faq'][0]['answer'] = 'QA-answer-live';
  $data['pages']['game-boy']['tagline'] = 'QA-home-live';
  foreach ($data['pages'] as &$candidate) { $candidate['related_ids'] = SystemPages::lines($candidate['related_ids'] ?? ''); } unset($candidate);
  $data['labels']['about'] = 'QA-heading-live';
  $home_before = \Drupal::config('nelkano_home.settings')->getRawData();
  $form = \Drupal\nelkano_home\Form\SystemSettingsForm::create(\Drupal::getContainer());
  $state = new FormState();
  $state->setValue(['es', 'systems'], $data);
  $state->setValue('active_language', 'es');
  $form_array = [];
  $form->submitForm($form_array, $state);
  $expect(\Drupal::config('nelkano_home.settings')->getRawData() === $home_before, 'System editor modified home settings');
  $route = \Drupal::service('router.route_provider')->getRouteByName('nelkano_home.admin_systems');
  $expect($route->getPath() === '/admin/nelkano/sistemas', 'Missing independent editor route');
  $expect($route->getRequirement('_permission') === 'administer nelkano editable pages', 'Missing admin permission');
  $expect(!method_exists(\Drupal\nelkano_home\Form\HomeSettingsForm::class, 'saveSystemPages'), 'System editor remains in Home');
  $html = (string) $get('/sistemas/game-boy')->getBody();
  $expect(!str_contains($html, '<script>alert(1)</script>'), 'Editorial HTML must be escaped');
  $expect(str_contains($html, 'QA &lt;script&gt;alert(1)&lt;/script&gt;'), 'Edited title not rendered');
  foreach (['QA-heading-live', 'QA-line-one', 'QA-line-two', 'QA-answer-live'] as $marker) {
    $expect(str_contains($html, $marker), 'Saved field not rendered: ' . $marker);
  }
  $expect(str_contains((string) $get('/')->getBody(), 'QA-home-live'), 'Card edit not visible anonymously');
  $expect(str_contains((string) $get('/sistemas')->getBody(), 'QA-card-live'), 'Index card edit not visible');
  $expect(\Drupal::config(SystemPages::CONFIG)->get('en') === $original['en'], 'Spanish save modified English');
  SystemPages::install();
  $expect(\Drupal::config(SystemPages::CONFIG)->get('es.pages.game-boy.summary') === 'QA-card-live', 'Migration overwrote existing edits');
  $config->set('es.pages.game-boy.enabled', FALSE)->save();
  $expect($get('/sistemas/game-boy')->getStatusCode() === 404, 'Unpublished page still accessible');
  $expect(!str_contains((string) $get('/')->getBody(), 'href="/sistemas/game-boy"'), 'Unpublished page still linked');
  $expect(!str_contains((string) $get('/sitemap.xml')->getBody(), '/sistemas/game-boy</loc>'), 'Unpublished page remains in sitemap');
}
finally {
  $config->setData($original)->save();
}
$expect(!str_contains((string) $get('/sistemas/game-boy')->getBody(), 'QA-heading-live'), 'Original data was not restored');
echo "PASS: $checks checks; pages, home links, bilingual editing, escaping, live updates, migration and unpublishing. Original configuration restored.\n";
