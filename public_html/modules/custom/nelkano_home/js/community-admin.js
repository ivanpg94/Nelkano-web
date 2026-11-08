(function(Drupal,once){
  Drupal.behaviors.nelkanoCommunityModeration={attach(context){
    once('nelkano-community-moderation','[data-nk-community-moderation]',context).forEach(section=>{
      section.addEventListener('click',async event=>{
        const button=event.target.closest('[data-community-action]');if(!button||button.disabled)return;
        const row=button.closest('[data-rating-id]'),status=row.querySelector('.nk-community-status');
        const payload={id:row.dataset.ratingId,revision:Number(row.dataset.revision),action:button.dataset.communityAction};
        if(payload.action==='associate'){payload.game_id=row.querySelector('[data-community-target]').value;if(!payload.game_id){status.textContent='Selecciona un juego antes de confirmar.';return;}}
        else payload.visible=button.dataset.communityVisible==='1';
        const buttons=Array.from(row.querySelectorAll('button'));buttons.forEach(b=>b.disabled=true);status.textContent='Guardando…';
        try{
          const response=await fetch(section.dataset.nkCommunityModeration,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':section.dataset.csrfToken,'Accept':'application/json'},body:JSON.stringify(payload)});
          const result=await response.json();if(!response.ok||!result.ok)throw Error(result.code||'No se pudo guardar.');
          status.textContent='Guardado. Recarga la página para seguir moderando.';
        }catch(error){status.textContent=error.message;buttons.forEach(b=>b.disabled=false);}
      });
    });
  }};
})(Drupal,once);
