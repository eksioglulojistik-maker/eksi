(() => {
 'use strict';
 const root=document.querySelector('.svc-workspace'),stateNode=document.getElementById('svc-state');
 if(!root||!stateNode)return;
 const state=JSON.parse(stateNode.textContent),tabs=[...root.querySelectorAll('[data-room-tab]')],grids=[...root.querySelectorAll('[data-room-grid]')];
 const cart=document.getElementById('svc-cart-items'),template=document.getElementById('svc-cart-template'),message=document.getElementById('svc-message'),search=document.getElementById('svc-search');
 const storageKey='sv-room:'+state.actor_id+':'+state.survey_id;
 let selected=state.selected,snapshot=state.snapshot,busy=false,uncertain=false,editDirty=false;
 const norm=text=>String(text).toLocaleLowerCase('tr').normalize('NFKD').replace(/[\u0300-\u036f]/g,'').replace(/ı/g,'i');
 const number=value=>Number(value).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
 if(!new URL(location.href).searchParams.has('room')){try{const saved=sessionStorage.getItem(storageKey);if(Object.prototype.hasOwnProperty.call(state.rooms,saved))selected=saved;}catch{}}
 const setControls=()=>root.querySelectorAll('[data-catalog-add] button,[data-catalog-step] button,[data-catalog-clear] button').forEach(button=>{button.disabled=state.locked||busy||uncertain||(button.closest('[data-catalog-clear]')&&snapshot.items.length===0);});
 const showMessage=(text,error=false)=>{message.textContent=text;message.classList.toggle('svc-error',error);};
 const filter=()=>{
  const term=norm(search.value.trim());let matches=0;
  grids.forEach(grid=>{grid.hidden=grid.dataset.roomGrid!==selected;grid.querySelectorAll('form[data-catalog-add]').forEach(form=>{const found=norm(form.dataset.search).includes(term);form.hidden=!found;if(!grid.hidden&&found)matches++;});});
  document.getElementById('svc-search-empty').hidden=matches!==0;
  let visible=0;cart.querySelectorAll('[data-item-id]').forEach(card=>{card.hidden=card.dataset.roomKey!==selected;if(!card.hidden)visible++;});
  document.getElementById('svc-cart-empty').hidden=visible!==0;
 };
 const renderTotals=()=>{
  const room=snapshot.rooms[selected]||{volume:0,pieces:0};
  document.getElementById('svc-room-title').textContent=state.rooms[selected][0];
  document.getElementById('svc-room-total').textContent=number(room.volume)+' m³';
  document.getElementById('svc-room-pieces').textContent=room.pieces+' adet';
  document.getElementById('svc-total-volume').textContent=number(snapshot.volume)+' m³';
  document.getElementById('svc-total-pieces').textContent=snapshot.pieces+' adet';
  tabs.forEach(tab=>{const active=tab.dataset.roomTab===selected;tab.classList.toggle('is-active',active);tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;tab.querySelector('[data-room-count]').textContent=snapshot.rooms[tab.dataset.roomTab]?.pieces||0;});
  const topVolume=document.querySelector('.sv-summarybar span:nth-child(2) b'),topPieces=document.querySelector('.sv-summarybar span:nth-child(3) b');
  if(topVolume)topVolume.textContent=number(snapshot.volume)+' m³';if(topPieces)topPieces.textContent=snapshot.pieces+' adet';
 };
 const chooseRoom=key=>{
  selected=key;try{sessionStorage.setItem(storageKey,key);}catch{}
  const url=new URL(location.href);url.searchParams.set('room',key);url.hash='sv-inventory';history.replaceState(null,'',url);
  const manual=document.getElementById('sv-item-name')?.closest('form')?.elements.namedItem('room');if(manual)manual.value=state.rooms[key][0];
  renderTotals();filter();
 };
 root.querySelector('.svc-room-tabs').setAttribute('role','tablist');
 tabs.forEach((tab,index)=>{
  tab.id='svc-room-'+tab.dataset.roomTab;tab.setAttribute('role','tab');tab.setAttribute('aria-controls','svc-grid-'+tab.dataset.roomTab);
  tab.addEventListener('click',event=>{event.preventDefault();chooseRoom(tab.dataset.roomTab);});
  tab.addEventListener('keydown',event=>{let next;if(event.key==='ArrowRight')next=(index+1)%tabs.length;if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;if(event.key==='Home')next=0;if(event.key==='End')next=tabs.length-1;if(next!==undefined){event.preventDefault();tabs[next].click();tabs[next].focus();}});
 });
 grids.forEach(grid=>{grid.id='svc-grid-'+grid.dataset.roomGrid;grid.setAttribute('role','tabpanel');grid.setAttribute('aria-labelledby','svc-room-'+grid.dataset.roomGrid);});
 search.addEventListener('input',filter);
 const renderCart=()=>{
  const fragment=document.createDocumentFragment();
  snapshot.items.forEach(item=>{
   const card=template.content.firstElementChild.cloneNode(true);card.dataset.itemId=item.id;card.dataset.roomKey=item.room_key;
   card.querySelector('[data-cart-name]').textContent=item.item_name;card.querySelector('[data-cart-room]').textContent=item.room;
   card.querySelector('.svc-cart-emoji').textContent=state.rooms[item.room_key][1];
   card.querySelector('[data-cart-volume]').textContent=number(item.quantity*item.unit_volume)+' m³';card.querySelector('[data-cart-unit]').textContent=number(item.unit_volume)+' m³ / adet';card.querySelector('[data-cart-quantity]').textContent=item.quantity;
   card.querySelectorAll('input[name="item_id"]').forEach(input=>{input.value=item.id;});
   card.querySelectorAll('.svc-step').forEach(button=>{button.setAttribute('aria-label',item.item_name+(button.value==='1'?' adedini artır':' adedini azalt'));});
   const edit=card.querySelector('.sv-edit-form');if(edit)for(const name of ['room','item_name','quantity','unit_volume'])edit.elements.namedItem(name).value=item[name];
   const note=card.querySelector('[data-cart-note]');note.textContent=item.notes||'';note.hidden=!item.notes;
   fragment.append(card);
  });
  cart.replaceChildren(fragment);window.ekVolumeInit?.(cart);editDirty=false;renderTotals();filter();setControls();
 };
 cart.addEventListener('input',()=>{editDirty=true;});
 root.addEventListener('submit',async event=>{
  const form=event.target;if(!form.matches('[data-catalog-add],[data-catalog-step],[data-catalog-clear]'))return;
  event.preventDefault();if(state.locked||busy||uncertain)return;
  const clearing=form.matches('[data-catalog-clear]');
  if(clearing){if(!snapshot.items.length)return;if(!await window.ekConfirm('Bu ekspertizin tüm odalarındaki '+snapshot.pieces+' adet eşya kaldırılacak. Envanter sıfırlanacak; bu işlem geri alınamaz.',{title:'Envanteri temizle',confirmText:'Tümünü temizle',tone:'danger'}))return;}
  if(!clearing&&editDirty&&!await window.ekConfirm('Kaydedilmemiş eşya düzenlemesi var. Kart işlemiyle bu düzenleme kaldırılacak. Devam edilsin mi?'))return;
  const body=new FormData(form);body.set('room_key',selected);
  if(event.submitter?.name)body.set(event.submitter.name,event.submitter.value);
  if(clearing){body.set('revision',snapshot.revision);body.set('confirm_clear','1');}
  const random=crypto.getRandomValues(new Uint8Array(16));body.set('request_id',[...random].map(value=>value.toString(16).padStart(2,'0')).join(''));
  busy=true;setControls();document.getElementById('svc-save-state').textContent='Kaydediliyor…';showMessage('');
  let knownResponse=false,committed=false;const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),15000);
  try{
   const response=await fetch('ekspertiz.php',{method:'POST',body,credentials:'same-origin',signal:controller.signal,headers:{'X-Requested-With':'SurveyCatalog','Accept':'application/json'}});
   if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('İşlem sonucu doğrulanamadı. Sayfayı yenileyerek kayıtlı envanteri kontrol edin.');
   const result=await response.json();knownResponse=true;
   if(!response.ok||!result.ok)throw new Error(result.error||'Eşya kaydedilemedi.');
   committed=true;snapshot=result.snapshot;renderCart();showMessage(clearing?'Tüm odalardaki envanter temizlendi.':form.matches('[data-catalog-add]')?'Eşya odaya eklendi.':'Eşya adedi güncellendi.');document.getElementById('svc-save-state').textContent='✓ Kaydedildi';
  }catch(error){
   if(committed){uncertain=true;showMessage('Kayıt yapıldı ancak görünüm güncellenemedi. Sayfayı yenileyip envanteri kontrol edin.',true);document.getElementById('svc-save-state').textContent='Görünümü yenileyin';}
   else if(!knownResponse){uncertain=true;showMessage('Bağlantı nedeniyle sonuç doğrulanamadı. Aynı işlemi tekrarlamadan sayfayı yenileyip kayıtlı listeyi kontrol edin.',true);document.getElementById('svc-save-state').textContent='Kontrol gerekiyor';}
   else{showMessage(error.message,true);document.getElementById('svc-save-state').textContent='İşlem kaydedilmedi';}
   document.getElementById('svc-reload').hidden=false;
  }finally{clearTimeout(timer);busy=false;setControls();}
 });
 chooseRoom(selected);setControls();
})();
