<?php

namespace Drupal\nelkano_home\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Url;
use Drupal\book_import_export\BookTransfer;

/** Common presentation and immutable scope for the SDK book administration. */
abstract class SdkBookAdminFormBase extends FormBase {
  use AdminFormUiTrait { activeAdminLanguage as protected; }

  public const BOOK_UUID = 'de520020-2026-4000-8000-000000000001';

  protected function transfer(): BookTransfer {
    return \Drupal::service('book_import_export.transfer');
  }

  public static function assertScope(array $data): void {
    if (($data['book'] ?? '') !== self::BOOK_UUID) {
      throw new \InvalidArgumentException('El archivo debe corresponder al libro SDK de Nelkano.');
    }
    if (!is_array($data['pages'] ?? NULL)) {
      throw new \InvalidArgumentException('El archivo debe contener una lista de p�ginas.');
    }
    foreach ($data['pages'] as $page) {
      if (($page['type'] ?? '') !== 'sdk_page') {
        throw new \InvalidArgumentException('Solo se admiten páginas de tipo SDK.');
      }
    }
  }

  protected function currentData(): array {
    $data = $this->transfer()->export(self::BOOK_UUID);
    self::assertScope($data);
    return $data;
  }

  protected function chrome(array &$form, string $title, string $description): void {
    $this->applyNelkanoAdminChrome($form, 'sdk', $title, $description, '/es/sdk-de-nelkano', $this->activeAdminLanguage());
    $form['#attached']['library'][] = 'nelkano_home/sdk_admin';
    $form['#cache']['max-age'] = 0;
    $form['tools'] = ['#type' => 'container', '#weight' => -990, '#attributes' => ['class' => ['nk-sdk-tools']]];
    foreach (['export' => ['Exportar', 'nelkano_home.admin_sdk_export'], 'import' => ['Importar', 'nelkano_home.admin_sdk_import']] as $key => [$label, $route]) {
      $form['tools'][$key] = ['#type' => 'link', '#title' => $label, '#url' => Url::fromRoute($route),
        '#attributes' => ['class' => ['nk-admin-public-link']]];
    }
    $form['tools']['back'] = ['#type' => 'link', '#title' => 'Libro y capítulos', '#url' => Url::fromRoute('nelkano_home.admin_sdk'), '#attributes' => ['class' => ['nk-admin-public-link']]];
  }

  protected function pageNode(string $uuid): ?\Drupal\node\NodeInterface {
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]);
    return $nodes ? reset($nodes) : NULL;
  }
}
