<?php
/** Persistent harmless demo packages. Docker CLI opt-in only; never a deployment update. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
$db = \Drupal::database()->getConnectionOptions();
if (getenv('NELKANO_LOCAL_MODULE_DEMO') !== '1' || ($db['database'] ?? '') !== 'nelkano_drupal'
  || ($db['host'] ?? '') !== 'database') {
  throw new RuntimeException('This seed runs only against the local Docker database with explicit opt-in.');
}
require_once DRUPAL_ROOT . '/modules/custom/nelkano_modules/tests/ModuleFixtures.php';

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\UserSession;
use Drupal\file\Entity\File;
use Drupal\nelkano_modules\Service\ModuleAudience;
use Drupal\nelkano_modules\Service\ModuleHostedPackages;
use Drupal\nelkano_modules\Service\PackageVerifier;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Drupal\user\Entity\User;
use NelkanoModuleTests\Packages;

$registry = \Drupal::service('nelkano_modules.registry');
if (!method_exists($registry, 'setContentLanguage')) { throw new RuntimeException('Install the content-language registry change before running this seed.'); }
$definitions = json_decode(file_get_contents(__DIR__ . '/module-demo.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$state = \Drupal::state();
$stateKey = 'nelkano_modules_demo_content';
$fixture = $state->get($stateKey) ?: ['users' => [], 'modules' => [], 'files' => [],
  'keypair' => base64_encode(sodium_crypto_sign_keypair())];
$keypair = base64_decode($fixture['keypair'], TRUE);
if (!is_string($keypair) || strlen($keypair) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) { throw new RuntimeException('Invalid stored demo signing identity.'); }
$verifier = new PackageVerifier();
$prepared = [];

// Preflight ALL existing identities/hashes before writing any fixture data.
foreach ($definitions as $demo) {
  $slug = $demo['slug']; $id = 'example.demo.' . $slug;
  $module = isset($fixture['modules'][$slug]) ? Node::load($fixture['modules'][$slug]) : NULL;
  if ($module && ($module->bundle() !== 'nelkano_module' || $module->get('field_module_id')->value !== $id
    || ModuleAudience::access($module) !== 'public'
    || !in_array((int) $module->getOwnerId(), array_map('intval', array_values($fixture['users'])), TRUE))) {
    throw new RuntimeException('Demo state points to unrelated or changed content: ' . $slug);
  }
  if (!$module) {
    $ids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'nelkano_module')->condition('field_module_id', $id)->execute();
    if ($ids) { throw new RuntimeException('An unrelated module already uses ' . $id); }
  }
  $existing = [];
  if ($module) { foreach ($registry->releases($module) as $release) { $existing[$release->get('field_release_version')->value] = $release; } }
  foreach ($demo['versions'] as $j => $version) {
    $bytes = Packages::make($id, $version, $keypair, $demo['platforms'], $j === 0 ? '0.1.0' : '0.2.0');
    $metadata = $verifier->verify($bytes);
    $release = $existing[$version] ?? NULL;
    if (($module && $module->get('field_module_author')->value !== $metadata['author'])
      || ($release && ($release->get('field_release_hash')->value !== $metadata['sha256']
        || (int) $release->get('field_release_size')->value !== $metadata['size']))) {
      throw new RuntimeException('Stored immutable package differs; no repair attempted: ' . $id . ' ' . $version);
    }
    if (($demo['retired'] ?? NULL) === $version && $release
      && ($release->get('field_release_state')->value !== 'unavailable' || ModuleHostedPackages::fid($release))) {
      throw new RuntimeException('The intentionally retired version changed; refusing to reactivate it.');
    }
    $prepared[$slug][$version] = ['bytes' => $bytes, 'metadata' => $metadata];
  }
}
foreach ($fixture['users'] as $name => $uid) {
  $user = User::load($uid);
  if (!$user || $user->getAccountName() !== 'Nelkano Demo ' . $name || !$user->isActive()) {
    throw new RuntimeException('A stored demo author changed; refusing to impersonate another account.');
  }
}

$lock = \Drupal::lock();
if (!$lock->acquire('nelkano_modules_demo_seed', 300)) { throw new RuntimeException('Another demo seed is running.'); }
$saveState = static function () use (&$fixture, $state, $stateKey): void { $state->set($stateKey, $fixture); };
$switcher = \Drupal::service('account_switcher');
$switched = FALSE; $hostedCount = 0; $retiredCount = 0;
try {
  $saveState();
  foreach (['Ana', 'Bruno', 'Clara'] as $name) {
    if (isset($fixture['users'][$name])) { continue; }
    $username = 'Nelkano Demo ' . $name;
    if (user_load_by_name($username)) { throw new RuntimeException('An unrelated account already uses ' . $username); }
    $user = User::create(['name' => $username, 'mail' => strtolower($name) . '@nelkano-demo.example.invalid',
      'status' => 1, 'pass' => bin2hex(random_bytes(32)), 'langcode' => 'es',
      'preferred_langcode' => 'es', 'preferred_admin_langcode' => 'es']);
    $user->save(); $fixture['users'][$name] = (int) $user->id(); $saveState();
  }
  $authors = array_values($fixture['users']);
  $directory = 'private://nelkano-modules/packages';
  if (!\Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
    throw new RuntimeException('Private package storage is unavailable.');
  }

  foreach ($definitions as $i => $demo) {
    $slug = $demo['slug']; $id = 'example.demo.' . $slug;
    $module = isset($fixture['modules'][$slug]) ? Node::load($fixture['modules'][$slug]) : NULL;
    $owner = User::load($module ? $module->getOwnerId() : $authors[$i % count($authors)]);
    // CLI fixture scope only: keep the same UID and persisted roles unchanged.
    // Administrative permission is scoped to this guarded CLI recipe and is never saved.
    $scope = new UserSession(['uid' => (int) $owner->id(), 'name' => $owner->getAccountName(),
      'roles' => array_values(array_unique([...$owner->getRoles(), 'administrator']))]);
    $switcher->switchTo($scope); $switched = TRUE;
    $existing = [];
    if ($module) { foreach ($registry->releases($module) as $r) { $existing[$r->get('field_release_version')->value] = $r; } }

    foreach ($demo['versions'] as $version) {
      $release = $existing[$version] ?? NULL;
      if (($demo['retired'] ?? NULL) === $version) {
        // Preserve the original withdrawn record, including checked/hash/url. On a
        // fresh installation it is omitted rather than inventing an available URL.
        if ($release) { $retiredCount++; }
        continue;
      }
      $item = $prepared[$slug][$version];
      $fid = $release ? ModuleHostedPackages::fid($release) : 0;
      if (!$fid) { $fid = (int) ($fixture['packages'][$slug][$version] ?? 0); }
      $file = $fid ? File::load($fid) : NULL;
      if ($file) {
        if ((int) $file->getOwnerId() !== (int) $owner->id()
          || !str_starts_with($file->getFileUri(), ModuleHostedPackages::PREFIX)) {
          throw new RuntimeException('An unrelated file appears in the demo state.');
        }
      }
      else {
        $file = \Drupal::service('file.repository')->writeData($item['bytes'],
          $directory . '/' . bin2hex(random_bytes(16)) . '.nelkmod', FileExists::Error);
        $file->setOwnerId((int) $owner->id()); $file->setTemporary(); $file->save();
        $fixture['packages'][$slug][$version] = (int) $file->id(); $saveState();
      }
      $verified = $registry->inspectUpload((int) $file->id(), $module, $release);
      if ($verified['sha256'] !== $item['metadata']['sha256'] || $verified['author'] !== $item['metadata']['author']) {
        throw new RuntimeException('Uploaded bytes differ from the immutable demo package.');
      }
      $notes = 'Versión ' . $version . ' de demostración local. Descarga firmada real; el JavaScript de ejemplo es inocuo y no implementa la función anunciada por la ficha.';
      if (!$module) {
        $imageId = 0;
        if ($demo['image'] ?? TRUE) {
          $image = isset($fixture['files'][$slug]) ? File::load($fixture['files'][$slug]) : NULL;
          if (!$image) {
            $source = '/tmp/nelkano-module-demo/' . $slug . '.png';
            if (!is_readable($source)) { throw new RuntimeException('Supply the existing demo illustration before creating ' . $slug); }
            $imageDir = 'public://nelkano-modules/images';
            \Drupal::service('file_system')->prepareDirectory($imageDir, FileSystemInterface::CREATE_DIRECTORY);
            $image = \Drupal::service('file.repository')->writeData(file_get_contents($source),
              $imageDir . '/demo-' . $slug . '.png', FileExists::Rename);
            $fixture['files'][$slug] = (int) $image->id(); $saveState();
          }
          $imageId = (int) $image->id();
        }
        $module = $registry->saveModule(NULL, NULL, ['title' => $demo['name'] . ' (prueba)', 'langcode' => 'es',
          'description' => $demo['description'] . "\n\nContenido de demostración local. Descarga firmada real; el ejemplo no implementa la función descrita.",
          'source' => '', 'published' => TRUE, 'access' => 'public', 'systems' => $demo['systems'],
          'image' => $imageId, 'notes' => $notes], $verified);
        $fixture['modules'][$slug] = (int) $module->id(); $saveState();
        foreach ($registry->releases($module) as $r) { $existing[$r->get('field_release_version')->value] = $r; }
        $release = $existing[$version];
      }
      $alreadyHosted = ModuleHostedPackages::fid($release ?? $module) === (int) $file->id()
        && $release && $release->get('field_release_state')->value === 'available'
        && (string) $release->get('field_release_notes')->value === $notes;
      if (!$alreadyHosted) {
        $saved = $registry->saveVersion($module, $release, $release ? (int) $release->getRevisionId() : NULL, $verified, $notes);
        $existing[$version] = $saved;
      }
      $hostedCount++;
    }

    $description = str_replace('Contenido de demostración local. Descargas simuladas; no es un módulo funcional.',
      'Contenido de demostración local. Descarga firmada real; el ejemplo no implementa la función descrita.',
      (string) $module->get('field_module_description')->value);
    if ($description !== (string) $module->get('field_module_description')->value || $module->get('field_module_systems')->isEmpty()) {
      $module = $registry->saveModule($module, (int) $module->getRevisionId(), [
        'title' => $module->label(), 'description' => $description,
        'source' => $module->get('field_module_source')->value ?? '', 'published' => $module->isPublished(),
        'systems' => $module->get('field_module_systems')->isEmpty() ? $demo['systems']
          : array_column($module->get('field_module_systems')->getValue(), 'value')]);
    }
    $module = $registry->setContentLanguage($module, (int) $module->getRevisionId(), 'es');
    $switcher->switchBack(); $switched = FALSE;
    echo $module->label() . ' -> /modulos/' . $module->id() . PHP_EOL;
  }

  $index = Index::load('nelkano_modules');
  $index->reindex();
  while ($index->getTrackerInstance()->getRemainingItemsCount() > 0) {
    if (!$index->indexItems(50)) { throw new RuntimeException('The catalogue index did not finish.'); }
  }
  echo 'READY: ' . count($definitions) . ' persistent local demos; ' . $hostedCount
    . ' real hosted packages; ' . $retiredCount . ' existing withdrawn versions preserved.' . PHP_EOL;
  echo 'Catalog: http://localhost:8088/modulos' . PHP_EOL;
  echo 'No account roles were changed. Existing names, owners, images, signing identities and archive hashes are preserved.' . PHP_EOL;
}
finally {
  if ($switched) { $switcher->switchBack(); }
  $lock->release('nelkano_modules_demo_seed');
}
