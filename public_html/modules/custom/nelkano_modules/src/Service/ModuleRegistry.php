<?php

declare(strict_types=1);

namespace Drupal\nelkano_modules\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** All module/version writes share a lock; only verified packages become available. */
final class ModuleRegistry {
  private bool $writing = FALSE;

  public function __construct(private readonly DownloadClientInterface $downloader, private readonly PackageVerifier $packages) {}

  public function isWriting(): bool { return $this->writing; }

  public static function canEdit(NodeInterface $module, AccountInterface $account): bool {
    return $module->bundle() === 'nelkano_module' && $account->isAuthenticated()
      && ($account->hasPermission('administer nelkano modules')
        || ($account->hasPermission('create nelkano modules') && (int) $module->getOwnerId() === (int) $account->id()));
  }

  private function authorize(?NodeInterface $module = NULL): void {
    $account = \Drupal::currentUser();
    if (!$account->isAuthenticated() || ($module ? !self::canEdit($module, $account)
      : !$account->hasPermission('create nelkano modules') && !$account->hasPermission('administer nelkano modules'))) {
      throw new AccessDeniedHttpException();
    }
  }

  public function inspect(string $url): array {
    $response = $this->downloader->fetch($url);
    if ($response['status'] !== 200) {
      throw new \InvalidArgumentException('No se pudo descargar el .nelkmod. Comprueba que el enlace sea público y directo.');
    }
    return $this->packages->verify($response['body']) + [
      'url' => $url, 'etag' => $response['etag'] ?? '', 'modified' => $response['modified'] ?? '',
    ];
  }

  public function releases(NodeInterface $module): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'nelkano_module_release')
      ->condition('field_release_module', $module->id())->execute();
    $releases = array_values($storage->loadMultiple($ids));
    usort($releases, static fn($a, $b) => version_compare($b->get('field_release_version')->value, $a->get('field_release_version')->value));
    return $releases;
  }

  private function write(callable $callback) {
    $lock = \Drupal::lock();
    if (!$lock->acquire('nelkano_modules.write', 60)) { throw new ConflictHttpException('Otro cambio está en curso. Vuelve a intentarlo.'); }
    $transaction = \Drupal::database()->startTransaction();
    $this->writing = TRUE;
    try {
      $result = $callback();
      unset($transaction);
      return $result;
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      \Drupal::entityTypeManager()->getStorage('node')->resetCache();
      throw $error;
    }
    finally {
      $this->writing = FALSE;
      unset($transaction);
      $lock->release('nelkano_modules.write');
    }
  }

  private function reload(NodeInterface $node, ?int $revision): NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    $current = $storage->load($node->id());
    if (!$current || (int) $current->getRevisionId() !== $revision) {
      throw new ConflictHttpException('La ficha ha cambiado. Recarga antes de guardar.');
    }
    return $current;
  }

  /** Never attach another user's upload or an unrelated managed file. */
  public function validateImage(int $fid, ?NodeInterface $module = NULL): ?\Drupal\file\FileInterface {
    if (!$fid) { return NULL; }
    $file = \Drupal\file\Entity\File::load($fid);
    $existing = $module && (int) $module->get('field_module_image')->target_id === $fid;
    if (!$file || (!$existing && (int) $file->getOwnerId() !== (int) \Drupal::currentUser()->id())
      || !str_starts_with($file->getFileUri(), 'public://nelkano-modules/images/')) {
      throw new \InvalidArgumentException('Selecciona una imagen subida desde este formulario.');
    }
    $violations = \Drupal::service('file.validator')->validate($file, [
      'FileExtension' => ['extensions' => 'png jpg jpeg webp'],
      'FileSizeLimit' => ['fileLimit' => 2 * 1024 * 1024], 'FileIsImage' => []]);
    $image = \Drupal::service('image.factory')->get($file->getFileUri());
    if (count($violations) || !$image->isValid() || $image->getFileSize() > 2 * 1024 * 1024 || $image->getWidth() > 4096 || $image->getHeight() > 4096) {
      throw new \InvalidArgumentException('La imagen debe ser PNG, JPG o WebP, de hasta 2 MB y 4096 × 4096 píxeles.');
    }
    return $file;
  }

  public function saveModule(?NodeInterface $module, ?int $revision, array $values, ?array $initial = NULL): NodeInterface {
    $this->authorize($module);
    if ($values['source'] !== '') { SecureDownloadClient::validateUrl($values['source']); }
    if (trim($values['title']) === '' || mb_strlen($values['title']) > 255
      || trim($values['description']) === '' || mb_strlen($values['description']) > 12000) {
      throw new \InvalidArgumentException('Completa el nombre y la descripción.');
    }
    if (array_key_exists('systems', $values)) { $values['systems'] = ModuleSystems::validate($values['systems']); }
    return $this->write(function () use ($module, $revision, $values, $initial) {
      if ($module) { $module = $this->reload($module, $revision); $this->authorize($module); }
      else {
        if (!$initial) { throw new \InvalidArgumentException('Añade una primera versión válida.'); }
        $ids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)
          ->condition('type', 'nelkano_module')->condition('field_module_id', $initial['id'])->execute();
        if ($ids) { throw new \InvalidArgumentException('Ya hay una ficha con ese ID de módulo.'); }
        $module = Node::create(['type' => 'nelkano_module', 'uid' => \Drupal::currentUser()->id(),
          'field_module_id' => $initial['id'], 'field_module_author' => $initial['author'],
          'field_module_available' => FALSE]);
      }
      $module->setTitle($values['title'])->set('field_module_description', $values['description'])
        ->set('field_module_source', $values['source'])->set('status', (int) $values['published']);
      if (array_key_exists('systems', $values)) { $module->set('field_module_systems', $values['systems']); }
      if (array_key_exists('image', $values)) {
        $file = $this->validateImage((int) $values['image'], $module);
        $module->set('field_module_image', $file ? ['target_id' => $file->id(), 'alt' => $values['title']] : []);
      }
      $module->setNewRevision(TRUE);
      $module->setRevisionUserId((int) \Drupal::currentUser()->id());
      $module->save();
      if ($initial) {
        $this->persistVersion($module, NULL, $initial, $values['notes'] ?? '');
        $this->refresh($module);
      }
      return $module;
    });
  }

  public function saveVersion(NodeInterface $module, ?NodeInterface $release, ?int $revision, array $verified, string $notes): NodeInterface {
    $this->authorize($module);
    return $this->write(function () use ($module, $release, $revision, $verified, $notes) {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $storage->resetCache([$module->id()]);
      $module = $storage->load($module->id());
      if (!$module) { throw new ConflictHttpException('La ficha ya no está disponible.'); }
      $this->authorize($module);
      if ($release) { $release = $this->reload($release, $revision); }
      $saved = $this->persistVersion($module, $release, $verified, $notes);
      $this->refresh($module);
      return $saved;
    });
  }

  private function persistVersion(NodeInterface $module, ?NodeInterface $release, array $verified, string $notes): NodeInterface {
    if ($verified['id'] !== $module->get('field_module_id')->value
      || $verified['author'] !== $module->get('field_module_author')->value
      || mb_strlen($notes) > 8000) {
      throw new \InvalidArgumentException('La versión debe pertenecer al mismo módulo y conservar su firma de autor.');
    }
    if ($release) {
      if ($release->bundle() !== 'nelkano_module_release' || (int) $release->get('field_release_module')->target_id !== (int) $module->id()
        || $verified['version'] !== $release->get('field_release_version')->value
        || $verified['sha256'] !== $release->get('field_release_hash')->value) {
        throw new \InvalidArgumentException('Para reparar un enlace, conserva exactamente el paquete de esa versión.');
      }
    }
    else {
      foreach ($this->releases($module) as $existing) {
        if ($existing->get('field_release_version')->value === $verified['version']) {
          throw new \InvalidArgumentException('Esa versión ya está registrada. Puedes reparar su enlace.');
        }
      }
      $release = Node::create(['type' => 'nelkano_module_release', 'uid' => $module->getOwnerId(),
        'status' => 0, 'field_release_module' => $module->id()]);
    }
    $release->setTitle($module->get('field_module_id')->value . ' ' . $verified['version']);
    foreach (['url', 'version', 'sdk', 'platforms', 'capabilities', 'size', 'etag', 'modified'] as $key) {
      $release->set('field_release_' . $key, $verified[$key]);
    }
    $release->set('field_release_hash', $verified['sha256'])->set('field_release_notes', $notes)
      ->set('field_release_state', 'available')->set('field_release_error', '')
      ->set('field_release_checked', time())->set('field_release_queued', 0);
    $release->setNewRevision(TRUE);
    $release->setRevisionUserId((int) \Drupal::currentUser()->id());
    $release->save();
    return $release;
  }

  private function refresh(NodeInterface $module): void {
    $platforms = [];
    foreach ($this->releases($module) as $release) {
      if ($release->get('field_release_state')->value === 'available') {
        foreach ($release->get('field_release_platforms') as $platform) { $platforms[$platform->value] = $platform->value; }
      }
    }
    $available = (bool) $platforms;
    $current = array_column($module->get('field_module_platforms')->getValue(), 'value');
    sort($current);
    $platforms = array_values($platforms);
    sort($platforms);
    if ((bool) $module->get('field_module_available')->value !== $available || $platforms !== $current) {
      $module->set('field_module_available', $available)->set('field_module_platforms', $platforms);
      $module->setNewRevision(FALSE);
      $module->save();
    }
  }

  /** Hourly queue scheduling; stale leases can be retried after one hour. */
  public function enqueueDue(?int $module_id = NULL): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $due = time() - 3600;
    $offset = 0;
    do {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'nelkano_module_release')
        ->condition('field_release_checked', $due, '<=')->condition('field_release_queued', $due, '<=')
        ->sort('nid')->range(0, 100);
      if ($module_id !== NULL) { $ids->condition('field_release_module', $module_id); }
      $ids = $ids->execute();
      foreach ($storage->loadMultiple($ids) as $release) {
        $this->write(function () use ($release) {
          $release->set('field_release_queued', time())->setNewRevision(FALSE);
          $release->save();
          \Drupal::queue('nelkano_module_links')->createItem(['id' => (int) $release->id()]);
        });
      }
      $offset += count($ids);
    } while (count($ids) === 100 && $offset < 10000);
  }

  /** Preserve availability on timeouts/429/5xx; retire absent/altered packages. */
  public function checkVersion(int $id): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$id]);
    $release = $storage->load($id);
    if (!$release || $release->bundle() !== 'nelkano_module_release') { return; }
    $revision = (int) $release->getRevisionId();
    $url = $release->get('field_release_url')->value;
    $hash = $release->get('field_release_hash')->value;
    $state = $release->get('field_release_state')->value;
    $response = NULL;
    $error = '';
    $validators = [];
    if ($state === 'available') {
      $validators = ['etag' => $release->get('field_release_etag')->value ?? '',
        'modified' => $release->get('field_release_modified')->value ?? ''];
    }
    try {
      $response = $this->downloader->fetch($url, $validators);
      if ($response['status'] === 304 && $state === 'available') {
        // Provider confirms the already-verified version is unchanged.
      }
      elseif ($response['status'] === 200) {
        $verified = $this->packages->verify($response['body']);
        if ($verified['sha256'] !== $hash) { throw new \InvalidArgumentException('La descarga ha cambiado respecto al paquete registrado.'); }
        $state = 'available';
      }
      elseif (($response['status'] >= 400 && $response['status'] < 500 && !in_array($response['status'], [408, 425, 429], TRUE))) { $state = 'unavailable'; $error = 'El archivo de esta versión ya no está disponible.'; }
      else { $error = 'El proveedor no respondió correctamente. Se volverá a comprobar.'; }
    }
    catch (\InvalidArgumentException $exception) { $state = 'unavailable'; $error = $exception->getMessage(); }
    catch (\RuntimeException) { $error = 'No se pudo conectar. Se volverá a comprobar.'; }
    $this->write(function () use ($id, $url, $hash, $state, $error, $response, $revision) {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $storage->resetCache([$id]);
      $release = $storage->load($id);
      if (!$release || (int) $release->getRevisionId() !== $revision || $release->get('field_release_url')->value !== $url
        || $release->get('field_release_hash')->value !== $hash) { return; }
      $release->set('field_release_state', $state)->set('field_release_error', $error)
        ->set('field_release_checked', time())->set('field_release_queued', 0)->setNewRevision(FALSE);
      if (isset($response) && $response['status'] === 200 && $state === 'available') {
        $release->set('field_release_etag', $response['etag'] ?? '')->set('field_release_modified', $response['modified'] ?? '');
      }
      $release->save();
      $module = $storage->load($release->get('field_release_module')->target_id);
      if ($module) { $this->refresh($module); }
    });
  }
}
