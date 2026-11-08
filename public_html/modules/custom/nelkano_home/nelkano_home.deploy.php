<?php
/** Import this release's changed canonical SDK documentation once, through drush deploy. */
function nelkano_home_deploy_sdk_reference_20261108(): string {
  $transfer=\Drupal::service('book_import_export.transfer');
  $plan=$transfer->import($transfer->read(dirname(DRUPAL_ROOT).'/content/books/nelkano-sdk.yml'));
  return 'SDK documentation reconciled: '.count($plan).' entries; omitted pages retained.';
}
