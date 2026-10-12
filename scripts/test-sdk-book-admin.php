<?php

use Drupal\Core\Form\FormState;
use Drupal\nelkano_home\Form\SdkBookAdminForm;
use Drupal\nelkano_home\Form\SdkBookAdminFormBase;
use Drupal\nelkano_home\Form\SdkBookImportForm;
use Drupal\nelkano_home\Controller\SdkBookExportController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Yaml\Yaml;

$db = \Drupal::database();
if (!str_starts_with($db->getConnectionOptions()['database'], 'nelkano_book_qa_')) {
  throw new RuntimeException('Disposable Book QA database required.');
}
$transaction = $db->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal\user\Entity\User::load(1));
$request = \Drupal::request();
$originalQuery = $request->query->all();
$originalFiles = $request->files->all();
$transfer = \Drupal::service('book_import_export.transfer');
$root = SdkBookAdminFormBase::BOOK_UUID;
$count = 0;
$check = function (bool $ok, string $label) use (&$count): void {
  if (!$ok) { throw new RuntimeException($label); }
  echo 'PASS ' . ++$count . ': ' . $label . PHP_EOL;
};
$file = tempnam(sys_get_temp_dir(), 'sdk-admin-qa-');
try {
  $source = $transfer->read(dirname(DRUPAL_ROOT) . '/content/books/nelkano-sdk.yml');
  $transfer->import($source);
  $data = $transfer->export($root);
  $page = $data['pages'][0];
  $request->query->replace(['admin_lang' => 'es']);
  $editor = new SdkBookAdminForm();
  $state = new FormState();
  $form = $editor->buildForm([], $state);
  $check(count($form['chapters']['list']['#rows']) === count($source['pages']), 'All fixture chapters with hierarchy and edit/add actions');
  $check(isset($form['tools']['export'], $form['tools']['import']), 'Export and import tools at top');
  $values = ['title' => 'QA SDK revised', 'body' => ['value' => '<p>QA revision</p>', 'format' => 'basic_html'],
    'summary' => '', 'alias' => '/sdk-de-nelkano', 'published' => TRUE, 'weight' => 10];
  $state->setValues($values);
  $before = serialize($transfer->export($root));
  $editor->validateForm($form, $state);
  $check(!$state->hasAnyErrors() && serialize($transfer->export($root)) === $before, 'Editor validates without writes');
  $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $root]);
  $oldRevision = reset($nodes)->getRevisionId();
  $editor->submitForm($form, $state);
  $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $root]);
  $saved = reset($nodes);
  $check($saved->label() === 'QA SDK revised' && $saved->getRevisionId() > $oldRevision, 'Editor saves real Book content with revision');
  $conflict = FALSE;
  try {
    $method = new ReflectionMethod($editor, 'editedData');
    $method->invoke($editor, $state);
  }
  catch (InvalidArgumentException $e) { $conflict = TRUE; }
  $check($conflict, 'Stale page edits rejected');
  $transfer->import($source);
  $request->query->replace(['new' => '1', 'parent' => $root, 'admin_lang' => 'es']);
  $state = new FormState();
  $form = $editor->buildForm([], $state);
  $state->setValues(['title' => 'QA new chapter', 'body' => ['value' => '<p>New content</p>', 'format' => 'basic_html'],
    'summary' => '', 'alias' => '/sdk-de-nelkano/qa-new', 'published' => TRUE, 'parent' => $root, 'weight' => 200]);
  $editor->validateForm($form, $state);
  $check(!$state->hasAnyErrors(), 'New chapter validated');
  $editor->submitForm($form, $state);
  $new = array_column($transfer->export($root)['pages'], NULL, 'uuid')[$state->get('sdk_page_uuid')];
  $check($new['parent'] === $root && $new['translations']['es']['alias'] === '/sdk-de-nelkano/qa-new', 'New chapter gets stable UUID, alias and parent');
  $request->query->replace(['page' => $new['uuid'], 'admin_lang' => 'en']);
  $state = new FormState();
  $form = $editor->buildForm([], $state);
  $state->setValues(['title' => 'QA English chapter', 'body' => ['value' => '<p>English content</p>', 'format' => 'basic_html'],
    'summary' => '', 'alias' => '/sdk-de-nelkano/qa-new-en', 'published' => TRUE, 'parent' => $root, 'weight' => 200]);
  $editor->validateForm($form, $state);
  $check(!$state->hasAnyErrors(), 'New translation validated');
  $editor->submitForm($form, $state);
  $translated = array_column($transfer->export($root)['pages'], NULL, 'uuid')[$new['uuid']];
  $check(isset($translated['translations']['es'], $translated['translations']['en']), 'English editing retains original Spanish translation');
  $request->query->replace([]);
  $response = (new SdkBookExportController())->download();
  $exported = Yaml::parse($response->getContent());
  $check($exported['book'] === $root && str_contains($response->headers->get('Content-Disposition'), 'attachment'), 'Export downloads explicit book YAML');
  $check(!isset($exported['users'], $exported['config']) && str_contains($response->headers->get('Cache-Control'), 'no-store'), 'Download excludes unrelated entities and is not cached');
  $rootIndex = array_search($root, array_column($exported['pages'], 'uuid'), TRUE);
  $exported['pages'][$rootIndex]['translations']['es']['title'] = 'QA imported title';
  file_put_contents($file, Yaml::dump($exported, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
  $request->files->set('files', ['yaml' => new UploadedFile($file, 'sdk.yml', 'application/yaml', NULL, TRUE)]);
  $import = new SdkBookImportForm();
  $state = new FormState();
  $form = $import->buildForm([], $state);
  $before = serialize($transfer->export($root));
  $import->validateForm($form, $state);
  $check(!$state->hasAnyErrors() && $state->get('sdk_plan')[$root] === 'update' && serialize($transfer->export($root)) === $before, 'Uploaded YAML simulation produces plan without writes');
  $preview = ['#name' => 'preview']; $state->setTriggeringElement($preview);
  $import->submitForm($form, $state);
  $form = $import->buildForm([], $state);
  $check(isset($form['actions']['apply']) && !isset($form['yaml']), 'Apply button appears only after simulation');
  $import->validateForm($form, $state);
  $apply = ['#name' => 'apply']; $state->setTriggeringElement($apply);
  $import->submitForm($form, $state);
  $check(array_column($transfer->export($root)['pages'], NULL, 'uuid')[$root]['translations']['es']['title'] === 'QA imported title', 'Explicit apply imports simulated content');
  $denied = FALSE;
  try { SdkBookAdminFormBase::assertScope(['book' => 'another-book', 'pages' => []]); }
  catch (InvalidArgumentException $e) { $denied = TRUE; }
  $check($denied, 'Other books rejected');
  $denied = FALSE;
  try { SdkBookAdminFormBase::assertScope(['book' => $root, 'pages' => [['type' => 'article']]]); }
  catch (InvalidArgumentException $e) { $denied = TRUE; }
  $check($denied, 'Foreign content types rejected');
  $access = \Drupal::service('access_manager');
  $anonymous = new \Drupal\Core\Session\AnonymousUserSession();
  foreach (['nelkano_home.admin_sdk', 'nelkano_home.admin_sdk_import', 'nelkano_home.admin_sdk_export'] as $route) {
    $check(!$access->checkNamedRoute($route, [], $anonymous), 'Anonymous access denied: ' . $route);
  }
  $state = new FormState();
  $form = $import->buildForm([], $state);
  $import->validateForm($form, $state);
  $newer = $transfer->export($root);
  $newerRoot = array_search($root, array_column($newer['pages'], 'uuid'), TRUE);
  $newer['pages'][$newerRoot]['translations']['es']['title'] = 'QA concurrent update';
  $transfer->import($newer);
  $import->validateForm($form, $state);
  $check($state->hasAnyErrors(), 'Book changes after preview require a new simulation');
  $state->clearErrors();
}
finally {
  $transaction->rollBack();
  $switcher->switchBack();
  $request->query->replace($originalQuery);
  $request->files->replace($originalFiles);
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  if (is_file($file)) { unlink($file); }
}
echo "SDK admin checks complete: $count; fixtures rolled back." . PHP_EOL;
