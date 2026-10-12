(function (Drupal, once, settings) {
  'use strict';
  // Core Drupal AJAX executes replacement commands and reattaches behaviors.
  Drupal.behaviors.nelkanoBacklogFilter = {
    attach(context) {
      once('nelkano-backlog-filter', '[data-nk-backlog-filter]', context).forEach(form => {
        const search = form.elements.q;
        const sprint = form.elements.sprint;
        const clear = form.querySelector('[data-nk-backlog-clear]');
        let timer;
        let pending = false;
        let queued = false;
        async function filter() {
          clearTimeout(timer);
          if (pending || document.querySelector('.nk-hu-card[aria-busy="true"]')) {
            queued = true;
            return;
          }
          const url = new URL(form.action, window.location.href);
          if (search.value.trim()) url.searchParams.set('q', search.value.trim());
          if (sprint.value !== '') url.searchParams.set('sprint', sprint.value);
          pending = true;
          queued = false;
          const results = document.getElementById('nk-backlog-results');
          results.setAttribute('aria-busy', 'true');
          results.querySelectorAll('select, .nk-hu-delete').forEach(select => { select.disabled = true; });
          const ajax = Drupal.ajax({url: url.href, httpMethod: 'GET', progress: false});
          // Dispose each transient instance, including its possible error state.
          try {
            // jqXHR resolves before Drupal's asynchronous command queue finishes.
            await new Promise((resolve, reject) => {
              const success = ajax.success;
              ajax.success = function (response, status) {
                return Promise.resolve(success.call(this, response, status)).then(resolve, reject);
              };
              ajax.error = () => reject(new Error('Search request failed'));
              ajax.execute().fail(reject);
            });
            window.history.replaceState(null, '', url.href);
            clear.hidden = search.value.trim() === '' && sprint.value === '';
          } catch (error) {
            const feedback = document.querySelector('.nk-backlog-feedback');
            feedback.textContent = 'No se pudo buscar. Vuelve a intentarlo.';
            feedback.classList.add('is-error');
          } finally {
            Drupal.ajax.instances[ajax.instanceIndex] = null;
            pending = false;
            const current = document.getElementById('nk-backlog-results');
            current.removeAttribute('aria-busy');
            current.querySelectorAll('select, .nk-hu-delete').forEach(select => { select.disabled = false; });
            if (queued) filter();
          }
        }
        form.addEventListener('submit', event => { event.preventDefault(); filter(); });
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(filter, 300); });
        sprint.addEventListener('change', filter);
        clear.addEventListener('click', event => {
          event.preventDefault();
          search.value = '';
          sprint.value = '';
          filter();
        });
        form.addEventListener('nelkano-write-complete', () => { if (queued) filter(); });
      });
    }
  };
  Drupal.behaviors.nelkanoBacklog = {
    attach(context) {
      once('nelkano-backlog', '[data-nk-backlog]', context).forEach(board => {
        const feedback = board.previousElementSibling;
        let dragging = null;
        function announce(message, error = false) {
          feedback.textContent = message;
          feedback.classList.toggle('is-error', error);
        }
        function recount() {
          board.querySelectorAll('.nk-hu-column').forEach(column => {
            const count = column.querySelectorAll('.nk-hu-card').length;
            column.querySelector('.nk-hu-count').textContent = count;
            column.querySelector('.nk-hu-empty').hidden = count !== 0;
          });
        }
        async function move(card, status) {
          const original = card.closest('.nk-hu-column').dataset.status;
          const select = card.querySelector('select');
          if (card.getAttribute('aria-busy') === 'true' || board.closest('[aria-busy="true"]')) return;
          if (original === status) { select.value = original; return; }
          card.setAttribute('aria-busy', 'true');
          card.draggable = false;
          select.disabled = true;
          card.querySelector('.nk-hu-delete').disabled = true;
          announce(`Guardando HU ${card.dataset.number}…`);
          try {
            const response = await fetch(card.dataset.moveUrl, {
              method: 'POST', credentials: 'same-origin',
              headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': settings.nelkanoBacklog.csrfToken},
              body: JSON.stringify({status, revision: Number(card.dataset.revision)})
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || 'No se pudo guardar. Recarga la página y vuelve a intentarlo.');
            card.dataset.revision = data.revision;
            const column = Array.from(board.querySelectorAll('.nk-hu-column')).find(item => item.dataset.status === data.status);
            column.querySelector('.nk-hu-cards').appendChild(card);
            select.value = data.status;
            recount();
            announce(`HU ${card.dataset.number} guardada en ${select.selectedOptions[0].textContent}.`);
          } catch (error) {
            select.value = original;
            announce(error.message || 'No se pudo confirmar el cambio. Recarga la página.', true);
          } finally {
            card.removeAttribute('aria-busy');
            card.draggable = true;
            select.disabled = false;
            card.querySelector('.nk-hu-delete').disabled = false;
            document.querySelector('[data-nk-backlog-filter]')?.dispatchEvent(new Event('nelkano-write-complete'));
          }
        }
        async function remove(card) {
          if (card.getAttribute('aria-busy') === 'true' || board.closest('[aria-busy="true"]')) return;
          const number = card.dataset.number;
          if (!window.confirm(`¿Eliminar la HU ${number}? Se eliminarán la historia y todas sus revisiones. Esta acción no se puede deshacer.`)) return;
          const button = card.querySelector('.nk-hu-delete');
          const select = card.querySelector('select');
          card.setAttribute('aria-busy', 'true');
          card.draggable = false;
          button.disabled = true;
          select.disabled = true;
          announce(`Eliminando HU ${number}…`);
          try {
            const response = await fetch(card.dataset.deleteUrl, {
              method: 'POST', credentials: 'same-origin',
              headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': settings.nelkanoBacklog.csrfToken},
              body: JSON.stringify({revision: Number(card.dataset.revision)})
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.deleted || data.id !== Number(card.dataset.id)) {
              throw new Error(data.error || 'No se pudo confirmar la eliminación. Actualiza el backlog antes de volver a intentarlo.');
            }
            const next = Array.from(board.querySelectorAll('.nk-hu-card')).find(item => item !== card);
            card.remove();
            recount();
            document.getElementById('nk-backlog-total').textContent = board.querySelectorAll('.nk-hu-card').length;
            announce(`HU ${number} eliminada.`);
            (next?.querySelector('h3 a') || document.getElementById('nk-hu-search')).focus();
          } catch (error) {
            announce(error.message || 'No se pudo eliminar la HU. Vuelve a intentarlo.', true);
          } finally {
            card.removeAttribute('aria-busy');
            card.draggable = true;
            button.disabled = false;
            select.disabled = false;
            document.querySelector('[data-nk-backlog-filter]')?.dispatchEvent(new Event('nelkano-write-complete'));
          }
        }
        board.querySelectorAll('.nk-hu-card').forEach(card => {
          card.querySelector('select').addEventListener('change', event => move(card, event.target.value));
          card.querySelector('.nk-hu-delete').addEventListener('click', () => remove(card));
          card.addEventListener('dragstart', event => {
            if (card.getAttribute('aria-busy') === 'true' || event.target.closest('select, button, a')) { event.preventDefault(); return; }
            dragging = card;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.id);
            card.classList.add('is-dragging');
          });
          card.addEventListener('dragend', () => {
            dragging = null;
            card.classList.remove('is-dragging');
            board.querySelectorAll('.is-over').forEach(column => column.classList.remove('is-over'));
          });
        });
        board.querySelectorAll('.nk-hu-column').forEach(column => {
          column.addEventListener('dragover', event => {
            if (!dragging) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            column.classList.add('is-over');
          });
          column.addEventListener('dragleave', event => {
            if (!column.contains(event.relatedTarget)) column.classList.remove('is-over');
          });
          column.addEventListener('drop', event => {
            event.preventDefault();
            column.classList.remove('is-over');
            if (dragging) move(dragging, column.dataset.status);
          });
        });
      });
    }
  };
})(Drupal, once, drupalSettings);
