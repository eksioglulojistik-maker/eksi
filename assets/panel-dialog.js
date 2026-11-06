(() => {
 'use strict';
 let dialog,resolveCurrent=null,previousFocus=null,inertElements=[],scrollStyle='';
 const messages=new WeakMap(),allowed=new WeakSet(),pending=new WeakSet();
 const infer=message=>{
  const text=message.toLocaleLowerCase('tr');
  if(/sil|kaldır|kalıcı/.test(text))return {title:'İşlemi onaylayın',confirmText:/kaldır/.test(text)?'Kaldır':'Sil',tone:'danger'};
  if(/arşiv/.test(text))return {title:'Arşiv işlemini onaylayın',confirmText:'Devam et',tone:'normal'};
  if(/ayrıl|çıkılsın/.test(text))return {title:'Kaydedilmemiş değişiklikler',confirmText:'Sayfadan ayrıl',tone:'normal'};
  if(/gönder/.test(text))return {title:'Gönderimi onaylayın',confirmText:'Gönder',tone:'normal'};
  if(/geri yük/.test(text))return {title:'Geri yüklemeyi onaylayın',confirmText:'Geri yükle',tone:'normal'};
  return {title:'İşlemi onaylayın',confirmText:'Devam et',tone:'normal'};
 };
 const node=(tag,className,text)=>{const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=text;return el;};
 const finish=value=>{
  if(!resolveCurrent)return;const resolve=resolveCurrent;resolveCurrent=null;
  if(dialog.open&&typeof dialog.close==='function')dialog.close();else dialog.removeAttribute('open');
  document.getElementById('ek-dialog-overlay')?.remove();
  inertElements.forEach(([el,value])=>{el.inert=value;});inertElements=[];document.documentElement.style.overflow=scrollStyle;
  if(previousFocus?.isConnected)previousFocus.focus({preventScroll:true});resolve(Boolean(value));
 };
 const create=()=>{
  if(dialog)return;
  dialog=node('dialog','ek-panel-dialog');dialog.id='ek-panel-dialog';dialog.setAttribute('role','alertdialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-labelledby','ek-dialog-title');dialog.setAttribute('aria-describedby','ek-dialog-message');
  const header=node('div','ek-dialog-header'),brand=node('div','ek-dialog-brand','EKŞİOĞLU LOJİSTİK');
  const close=node('button','ek-dialog-close','×');close.type='button';close.setAttribute('aria-label','Pencereyi kapat');close.addEventListener('click',()=>finish(false));header.append(brand,close);
  const content=node('div','ek-dialog-content'),icon=node('span','ek-dialog-icon','!');icon.setAttribute('aria-hidden','true');
  const copy=node('div','ek-dialog-copy'),title=node('h2','','');title.id='ek-dialog-title';const description=node('p','','');description.id='ek-dialog-message';copy.append(title,description);content.append(icon,copy);
  const footer=node('div','ek-dialog-footer'),cancel=node('button','ek-dialog-cancel','Vazgeç'),confirm=node('button','ek-dialog-confirm','Devam et');cancel.type=confirm.type='button';cancel.id='ek-dialog-cancel';confirm.id='ek-dialog-confirm';cancel.addEventListener('click',()=>finish(false));confirm.addEventListener('click',()=>finish(true));footer.append(cancel,confirm);dialog.append(header,content,footer);document.body.append(dialog);
  dialog.addEventListener('cancel',event=>{event.preventDefault();finish(false);});
  dialog.addEventListener('keydown',event=>{
   event.stopPropagation();
   if(event.key==='Escape'){event.preventDefault();finish(false);return;}
   if(event.key!=='Tab')return;const controls=[...dialog.querySelectorAll('button:not([disabled])')].filter(el=>!el.hidden);const first=controls[0],last=controls[controls.length-1];if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
  dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)finish(false);}});
 };
 window.ekConfirm=(message,options={})=>{
  // A second trigger while a decision is pending must never queue another destructive action.
  if(resolveCurrent)return Promise.resolve(false);
  create();const config={...infer(String(message)),...options};
  const isAlert=config.mode==='alert';dialog.querySelector('#ek-dialog-cancel').hidden=isAlert;dialog.querySelector('.ek-dialog-icon').textContent=isAlert?'i':'!';
  previousFocus=document.activeElement;dialog.dataset.tone=config.tone==='danger'?'danger':'normal';dialog.querySelector('#ek-dialog-title').textContent=config.title;dialog.querySelector('#ek-dialog-message').textContent=String(message);dialog.querySelector('#ek-dialog-confirm').textContent=config.confirmText;
  return new Promise(resolve=>{
   resolveCurrent=resolve;scrollStyle=document.documentElement.style.overflow;document.documentElement.style.overflow='hidden';
   inertElements=[...document.body.children].filter(el=>el!==dialog).map(el=>[el,el.inert]);inertElements.forEach(([el])=>{el.inert=true;});
   try{if(typeof dialog.showModal==='function')dialog.showModal();else{const overlay=node('div','ek-dialog-overlay');overlay.id='ek-dialog-overlay';overlay.addEventListener('click',()=>finish(false));document.body.append(overlay);dialog.setAttribute('open','');}}catch{finish(false);return;}
   dialog.querySelector(isAlert?'#ek-dialog-confirm':'#ek-dialog-cancel').focus({preventScroll:true});
  });
 };
 window.ekAlert=(message,options={})=>window.ekConfirm(message,{title:'Bilgilendirme',confirmText:'Tamam',...options,mode:'alert'});
 // Safely read only legacy handlers consisting of a single literal confirm call. No eval.
 const literal=handler=>{
  const match=/^\s*return\s+confirm\s*\(\s*([\s\S]+?)\s*\)\s*;?\s*$/.exec(handler||'');if(!match)return null;
  const value=match[1];if(value[0]==='"'){try{const parsed=JSON.parse(value);return typeof parsed==='string'?parsed:null;}catch{return null;}}
  if(value[0]!=="'"||value[value.length-1]!=="'")return null;
  const inner=value.slice(1,-1);let result='';
  for(let i=0;i<inner.length;i++){
   const c=inner[i];if(c==="'"||c==='\n'||c==='\r')return null;
   if(c!=='\\'){result+=c;continue;}const next=inner[++i];const escapes={"'":"'",'"':'"','\\':'\\',n:'\n',r:'\r',t:'\t',b:'\b',f:'\f',v:'\v',0:'\0'};
   if(Object.prototype.hasOwnProperty.call(escapes,next))result+=escapes[next];
   else if(next==='u'||next==='x'){const size=next==='u'?4:2,code=inner.slice(i+1,i+1+size);if(!new RegExp('^[a-fA-F0-9]{'+size+'}$').test(code))return null;result+=String.fromCharCode(parseInt(code,16));i+=size;}
   else return null;
  }
  return result;
 };
 const configFor=(el,eventName)=>{
  const explicit=el.getAttribute('data-ek-confirm');if(explicit!==null)return {message:explicit,title:el.dataset.ekConfirmTitle,confirmText:el.dataset.ekConfirmButton,tone:el.dataset.ekConfirmTone};
  const map=messages.get(el);if(map?.[eventName]!==undefined)return {message:map[eventName]};
  const attr=eventName==='submit'?'onsubmit':'onclick',message=literal(el.getAttribute(attr));if(message===null)return null;
  messages.set(el,{...map,[eventName]:message});el.removeAttribute(attr);if(eventName==='click')el.setAttribute('data-ek-confirm-adapted-click','');return {message};
 };
 const ask=cfg=>{const options={};for(const key of ['title','confirmText','tone'])if(cfg[key])options[key]=cfg[key];return window.ekConfirm(cfg.message,options);};
 document.addEventListener('submit',event=>{
  const form=event.target;if(!(form instanceof HTMLFormElement)||allowed.has(form))return;
  const config=configFor(form,'submit');if(!config)return;
  event.preventDefault();event.stopImmediatePropagation();if(pending.has(form))return;pending.add(form);const submitter=event.submitter;
  ask(config).then(confirmed=>{if(!confirmed||!form.isConnected||(submitter&&(!submitter.isConnected||submitter.form!==form)))return;allowed.add(form);try{form.requestSubmit(submitter?.isConnected?submitter:undefined);}finally{allowed.delete(form);}}).finally(()=>pending.delete(form));
 },true);
 document.addEventListener('click',event=>{
  const target=event.target.closest?.('[onclick],[data-ek-confirm-adapted-click],a[data-ek-confirm],button[data-ek-confirm],input[data-ek-confirm]');if(!target||allowed.has(target)||target.disabled)return;
  const config=configFor(target,'click');if(!config)return;
  event.preventDefault();event.stopImmediatePropagation();if(pending.has(target))return;
  if(target.form&&target.type==='submit'&&!target.form.noValidate&&!target.form.reportValidity())return;
  pending.add(target);ask(config).then(confirmed=>{if(!confirmed||!target.isConnected)return;allowed.add(target);try{target.click();}finally{allowed.delete(target);}}).finally(()=>pending.delete(target));
 },true);
})();
