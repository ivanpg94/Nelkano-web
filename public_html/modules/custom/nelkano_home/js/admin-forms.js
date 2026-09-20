(function (Drupal, once) {
  Drupal.behaviors.nelkanoAdminForms = {
    attach(context) {
      once('nelkano-admin-forms', '.nk-admin-config-form', context);
      once('nelkano-csv-upload', '[data-nk-csv-upload]', context).forEach((input) => {
        input.addEventListener('change', async () => {
          const file = input.files[0];
          if (!file) return;
          const status = input.closest('.nk-system-csv').querySelector('.nk-system-csv-status');
          const es = input.dataset.language !== 'en';
          status.textContent = es ? 'Subiendo…' : 'Uploading…';
          input.disabled = true;
          const body = new FormData();
          body.append('csv', file);
          try {
            const response = await fetch(input.dataset.nkCsvUpload, {
              method: 'POST', credentials: 'same-origin', body,
              headers: { 'X-CSRF-Token': input.dataset.csrfToken, 'Accept': 'application/json' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || (es ? 'No se pudo subir el CSV. Recarga la página e inténtalo de nuevo.' : 'Upload failed. Reload the page and try again.'));
            status.textContent = es ? `CSV actualizado: ${data.count} registros.` : `CSV updated: ${data.count} records.`;
          } catch (error) {
            status.textContent = error.message;
          } finally {
            input.disabled = false;
            input.value = '';
          }
        });
      });
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
