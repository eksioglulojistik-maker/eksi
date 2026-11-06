(()=>{
'use strict';
const form=document.getElementById('mq-form');if(!form)return;
const $=s=>document.querySelector(s),$$=s=>Array.from(document.querySelectorAll(s));
const panels=$$('.mq-panel'),nav=$$('.mq-step'),next=$('#mq-next'),prev=$('#mq-prev'),submit=$('#mq-submit');
const status=$('#mq-save-status'),id=new URLSearchParams(location.search).get('id')||'new';
const context=form.dataset.draftScope;
const storageKey='eksioglu-moving-draft-v334-'+form.dataset.user+'-'+context;
const protectedFields=['csrf','record_version','survey_id','survey_version','survey_nonce','editor_token'];
if(form.dataset.calculatorTransfer==='1')protectedFields.push('volume','inventory_summary');
if(form.dataset.survey&&form.dataset.survey!=='0')protectedFields.push('customer_id','volume','inventory_summary');
let step=0,saveTimer=null,submitting=false;
const num=selector=>Math.max(0,($(selector)?.matches('[data-ek-volume]')?window.ekVolumeNumber($(selector)):Number.parseFloat($(selector)?.value))||0);
const money=(amount,currency)=>amount.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' '+currency;
function show(index){
 step=Math.max(0,Math.min(2,index));
 panels.forEach((panel,i)=>panel.hidden=i!==step);
 nav.forEach((button,i)=>{
  button.classList.toggle('active',i===step);button.classList.toggle('done',i<step);
  if(i===step)button.setAttribute('aria-current','step');else button.removeAttribute('aria-current');
 });
 prev.hidden=step===0;next.hidden=step===2;submit.hidden=step!==2;
 next.textContent=step===0?'Taşıma bilgilerine geç':'Fiyat ve içeriğe geç';
 if(step===2)update();
 window.scrollTo({top:0,behavior:'smooth'});
}
function validate(index){
 const fields=Array.from(panels[index].querySelectorAll('input,select,textarea'));
 for(const el of fields){
  if(el.matches(':disabled')||el.type==='hidden'||!el.name||el.matches('textarea[data-ek-rich]'))continue;
  if(!el.checkValidity()){
   show(index);el.closest('details')?.setAttribute('open','');let hint=el.parentElement.querySelector('.mq-field-error');if(!hint){hint=document.createElement('small');hint.className='mq-field-error';el.parentElement.append(hint);}hint.textContent=el.validity.valueMissing?'Bu alanı doldurun.':(el.validationMessage.startsWith('İndirim')?el.validationMessage:'Geçerli bir değer girin.');el.setAttribute('aria-invalid','true');el.focus();return false;
  }
 }
 return true;
}
function update(){
 const currency=$('#mq-currency')?.value||'EUR';
 const subtotal=num('#mq-volume')*num('#mq-unit')+(Number(form.dataset.legacyExtra)||0);
 const total=Math.max(0,subtotal-num('#mq-discount'));
 const discount=$('#mq-discount');discount.setCustomValidity(num('#mq-discount')>subtotal?'İndirim hizmet bedelinden büyük olamaz.':'');
 $('#mq-p-total').textContent=money(total,currency);
 $('#mq-big-total').textContent=money(total,currency);
 if($('#mq-p-customer')){
  $('#mq-p-volume').textContent=num('#mq-volume').toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' m³';
  const customer=$('#mq-customer');$('#mq-p-customer').textContent=customer?.value?customer.selectedOptions[0].textContent:'Henüz seçilmedi';
  const origin=[form.elements.origin_city.value,form.elements.origin_country.value].filter(Boolean).join(', ')||form.elements.origin.value||'Çıkış';
  const destination=[form.elements.destination_city.value,form.elements.destination_country.value].filter(Boolean).join(', ')||form.elements.destination.value||'Varış';
  $('#mq-p-route').textContent=origin+' → '+destination;
 }
 $('#mq-p-date').textContent=form.elements.move_date.value||'Belirtilmedi';
 const transport=$('#mq-transport-type');const selected=transport?.selectedOptions[0];
 $('#mq-p-transport').textContent=transport?.value?selected.textContent:'Henüz seçilmedi';
}
function snapshot(){
 const data={};
 for(const field of form.elements){
  if(!field.name||protectedFields.includes(field.name)||field.type==='submit'||field.type==='button')continue;
  if(field.type==='checkbox'){
   if(field.name==='services[]'){if(!Array.isArray(data[field.name]))data[field.name]=[];if(field.checked)data[field.name].push(field.value);}
   else data[field.name]=field.checked;
  }else if(field.type==='radio'){if(field.checked)data[field.name]=(field.matches('[data-ek-volume]')?window.ekVolumeRaw(field):field.value);}
  else data[field.name]=(field.matches('[data-ek-volume]')?window.ekVolumeRaw(field):field.value);
 }
 return data;
}
function saveDraft(){
 if(window.self!==window.top||submitting||form.dataset.protected==='1')return;
 try{sessionStorage.setItem(storageKey,JSON.stringify({saved:Date.now(),version:form.dataset.recordVersion,scope:context,data:snapshot()}));status.textContent='Taslak bu tarayıcıda saklandı.';}
 catch(e){status.textContent='Taslak tarayıcıya kaydedilemedi.';}
}
async function restoreDraft(){
 if(window.self!==window.top||form.dataset.protected==='1')return;
 try{
  const draft=JSON.parse(sessionStorage.getItem(storageKey)||'null');
  if(draft?.scope!==context||(id!=='new'&&draft?.version!==form.dataset.recordVersion)||form.dataset.posted==='1'||!draft?.saved||!draft?.data||Date.now()-draft.saved>86400000)return;
  if(!await window.ekConfirm('Kaydedilmemiş teklif taslağı bulundu. Geri yüklemek ister misiniz?'))return;
  for(const field of form.elements){
   if(!field.name||protectedFields.includes(field.name)||!Object.prototype.hasOwnProperty.call(draft.data,field.name))continue;
   const value=draft.data[field.name];
   if(field.type==='checkbox')field.checked=field.name==='services[]'?Array.isArray(value)&&value.includes(field.value):Boolean(value);
   else if(field.type==='radio')field.checked=field.value===value;
   else if(field.tagName==='SELECT'&&![...field.options].some(o=>o.value===String(value)))continue;
   else if(field.ekRichSet)field.ekRichSet(value);
   else {field.value=value;if(field.matches('[data-ek-volume]'))window.ekVolumeInit?.(form);}
  }
  status.textContent='Kaydedilmemiş taslak geri yüklendi.';
 }catch(e){status.textContent='Eski taslak okunamadı.';}
}
next.addEventListener('click',()=>{if(validate(step))show(step+1);});
prev.addEventListener('click',()=>show(step-1));
nav.forEach((button,index)=>button.addEventListener('click',()=>{
 if(index<=step){show(index);return;}
 for(let i=0;i<index;i++){if(!validate(i))return;}show(index);
}));
form.addEventListener('input',event=>{
 event.target.removeAttribute('aria-invalid');const hint=event.target.parentElement.querySelector('.mq-field-error');if(hint)hint.remove();
 update();clearTimeout(saveTimer);saveTimer=setTimeout(saveDraft,900);
});
form.addEventListener('change',update);
form.addEventListener('submit',event=>{
 if(document.getElementById('content-template')?.disabled||document.getElementById('mq-create-customer')?.disabled){event.preventDefault();event.stopImmediatePropagation();status.textContent='Devam eden işlemin tamamlanmasını bekleyin.';return;}
 if(submitting||form.dataset.protected==='1'){event.preventDefault();event.stopImmediatePropagation();return;}
 for(let i=0;i<panels.length;i++){
  if(!validate(i)){event.preventDefault();event.stopImmediatePropagation();return;}
 }
 // Rich text editor's submit handler runs after this and encodes the HTML.
 submitting=true;clearTimeout(saveTimer);
 submit.disabled=true;submit.textContent='Kaydediliyor…';
 status.textContent='Teklif sunucuya gönderiliyor…';
 // Do not delete the draft before the server confirms a successful save.
},false);
const add=$('#mq-add-customer'),customerBox=$('#mq-customerbox');
const toggleCustomer=()=>{customerBox.hidden=!customerBox.hidden;add.setAttribute('aria-expanded',String(!customerBox.hidden));};
if(add&&customerBox)add.addEventListener('click',toggleCustomer);
$('#mq-cancel-customer')?.addEventListener('click',()=>{customerBox.hidden=true;add.setAttribute('aria-expanded','false');});
const create=$('#mq-create-customer');
let customerAttempt=null;
if(create)create.addEventListener('click',async()=>{
 const message=$('#mq-customer-msg'),name=$('#mq-new-name');
 if(!$('#mq-new-email').checkValidity()){message.textContent='Geçerli bir e-posta girin.';$('#mq-new-email').focus();return;}
 if(!name.value.trim()){message.textContent='Ad soyad zorunludur.';name.focus();return;}
 create.disabled=true;message.textContent='Kaydediliyor…';
 const data=new FormData();data.set('csrf',form.elements.csrf.value);data.set('action','create_customer');
 for(const key of ['name','phone','email','type','company'])data.set(key,$('#mq-new-'+key).value);
 const fingerprint=JSON.stringify(Array.from(data.entries()));
 if(customerAttempt?.fingerprint!==fingerprint){
  const key=Array.from(crypto.getRandomValues(new Uint8Array(16)),byte=>byte.toString(16).padStart(2,'0')).join('');
  customerAttempt={fingerprint,key};
 }
 data.set('request_key',customerAttempt.key);
 try{
  const response=await fetch('moving-customer-api.php',{method:'POST',body:data,credentials:'same-origin'});
  if(response.status===401){saveDraft();const route=location.pathname.split('/').pop()==='quote.php'?'quote.php'+location.search:'quote.php';
   location.assign('login.php?return='+encodeURIComponent(route));return;}
  const result=await response.json();
  if(!response.ok||!result.ok)throw new Error(result.error||'Müşteri kaydedilemedi.');
  customerAttempt=null;
  $('#mq-customer').add(new Option(result.label,result.id,true,true));
  customerBox.hidden=true;message.textContent='Müşteri kaydedildi.';update();saveDraft();
 }catch(error){message.textContent=error.message||'Müşteri kaydedilemedi.';}
 finally{create.disabled=false;}
});
document.addEventListener('ek-rich-ready',async()=>{await restoreDraft();update();},{once:true});
const search=$('#mq-customer-search');
search?.addEventListener('input',()=>{const term=search.value.toLocaleLowerCase('tr');for(const option of $('#mq-customer').options)option.hidden=!!option.value&&!option.selected&&!option.textContent.toLocaleLowerCase('tr').includes(term);});
const preview=$('.mq-preview');if(preview&&matchMedia('(max-width:1100px)').matches)preview.open=false;
const deepLink=location.hash==='#content-service_scope'?$('#content-service_scope'):null;
show(deepLink?panels.indexOf(deepLink.closest('.mq-panel')):0);
if(deepLink){
 const details=deepLink.closest('details');
 if(details){details.open=true;details.querySelector('summary')?.focus();}
}
update();
const serverError=form.querySelector('.mq-field-error');if(serverError){const panel=serverError.closest('.mq-panel');show(panels.indexOf(panel));serverError.closest('details')?.setAttribute('open','');}
})();
