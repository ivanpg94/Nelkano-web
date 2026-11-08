(function (Drupal, once) {
  Drupal.behaviors.nelkanoAdminForms = {
    attach(context) {
      once('nelkano-admin-forms', '.nk-admin-config-form', context);
      once('nelkano-csv-lists', '[data-nk-catalog-editor]', context).forEach((editor) => {
        const es = editor.dataset.language !== 'en';
        const text = (spanish, english) => es ? spanish : english;
        const mount = editor.querySelector('.nk-csv-editor-mount');
        let lists = JSON.parse(editor.dataset.lists);
        let revision = editor.dataset.revision;
        const files = new Map();
        let dirty = false;
        let busy = false;
        const maxLists = Number(editor.dataset.maxLists);
        const node = (tag, className, content) => {
          const element = document.createElement(tag);
          if (className) element.className = className;
          if (content !== undefined) element.textContent = content;
          return element;
        };
        const rows = node('div', 'nk-csv-lists');
        const actions = node('div', 'nk-csv-actions');
        const add = node('button', 'nk-csv-add', text('＋ Añadir CSV', '＋ Add CSV'));
        const save = node('button', 'button button--primary nk-csv-save', text('Guardar compatibilidad', 'Save compatibility'));
        const status = node('p', 'nk-system-csv-status');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        const picker = node('input', 'nk-csv-batch-picker');
        picker.type = 'file'; picker.accept = '.csv,text/csv'; picker.multiple = true; picker.hidden = true;
        add.type = save.type = 'button';
        mount.append(rows, add, picker, actions, status);
        actions.append(save);
        const markDirty = () => { dirty = true; status.textContent = text('Cambios sin guardar.', 'Unsaved changes.'); };
        const validFile = (file) => {
          if (!file.name.toLowerCase().endsWith('.csv') || file.size > 5242880) {
            status.textContent = text('Selecciona archivos .csv de hasta 5 MB cada uno.', 'Select .csv files up to 5 MB each.');
            return false;
          }
          return true;
        };
        const render = () => {
          rows.replaceChildren();
          if (!lists.length) rows.append(node('p', 'nk-csv-empty', text('Añade el primer CSV para publicar la compatibilidad.', 'Add the first CSV to publish compatibility.')));
          lists.forEach((list, index) => {
            const row = node('section', 'nk-csv-list');
            const heading = node('div', 'nk-csv-list-heading');
            const title = node('strong', '', text(`Lista ${index + 1}`, `List ${index + 1}`) + (index === 0 ? text(' · Se abre por defecto', ' · Opens by default') : ''));
            const controls = node('div', 'nk-csv-list-controls');
            const up = node('button', '', '↑');
            const down = node('button', '', '↓');
            const remove = node('button', 'nk-csv-remove', text('Eliminar', 'Remove'));
            up.setAttribute('aria-label', text(`Subir lista ${index + 1}`, `Move list ${index + 1} up`));
            down.setAttribute('aria-label', text(`Bajar lista ${index + 1}`, `Move list ${index + 1} down`));
            [up, down, remove].forEach(button => { button.type = 'button'; controls.append(button); });
            up.disabled = index === 0; down.disabled = index === lists.length - 1; remove.disabled = lists.length === 1;
            const move = (offset) => { [lists[index], lists[index + offset]] = [lists[index + offset], lists[index]]; markDirty(); render(); };
            up.addEventListener('click', () => move(-1)); down.addEventListener('click', () => move(1));
            remove.addEventListener('click', () => { lists.splice(index, 1); files.delete(list.id); markDirty(); render(); });
            heading.append(title, controls);
            const fields = node('div', 'nk-csv-list-fields');
            const titleLabel = node('label', 'nk-csv-field', text('Título de la pestaña', 'Tab title'));
            const titleInput = node('input'); titleInput.type = 'text'; titleInput.maxLength = 80; titleInput.value = list.title;
            titleInput.dataset.nkListTitle = list.id;
            titleInput.addEventListener('input', () => { list.title = titleInput.value; markDirty(); });
            // A title edit belongs to this editor, not the surrounding Drupal form.
            titleInput.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); save.click(); } });
            titleLabel.append(titleInput);
            const fileLabel = node('label', 'nk-csv-field', text('Archivo CSV', 'CSV file'));
            const fileName = node('span', 'nk-csv-filename', files.get(list.id)?.name || list.filename || text('CSV actual conservado', 'Current CSV retained'));
            const replacement = node('input', 'nk-csv-file'); replacement.type = 'file'; replacement.accept = '.csv,text/csv';
            replacement.setAttribute('aria-label', text(`Sustituir CSV de la lista ${index + 1}`, `Replace CSV for list ${index + 1}`));
            replacement.addEventListener('change', () => { const file = replacement.files[0]; if (!file) return; if (validFile(file)) { files.set(list.id, file); fileName.textContent = file.name; markDirty(); } replacement.value = ''; });
            fileLabel.append(fileName, replacement);
            if (list.count !== undefined) fileLabel.append(node('small', '', text(`${list.count} registros guardados`, `${list.count} saved records`)));
            fields.append(titleLabel, fileLabel); row.append(heading, fields); rows.append(row);
          });
          add.disabled = lists.length >= maxLists;
          save.disabled = lists.length === 0;
        };
        add.addEventListener('click', () => picker.click());
        picker.addEventListener('change', () => {
          const selected = Array.from(picker.files); picker.value = '';
          if (selected.length + lists.length > maxLists) { status.textContent = text(`Máximo ${maxLists} listas por página.`, `Maximum ${maxLists} lists per page.`); return; }
          if (!selected.every(validFile)) return;
          selected.forEach(file => {
            const id = 'csv-' + crypto.randomUUID();
            lists.push({id, title: file.name.replace(/\.csv$/i, '').slice(0, 80), filename: file.name}); files.set(id, file);
          });
          if (selected.length) { markDirty(); render(); }
        });
        save.addEventListener('click', async () => {
          if (busy) return;
          for (const list of lists) {
            if (!list.title.trim()) { status.textContent = text('Escribe un título para cada pestaña.', 'Enter a title for every tab.'); rows.querySelector(`[data-nk-list-title="${list.id}"]`).focus(); return; }
          }
          const body = new FormData();
          body.append('lists', JSON.stringify(lists.map(({id, title}) => ({id, title}))));
          body.append('revision', revision);
          files.forEach((file, id) => body.append(`csvs[${id}]`, file));
          busy = true;
          const disabled = Array.from(mount.querySelectorAll('button, input')).filter(element => !element.disabled);
          disabled.forEach(element => { element.disabled = true; });
          status.textContent = text('Validando y guardando…', 'Validating and saving…');
          try {
            const response = await fetch(editor.dataset.nkCatalogEditor, {method: 'POST', credentials: 'same-origin', body, headers: {'X-CSRF-Token': editor.dataset.csrfToken, 'Accept': 'application/json'}});
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !Array.isArray(data.lists) || typeof data.revision !== 'string') throw new Error(data.error || text('No se pudo guardar. Recarga la página e inténtalo de nuevo.', 'Save failed. Reload the page and try again.'));
            lists = data.lists; revision = data.revision; files.clear(); dirty = false; render();
            status.textContent = text('Compatibilidad guardada y publicada.', 'Compatibility saved and published.');
          } catch (error) { status.textContent = error.message; }
          finally { busy = false; disabled.forEach(element => { element.disabled = false; }); add.disabled = lists.length >= maxLists; save.disabled = !lists.length; }
        });
        window.addEventListener('beforeunload', event => { if (dirty || busy) { event.preventDefault(); event.returnValue = ''; } });
        render();
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
