(function (Drupal, once) {
  Drupal.behaviors.nelkanoAdminForms = {
    attach(context) {
      once('nelkano-admin-open-details', '.nk-admin-config-form details', context).forEach((details) => {
        if (details.parentElement && details.parentElement.matches('.nk-admin-config-form')) {
          details.open = true;
        }
      });
    },
  };
})(Drupal, once);
