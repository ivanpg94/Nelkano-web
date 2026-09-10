(function (Drupal, once, settings) {
  'use strict';
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
          if (card.getAttribute('aria-busy') === 'true') return;
          if (original === status) { select.value = original; return; }
          card.setAttribute('aria-busy', 'true');
          card.draggable = false;
          select.disabled = true;
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
          }
        }
        board.querySelectorAll('.nk-hu-card').forEach(card => {
          card.querySelector('select').addEventListener('change', event => move(card, event.target.value));
          card.addEventListener('dragstart', event => {
            if (card.getAttribute('aria-busy') === 'true' || event.target.closest('select')) { event.preventDefault(); return; }
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
