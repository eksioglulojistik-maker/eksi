<?php
function top($title){
$page=basename($_SERVER['SCRIPT_NAME']??'');
$menu=[['workdesk.php','◫','Çalışma Masası'],['quotes.php','▤','Satış ve Teklifler'],['customers.php','♙','Müşteriler'],['ekspertiz.php','▦','Ekspertizler'],['operations-hub.php','▣','Operasyonlar'],['v11-documents.php','✎','Belgeler'],['finance-hub.php','◈','Finans'],['settings-hub.php','⚙','Ayarlar']];
$activeMap=['notification-setup.php'=>'notifications.php','quote.php'=>'quotes.php','view.php'=>'quotes.php','quote-details.php'=>'quotes.php','quote-actions.php'=>'quotes.php','v11-tracking.php'=>'quotes.php','v11-revision.php'=>'quotes.php','v11-print.php'=>'quotes.php','v112-mail.php'=>'quotes.php','customer-detail.php'=>'customers.php','quote-payments.php'=>'payments.php','payment-receipt.php'=>'payments.php','templates.php'=>'settings-hub.php','v119-template-edit.php'=>'settings-hub.php','template-studio.php'=>'settings-hub.php','transport.php'=>'settings-hub.php','emails.php'=>'settings-hub.php','settings.php'=>'settings-hub.php','update-manager.php'=>'settings-hub.php','release-status.php'=>'settings-hub.php','panel-durum.php'=>'settings-hub.php','v11-backup.php'=>'settings-hub.php','v112-mail-setup.php'=>'settings-hub.php'];
$activeMap=array_merge($activeMap,['index.php'=>'workdesk.php','workdesk.php'=>'workdesk.php','v11-inventory.php'=>'operations-hub.php','inventory.php'=>'quotes.php','v11-calendar.php'=>'operations-hub.php','v11-documents.php'=>'v11-documents.php','contract-sign.php'=>'v11-documents.php','v11-center.php'=>'operations-hub.php','payments.php'=>'finance-hub.php','receivables.php'=>'finance-hub.php','quote-payments.php'=>'finance-hub.php','payment-receipt.php'=>'finance-hub.php','notifications.php'=>'workdesk.php']);$active=$activeMap[$page]??$page;
if(function_exists('ekAdmin') && !ekAdmin()){$menu=array_values(array_filter($menu,fn($m)=>$m[0]!=='receivables.php'));}
$quick=['quotes.php'=>[['quote.php','+ Yeni teklif'],['templates.php','Şablonlar']],'customers.php'=>[['customers.php?new=1','+ Yeni müşteri']],'operations-hub.php'=>[['v11-calendar.php','Takvim']],'finance-hub.php'=>[['payments.php','Tahsilatlar'],['receivables.php','Cari Alacaklar']],'settings-hub.php'=>[['templates.php','Şablonlar']],'v11-documents.php'=>[['v11-documents.php','Sözleşmeleri Yönet']],'v11-calendar.php'=>[['v11-calendar.php','Takvimi Aç']]];
$ekUnread=0;
try{$ekN=db()->prepare('SELECT COUNT(*) FROM ek_notifications WHERE recipient_id=? AND read_at IS NULL AND deleted_at IS NULL');$ekN->execute([(int)($_SESSION['uid']??0)]);$ekUnread=(int)$ekN->fetchColumn();}catch(Throwable $ignored){}
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b1d35"><title><?=h($title)?> · EKŞİOĞLU</title><style>.ek-bell{position:relative;display:inline-flex;align-items:center;justify-content:center;width:39px;height:39px;border-radius:12px;background:#edf3fb;text-decoration:none;font-size:19px}.ek-bell span,.ek-notif-count{background:#d22d49;color:#fff;border-radius:999px;padding:2px 6px;font-size:11px;font-weight:700}.ek-bell span{position:absolute;top:-5px;right:-5px}.ek-notif-count{margin-left:auto}</style><link rel="stylesheet" href="assets/ek-unified.css?v=363701"><link rel="stylesheet" href="assets/panel-dialog.css?v=363101"><script src="assets/panel-dialog.js?v=363101"></script><script src="assets/volume-format.js?v=363301"></script></head>
<body class="ex-app ek-design-v2"><a class="ek-skip-link" href="#ek-main-content">İçeriğe geç</a><aside class="ex-sidebar" id="ex-sidebar"><a href="workdesk.php" class="ex-brand"><img src="assets/eksioglu-logo.png" alt="EKŞİOĞLU LOJİSTİK"></a><button type="button" class="ex-sidebar-close" id="ex-menu-close" aria-label="Menüyü kapat">Menüyü kapat ×</button><div class="ex-menu-title">YÖNETİM PLATFORMU <span class="ek-version">2026</span></div><nav class="ex-nav" aria-label="Ana menü"><?php foreach($menu as $m):?><a class="<?= $active===$m[0]?'active':'' ?>" <?= $active===$m[0]?'aria-current="page"':'' ?> href="<?=h($m[0])?>"><span class="ex-nav-icon" aria-hidden="true"><?=h($m[1])?></span><span><?=h($m[2])?></span><?php if($m[0]==='notifications.php' && $ekUnread):?><span class="ek-notif-count"><?=min($ekUnread,99)?></span><?php endif;?><?php if($active===$m[0]):?><i aria-hidden="true"></i><?php endif;?></a><?php endforeach;?></nav><div class="ex-sidebar-bottom"><span class="ex-online"><i></i> Güvenli yönetim alanı</span><div class="ex-account"><span class="ex-avatar"><?=h(mb_substr($_SESSION['name']??'Y',0,1,'UTF-8'))?></span><span><b><?=h($_SESSION['name']??'Yetkili')?></b><small>Yönetim hesabı</small></span><a href="account.php" title="Hesap ayarları" aria-label="Hesap ayarları" style="margin-right:6px">⚙</a><a href="logout.php" title="Çıkış yap" aria-label="Çıkış yap">↗</a></div></div></aside>
<div class="ex-backdrop" id="ex-backdrop" hidden></div><div id="ek-command-backdrop" class="ek-command-backdrop" hidden><section class="ek-command-dialog" role="dialog" aria-modal="true" aria-label="Hızlı erişim"><label for="ek-command-input">Sayfalara hızlı erişim</label><input id="ek-command-input" type="search" placeholder="Teklifler, müşteriler, sözleşmeler..." autocomplete="off"><div id="ek-command-results" class="ek-command-results"></div><small>Yön tuşlarıyla seçin · Enter ile açın · Esc ile kapatın</small></section></div><div class="ex-shell"><header class="ex-header"><div class="ex-header-left"><button class="ex-hamburger" type="button" id="ex-menu-button" aria-label="Menüyü aç" aria-expanded="false" aria-controls="ex-sidebar">☰</button><div class="ex-headings"><span>EKŞİOĞLU <b>/</b> <?=h($title)?></span></div></div><div class="ex-header-right"><button type="button" class="ek-command-open" id="ek-command-open" aria-label="Hızlı erişim">⌕ <span>Hızlı erişim</span><kbd>Ctrl K</kbd></button><a class="ek-bell" href="notifications.php" aria-label="Bildirimler">🔔<?php if($ekUnread):?><span><?=min($ekUnread,99)?></span><?php endif;?></a><span class="ex-header-date"><?=h(date('d.m.Y'))?></span><?php if($page!=='quotes.php'):?><a class="ex-top-cta" href="quote.php">＋ Yeni Teklif</a><?php endif;?></div></header><main id="ek-main-content" tabindex="-1" class="ex-content" data-page="<?=h($page)?>"><?php if($page==='update-manager.php'):?><h1><?=h($title)?></h1><?php endif;?><?php }
function bottom(){?></main></div><script src="assets/v1119-platform.js?v=331301" defer></script><script src="assets/v120-suite.js?v=363101" defer></script><script>
(function(){
 const bell=document.querySelector('.ek-bell');
 if(!bell||!window.fetch)return;
 async function update(){
  if(document.visibilityState==='hidden')return;
  try{
   const r=await fetch('notification-count.php',{credentials:'same-origin',cache:'no-store'});
   if(!r.ok)return;
   const d=await r.json();
   if(typeof d.unread!=='number')return;
   let badge=bell.querySelector('span');
   if(d.unread>0){
    if(!badge){badge=document.createElement('span');bell.appendChild(badge);}
    badge.textContent=String(Math.min(d.unread,99));
   }else if(badge){badge.remove();}
   const menu=document.querySelector('.ex-nav a[href="notifications.php"]');
   if(menu){
    let count=menu.querySelector('.ek-notif-count');
    if(d.unread>0){if(!count){count=document.createElement('span');count.className='ek-notif-count';menu.appendChild(count);}count.textContent=String(Math.min(d.unread,99));}
    else if(count){count.remove();}
   }
  }catch(e){}
 }
 setInterval(update,45000);
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')update();});
})();
</script><script src="assets/enterprise-v3.js?v=331001" defer></script><script src="assets/enterprise-v31.js?v=331001" defer></script><script src="assets/ek-next.js?v=331001" defer></script></body></html><?php }
