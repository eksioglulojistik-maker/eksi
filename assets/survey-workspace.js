(() => {
 'use strict';
 const tabs=[...document.querySelectorAll('[data-sv-tab]')];
 const nav=document.querySelector('.sv-workflow');
 const activate=tab=>{tabs.forEach(item=>{const selected=item===tab;item.setAttribute('aria-selected',String(selected));item.tabIndex=selected?0:-1;const panel=document.getElementById(item.dataset.svTab);if(panel){panel.hidden=!selected;panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',item.id);}});};
 if(tabs.length&&nav){nav.setAttribute('role','tablist');tabs.forEach((tab,index)=>{tab.id='sv-tab-'+index;tab.setAttribute('role','tab');tab.setAttribute('aria-controls',tab.dataset.svTab);tab.addEventListener('click',event=>{event.preventDefault();activate(tab);history.replaceState(null,'',tab.getAttribute('href'));});tab.addEventListener('keydown',event=>{let i;if(event.key==='ArrowRight')i=(index+1)%tabs.length;if(event.key==='ArrowLeft')i=(index+tabs.length-1)%tabs.length;if(event.key==='Home')i=0;if(event.key==='End')i=tabs.length-1;if(i!==undefined){event.preventDefault();tabs[i].click();tabs[i].focus();}});});const selectHash=()=>activate(tabs.find(tab=>tab.getAttribute('href')===location.hash)||tabs[0]);selectHash();window.addEventListener('hashchange',selectHash);}
 const addForm=document.getElementById('sv-item-name')?.closest('form');
 if(addForm){const fields=['length_cm','width_cm','height_cm'].map(name=>addForm.elements.namedItem(name));const output=document.getElementById('sv-measured-volume');const update=()=>{if(!output)return;const values=fields.map(field=>Number(field?.value));output.textContent=values.every(value=>value>0&&value<=1000)?'Ölçüden birim hacim: '+(values.reduce((a,b)=>a*b,1)/1e6).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' m³':'';};fields.forEach(field=>field?.addEventListener('input',update));}
 // Leave upload/AI requests manual. Never run an external analysis merely by opening a tab.
})();
