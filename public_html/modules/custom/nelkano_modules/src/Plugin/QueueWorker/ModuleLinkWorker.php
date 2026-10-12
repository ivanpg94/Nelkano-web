<?php

namespace Drupal\nelkano_modules\Plugin\QueueWorker;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[QueueWorker(id: 'nelkano_module_links', title: new TranslatableMarkup('Revisar descargas de módulos'), cron: ['time' => 120])]
final class ModuleLinkWorker extends QueueWorkerBase {
  public function processItem($data): void {
    \Drupal::service('nelkano_modules.registry')->checkVersion((int) $data['id']);
  }
}