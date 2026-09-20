<?php
/** Run with Drush to regenerate the shipped header logo from its original. */
$path = DRUPAL_ROOT . '/modules/custom/nelkano_home/assets/';
$image = \Drupal::service('image.factory')->get($path . 'logo.png');
if (!$image->isValid() || !$image->scale(132, 132) || !$image->convert('webp') || !$image->save($path . 'logo-nav.webp')) {
  throw new RuntimeException('Unable to generate optimized header logo.');
}
echo 'Header logo: ' . filesize($path . 'logo-nav.webp') . " bytes\n";
