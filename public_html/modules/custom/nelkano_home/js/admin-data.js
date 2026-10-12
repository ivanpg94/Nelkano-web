(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.nelkanoAdminDataTable = {
    attach(context) {
      // Retain Views' table and its form; only the table scrolls on narrow screens.
      once('nk-report-table-scroll', '.nk-admin-data .view-nelkano-error-reports table', context).forEach(table => {
        const scroll = document.createElement('div');
        scroll.className = 'nk-report-table-scroll';
        scroll.tabIndex = 0;
        scroll.setAttribute('role', 'region');
        scroll.setAttribute('aria-label', 'Tabla de reportes, desplazamiento horizontal');
        table.before(scroll);
        scroll.appendChild(table);
      });
    }
  };
})(Drupal, once);