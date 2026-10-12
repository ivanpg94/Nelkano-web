(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.nelkanoModuleSearch = {
    attach(context) {
      once('nelkano-module-search', '[data-nm-search]', context).forEach(form => {
        let timer;
        let pending = false;
        let queued = null;
        const clear = form.querySelector('[data-nm-clear]');
        const feedback = form.parentElement.querySelector('.nm-feedback');
        function currentUrl() {
          const url = new URL(form.action, window.location.href);
          if (form.elements.q.value.trim()) url.searchParams.set('q', form.elements.q.value.trim());
          if (form.elements.plataforma.value) url.searchParams.set('plataforma', form.elements.plataforma.value);
          if (form.elements.sistema.value) url.searchParams.set('sistema', form.elements.sistema.value);
          return url;
        }
        async function search(url = currentUrl()) {
          clearTimeout(timer);
          if (pending) { queued = url; return; }
          pending = true;
          queued = null;
          feedback.textContent = '';
          document.getElementById('nm-results').setAttribute('aria-busy', 'true');
          const ajax = Drupal.ajax({url: url.href, httpMethod: 'GET', progress: false});
          try {
            await new Promise((resolve, reject) => {
              const success = ajax.success;
              ajax.success = function (response, status) {
                return Promise.resolve(success.call(this, response, status)).then(resolve, reject);
              };
              ajax.error = () => reject(new Error('Search failed'));
              ajax.execute().fail(reject);
            });
            history.replaceState(null, '', url.href);
            clear.hidden = !form.elements.q.value.trim() && !form.elements.plataforma.value && !form.elements.sistema.value;
          } catch (error) {
            feedback.textContent = 'No se pudo actualizar la búsqueda. Vuelve a intentarlo.';
          } finally {
            Drupal.ajax.instances[ajax.instanceIndex] = null;
            document.getElementById('nm-results').removeAttribute('aria-busy');
            pending = false;
            if (queued) search(queued);
          }
        }
        form.addEventListener('submit', event => { event.preventDefault(); search(); });
        form.elements.q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => search(), 300); });
        form.elements.plataforma.addEventListener('change', () => search());
        form.elements.sistema.addEventListener('change', () => search());
        clear.addEventListener('click', event => { event.preventDefault(); form.reset(); form.elements.q.value = ''; form.elements.plataforma.value = ''; form.elements.sistema.value = ''; search(); });
        form.parentElement.addEventListener('click', event => {
          const link = event.target.closest('[data-nm-page]');
          if (link) { event.preventDefault(); search(new URL(link.href)); }
        });
      });
    }
  };
})(Drupal, once);