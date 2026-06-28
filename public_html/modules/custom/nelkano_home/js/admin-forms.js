(function (Drupal, once) {
  Drupal.behaviors.nelkanoAdminForms = {
    attach(context) {
      once('nelkano-admin-forms', '.nk-admin-config-form', context);
      once('nelkano-section-toggle', '.nk-admin-section > .details-wrapper > .nk-section-toggle', context).forEach((toggle) => {
        const section = toggle.closest('.nk-admin-section');
        const summary = section ? section.querySelector(':scope > summary') : null;
        const checkbox = toggle.querySelector('input[type="checkbox"]');
        if (!section || !summary || !checkbox) {
          return;
        }

        const mount = document.createElement('span');
        mount.className = 'nk-section-toggle-slot';
        summary.appendChild(mount);
        mount.appendChild(toggle);

        const sync = () => {
          section.classList.toggle('is-section-disabled', !checkbox.checked);
          toggle.classList.toggle('is-enabled', checkbox.checked);
        };

        toggle.addEventListener('click', (event) => event.stopPropagation());
        checkbox.addEventListener('change', sync);
        sync();
      });
    },
  };
})(Drupal, once);
