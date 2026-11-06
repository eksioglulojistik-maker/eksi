(() => {
 'use strict';
 const states=new WeakMap(),initialized=new WeakSet();
 const format=value=>Number(value).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
 const parse=value=>{const s=String(value).trim();return /^(?:\d+(?:[.,]\d*)?|[.,]\d+)$/.test(s)?Number(s.replace(',','.')):NaN;};
 const read=el=>{
  const state=states.get(el);
  if(state&&el.value===state.display)return state.raw;
  return el.value.trim()===''?'':String(parse(el.value));
 };
 const validate=el=>{const raw=read(el),n=Number(raw),min=el.getAttribute('min'),max=el.getAttribute('max');el.setCustomValidity(raw!==''&&(!Number.isFinite(n)||(min!==null&&n<Number(min))||(max!==null&&n>Number(max)))?'Geçerli bir hacim girin. Örnek: 12,50':'');};
 const render=el=>{const raw=read(el),n=Number(raw);if(raw===''||!Number.isFinite(n)){validate(el);return;}const display=format(n);states.set(el,{raw,display});el.value=display;validate(el);};
 window.ekVolumeRaw=read;
 window.ekVolumeNumber=el=>Number(read(el))||0;
 window.ekVolumeInit=(root=document)=>{
  root.querySelectorAll('input[data-ek-volume]').forEach(el=>{
   if(!initialized.has(el)){
    initialized.add(el);
    // Text presentation accepts the decimal comma. Original numeric precision is
    // preserved separately and submitted canonically unless the user edits it.
    const raw=el.value;el.type='text';el.inputMode='decimal';
    states.set(el,{raw,display:el.value});
    el.addEventListener('input',()=>{states.delete(el);validate(el);});
    el.addEventListener('blur',()=>render(el));
   }
   render(el);
  });
 };
 document.addEventListener('DOMContentLoaded',()=>window.ekVolumeInit());
 document.addEventListener('formdata',event=>{
  event.target.querySelectorAll('input[data-ek-volume][name]').forEach(el=>{if(!el.disabled)event.formData.set(el.name,read(el));});
 });
 document.addEventListener('submit',event=>{
  let invalid=null;event.target.querySelectorAll('input[data-ek-volume]').forEach(el=>{validate(el);if(!el.disabled&&!el.checkValidity())invalid??=el;});
  if(invalid){event.preventDefault();event.stopImmediatePropagation();invalid.reportValidity();}
 },true);
})();
