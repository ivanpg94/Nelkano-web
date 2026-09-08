<?php

/** Implements hook_views_data_alter(). */
function nelkano_home_views_data_alter(array &$data): void {
  $data['node_field_data']['nelkano_report_bulk'] = [
    'title' => t('Selección y estado de reportes Nelkano'),
    'help' => t('Casillas y cambio masivo de estado para reportes del emulador.'),
    'field' => ['id' => 'nelkano_report_bulk'],
  ];
}
