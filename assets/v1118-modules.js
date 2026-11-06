(()=>{'use strict';
const page=(location.pathname.split('/').pop()||'index.php').toLowerCase();
const config={
'quotes.php':['Teklif Yönetimi','Tüm teklifleri inceleyin, durumlarını takip edin ve güvenli silme işlemlerini yönetin.','quotes',[['+ Yeni teklif','quote.php','primary'],['Silinen teklifler','quote-archive.php','']]],
'quote.php':['Teklif Oluşturma','Müşteri, taşıma güzergâhı, eşya hacmi ve hazır içerik şablonunu tek akışta yönetin.','quote',[['Teklif listesi','quotes.php',''],['Şablonlar','templates.php','']]],
'customers.php':['Müşteri Yönetimi','Müşteri bilgileri, iletişim kayıtları ve teklif geçmişi için merkezi çalışma alanı.','customers',[['+ Yeni teklif','quote.php','primary']]],
'customer-detail.php':['Müşteri Profili','Müşterinin kayıtlarını ve ilişkili tekliflerini inceleyin.','customers',[['Müşterilere dön','customers.php','']]],
'v11-inventory.php':['Eşya Envanteri','Oda bazlı eşya listelerini ve toplam hacmi tek ekrandan takip edin.','inventory',[['Yeni teklif','quote.php','primary']]],
'v11-calendar.php':['Taşınma Takvimi','Planlanan taşınmaları, tarihleri ve operasyon durumlarını takip edin.','calendar',[['Teklifler','quotes.php','']]],
'v11-documents.php':['Sözleşme Yönetimi','Tekliflerle ilişkili sözleşmeleri görüntüleyin ve belge işlemlerini yönetin.','documents',[['Teklifler','quotes.php','']]],
'settings-hub.php':['Sistem ve Ayarlar','Teklif şablonları, e-posta, kullanıcı tercihleri ve sistem araçları.','settings',[['Teklif şablonları','templates.php','primary']]],
'templates.php':['Teklif İçerik Şablonları','Karayolu ve denizyolu tekliflerinde kullanılacak standart hizmet açıklamalarını yönetin.','templates',[['Yeni teklif','quote.php','']]],
'v119-template-edit.php':['Şablon Düzenleme','Hazır teklif açıklamalarını düzenleyin; geçmiş teklifler değişmeden kalır.','templates',[['Şablonlara dön','templates.php','']]],
'view.php':['Teklif Detayı','Teklif içeriği, gönderim ve ilgili işlemler.','view',[['Tekliflere dön','quotes.php','']]],
'quote-details.php':['Teklif İçeriği','Teklifin hizmet ve ödeme açıklamalarını kontrol edin.','view',[['Tekliflere dön','quotes.php','']]]};
const item=config[page];if(!item)return;
const [title,description,cls,actions]=item;document.body.classList.add('v118-'+cls);
const content=document.querySelector('.v115-content')||document.querySelector('section.content');if(!content)return;
const bar=document.createElement('div');bar.className='v118-context';bar.setAttribute('aria-label','Sayfa işlemleri');
const left=document.createElement('div');left.className='v118-context-copy';
const label=document.createElement('div');label.className='v118-context-label';label.textContent='EKŞİOĞLU · INTERNATIONAL MOVERS';
const h=document.createElement('h1');h.textContent=title;const p=document.createElement('p');p.textContent=description;
left.append(label,h,p);bar.append(left);
const right=document.createElement('div');right.className='v118-context-actions';
for(const [name,href,kind] of actions){const a=document.createElement('a');a.className='v118-action'+(kind?' '+kind:'');a.textContent=name;a.href=href;right.append(a)}
bar.append(right);const utility=content.querySelector('.v117-utility');if(utility)utility.insertAdjacentElement('afterend',bar);else content.prepend(bar);
})();
