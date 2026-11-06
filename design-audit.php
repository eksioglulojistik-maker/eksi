<?php
// Administrator-only responsive inspection. No application records are written.
require __DIR__.'/inc/core.php';
auth();
if (!ekAdmin()) { http_response_code(403); exit('Yönetici yetkisi gerekir.'); }
$routes = ['ui-dialog-preview.php','workdesk.php','quotes.php','customers.php','quote.php','operations-hub.php',
 'settings-hub.php','v11-calendar.php','v11-inventory.php','inventory.php',
 'v11-documents.php','payments.php','receivables.php','templates.php',
 'emails.php','account.php','v11-tracking.php'];
$route = is_string($_GET['route']??null) && in_array($_GET['route'],$routes,true) ? $_GET['route'] : 'quotes.php';
$widths = [320,390,768,1024,1366,1440];
$width = filter_var($_GET['width']??390,FILTER_VALIDATE_INT);
if (!in_array($width,$widths,true)) $width=390;
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EKŞİOĞLU · Tasarım Kontrolü</title><style>
body{margin:0;background:#e2e8f0;color:#142a43;font:14px/1.5 system-ui}header{padding:14px 20px;background:white;border-bottom:1px solid #cbd5e1}h1{font-size:20px;margin:0 0 6px}form{display:flex;align-items:end;gap:12px;flex-wrap:wrap}label{display:flex;flex-direction:column}select,button{padding:8px;color:#142a43;background:white;border:1px solid #94a3b8;border-radius:6px}p{margin:8px 0}.preview{padding:16px;overflow:auto}iframe{display:block;background:#fff;border:1px solid #94a3b8;height:800px;max-width:none}
</style></head><body><header><h1>Tasarım Kontrolü</h1><form method="get"><label>Sayfa<select name="route"><?php foreach($routes as $r):?><option value="<?=h($r)?>" <?=$r===$route?'selected':''?>><?=h($r)?></option><?php endforeach;?></select></label><label>Genişlik<select name="width"><?php foreach($widths as $w):?><option value="<?=$w?>" <?=$w===$width?'selected':''?>><?=$w?> px</option><?php endforeach;?></select></label><button type="submit">Görüntüle</button><a href="settings-hub.php">Ayarlara dön</a></form><p>Yalnız yönetici içindir. Çerçevede form gönderimi kapalıdır. Bu görünüm CSS genişlik testidir; gerçek mobil cihaz ve dokunmatik testi yerine geçmez.</p></header><div class="preview"><iframe id="design-preview" title="<?=h($route)?> · <?=$width?> px" width="<?=$width?>" src="<?=h($route)?>" sandbox="allow-scripts allow-same-origin"></iframe></div></body></html>
