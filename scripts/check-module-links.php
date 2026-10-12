<?php

/** Dedicated hourly job: drush php:script scripts/check-module-links.php. */
if (PHP_SAPI !== 'cli' || !class_exists('\Drupal')) { http_response_code(404); exit; }
if (!\Drupal::moduleHandler()->moduleExists('nelkano_modules')) { throw new RuntimeException('The module catalog is not enabled.'); }
\Drupal::service('nelkano_modules.registry')->enqueueDue();
$queue = \Drupal::queue('nelkano_module_links');
$worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('nelkano_module_links');
$deadline = microtime(TRUE) + 240;
$processed = 0;
while (microtime(TRUE) < $deadline && ($item = $queue->claimItem(60))) {
  try { $worker->processItem($item->data); $queue->deleteItem($item); $processed++; }
  catch (\Throwable $error) { $queue->releaseItem($item); throw $error; }
}
\Drupal\search_api\Entity\Index::load('nelkano_modules')->indexItems();
echo "Checked $processed module releases.\n";