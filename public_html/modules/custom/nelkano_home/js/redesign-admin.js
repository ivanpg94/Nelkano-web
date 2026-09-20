(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.nelkanoRedesignAdmin = {
    attach(context) {
      // Reveal invalid fields even when their editing sections are folded.
      once('r-section-validation', '.nk-redesign-admin form', context).forEach(form => {
        form.addEventListener('invalid', event => {
          let section = event.target.closest('details');
          while (section) { section.open = true; section = section.parentElement.closest('details'); }
        }, true);
        form.querySelectorAll('.error, [aria-invalid="true"]').forEach(field => {
          let section = field.closest('details');
          while (section) { section.open = true; section = section.parentElement.closest('details'); }
        });
      });
      once('r-systems', '.nk-redesign-admin form:has([data-r-system])', context).forEach(form => {
        const systems = [...form.querySelectorAll('[data-r-system]')];
        const picker = document.createElement('div'); picker.className = 'r-system-picker';
        picker.setAttribute('role', 'group'); picker.setAttribute('aria-label', 'Sistema');
        const tabs = document.createElement('div'); tabs.className = 'r-admin-tabs';
        tabs.setAttribute('role','group'); tabs.setAttribute('aria-label','Contenido del sistema');
        let selected = systems[0].dataset.rSystem, activeTab = 'textos';
        const sync = () => {
          systems.forEach(panel => {panel.hidden = panel.dataset.rSystem !== selected; panel.open = true;
            panel.querySelectorAll('[data-r-tab]').forEach(group => {group.hidden = group.dataset.rTab !== activeTab;});
          });
          picker.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed',String(b.dataset.system === selected)));
          tabs.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed',String(b.dataset.tab === activeTab)));
        };
        systems.forEach(panel => {
          panel.classList.add('r-system-enhanced');
          const b = document.createElement('button'); b.type = 'button';
          b.textContent = panel.querySelector(':scope > summary').textContent.trim(); b.dataset.system = panel.dataset.rSystem;
          b.addEventListener('click', () => {selected=b.dataset.system;activeTab='textos';sync();}); picker.append(b);
        });
        Object.entries({textos:'Textos y estado',imagen:'Imagen',compatibilidad:'Compatibilidad CSV'}).forEach(([key,label]) => {
          const b=document.createElement('button');b.type='button';b.textContent=label;b.dataset.tab=key;
          b.addEventListener('click',()=>{activeTab=key;sync();});tabs.append(b);
        });
        if (!form.querySelector('[data-r-server-picker]')) systems[0].before(picker);
        systems[0].before(tabs);sync();
        // Native validation must reveal the selected field before the browser focuses it.
        form.addEventListener('invalid', event => {const p=event.target.closest('[data-r-system]');if(p){selected=p.dataset.rSystem;activeTab=event.target.closest('[data-r-tab]')?.dataset.rTab||'textos';sync();}},true);
      });
      once('r-save-section', '.nk-redesign-admin .r-save-section', context).forEach(button=>{const summary=button.closest('details')?.querySelector(':scope > summary');if(summary){summary.append(button);button.addEventListener('click',e=>e.stopPropagation());}});
      once('r-open-card', '.nk-redesign-admin .nk-config-row-card', context).forEach(card => {
        const summary=card.querySelector(':scope > summary');
        const controls=document.createElement('span');controls.className='r-row-order';
        [['↑',-1,'Subir'],['↓',1,'Bajar']].forEach(([symbol,direction,label])=>{
          const button=document.createElement('button');button.type='button';button.textContent=symbol;button.setAttribute('aria-label',label);
          button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();const parent=card.parentElement;const cards=[...parent.querySelectorAll(':scope > .nk-config-row-card')];const index=cards.indexOf(card);const other=cards[index+direction];if(!other)return;if(direction<0)parent.insertBefore(card,other);else parent.insertBefore(other,card);[...parent.querySelectorAll(':scope > .nk-config-row-card')].forEach((row,i)=>{row.querySelector('[data-r-row-weight]').value=i;});});controls.append(button);
        });summary.append(controls);
      });
    }
  };
})(Drupal, once);
