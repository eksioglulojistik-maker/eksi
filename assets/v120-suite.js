(()=>{'use strict';
const main=document.querySelector('.ex-content');if(!main)return;
const page=main.dataset.page||'';
const modules={
 'index.php':['Kontrol merkezi',[]],
 'quotes.php':['Teklif yönetimi',[['quote.php','+ Yeni teklif'],['templates.php','İçerik şablonları']]],
 'quote.php':['Teklif çalışma alanı',[['quotes.php','Teklif listesi'],['templates.php','Şablonları yönet']]],
 'customers.php':['Müşteri ilişkileri',[['customers.php?new=1','+ Yeni müşteri'],['quotes.php','Müşteri teklifleri']]],
 'customer-detail.php':['Müşteri profili',[['customers.php','Müşterilere dön'],['quote.php','+ Teklif oluştur']]],
 'v11-inventory.php':['Eşya envanteri',[['quotes.php','Teklifler'],['quote.php','+ Yeni teklif']]],
 'v11-calendar.php':['Taşınma operasyonları',[['quotes.php','Teklifler'],['v11-inventory.php','Envanter']]],
 'v11-documents.php':['Sözleşme ve belgeler',[['quotes.php','Teklifler'],['v11-calendar.php','Taşınma takvimi']]],
 'templates.php':['Teklif içerik şablonları',[['quote.php','+ Yeni teklif'],['settings-hub.php','Ayarlara dön']]],
 'v119-template-edit.php':['Şablon düzenleyici',[['templates.php','Şablon listesi']]],
 'settings-hub.php':['Sistem yönetimi',[['templates.php','Şablonlar'],['update-manager.php','Güncellemeler']]]
};
const cfg=modules[page];
// Forms with unsaved changes: warn only on actual navigation, not on submit.
const forms=[...main.querySelectorAll('form')].filter(f=>f.querySelector('input:not([type=hidden]),select,textarea')&&!f.matches('[method=get]'));
const dirty=new WeakSet();forms.forEach(f=>{f.addEventListener('input',()=>dirty.add(f));f.addEventListener('change',()=>dirty.add(f));f.addEventListener('submit',()=>dirty.delete(f));});
const anyDirty=()=>forms.some(f=>dirty.has(f));
window.addEventListener('beforeunload',e=>{if(anyDirty()){e.preventDefault();e.returnValue='';}});
document.addEventListener('ek-confirmed-navigation',()=>forms.forEach(f=>dirty.delete(f)));
const back=document.getElementById('ex-go-back');if(back){back.addEventListener('click',async e=>{if(!anyDirty())return;e.stopImmediatePropagation();e.preventDefault();if(await window.ekConfirm('Kaydedilmemiş değişiklikler var. Sayfadan ayrılmak istiyor musunuz?')){document.dispatchEvent(new Event('ek-confirmed-navigation'));location.assign(back.href);}},true);}
})();