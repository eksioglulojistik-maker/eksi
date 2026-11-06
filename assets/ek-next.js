(()=>{'use strict';if(!document.body.classList.contains('ex-app'))return;
const main=document.querySelector('main.ex-content');if(!main)return;
// Keep existing business forms, handlers, permissions and server endpoints unchanged.
const page=(main.dataset.page||'').toLowerCase();
// Responsive table overflow without modifying markup or breaking event delegation.
main.querySelectorAll('table').forEach(table=>{if(table.closest('.ek-table-scroll,.ex-table-scroll,.table-responsive,.table-wrap,.ek-scroll,.ek-responsive-table,.crm-table-wrap,.eq-table-wrap,.v11-table,.ex-table-wrap'))return;const parent=table.parentElement;if(!parent)return;const wrap=document.createElement('div');wrap.className='ek-table-scroll';wrap.tabIndex=0;wrap.setAttribute('role','region');wrap.setAttribute('aria-label','Tablo; yatay kaydırılabilir');parent.insertBefore(wrap,table);wrap.append(table);});
const back=document.createElement('button');back.type='button';back.className='ek-back-top';back.setAttribute('aria-label','Sayfanın başına dön');back.textContent='↑';document.body.append(back);back.addEventListener('click',()=>window.scrollTo({top:0,behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'}));
const show=()=>back.classList.toggle('ek-show',window.scrollY>500);window.addEventListener('scroll',show,{passive:true});show();
// Destructive actions require explicit user confirmation only when existing page does not already handle it.
})();
