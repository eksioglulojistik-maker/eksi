<?php
require_once __DIR__.'/record-protection.php';
// Included by v11-print.php after quote, contract and customer have been authenticated/loaded.
$signature=null;
try{
 $exists=db()->query("SHOW TABLES LIKE 'ek_contract_signatures'")->fetchColumn();
 if($exists){
  $st=db()->prepare('SELECT signer_name,image_png,signed_at,contract_hash FROM ek_contract_signatures WHERE quote_id=?');$st->execute([$id]);$signature=$st->fetch();
  if($signature&&!hash_equals(hash('sha256',$contract['language']."\n".$contract['body']),$signature['contract_hash']))$signature=null;
 }
}catch(Throwable $e){$signature=null;}
$documentDate=date('d.m.Y');
if($signature){
 try{$frozen=ekSignedDisplay(db(),(int)$id,(string)$signature['contract_hash']);
  if($frozen){$q=array_merge($q,$frozen);$contract['language']=$frozen['language'];$contract['body']=$frozen['body'];$lang=$frozen['language'];$documentDate=date('d.m.Y',strtotime($frozen['document_date']));}
 }catch(Throwable $e){http_response_code(409);exit('İmzalı belge arşiv bütünlüğü doğrulanamadı. Firmayla iletişime geçin.');}
}elseif(!empty($contract['created_at'])){$documentDate=date('d.m.Y',strtotime($contract['created_at']));}
$signImage=$signature?'data:image/png;base64,'.base64_encode($signature['image_png']):null;
?><!doctype html><html lang="<?=h($lang)?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($q['quote_no'])?> - Sözleşme</title>
<style>
:root{--navy:#122e4c;--ink:#222a33;--muted:#566779;--rule:#cbd5df}*{box-sizing:border-box}html,body{margin:0}body{font:10.5pt/1.48 Arial,Helvetica,sans-serif;color:var(--ink);background:#e7ebef}.actions{width:210mm;margin:16px auto;display:flex;align-items:center;justify-content:space-between;font:13px Arial,sans-serif}.actions button{border:0;border-radius:4px;background:var(--navy);color:white;padding:11px 20px;font-weight:bold;cursor:pointer}.paper{background:white;width:210mm;min-height:297mm;margin:0 auto 25px;padding:18mm 19mm 17mm;box-shadow:0 10px 35px #152e4720}.letterhead{display:flex;align-items:center;justify-content:space-between;gap:18px;border-bottom:2px solid var(--navy);padding-bottom:12px;margin-bottom:26px}.letterhead img{display:block;max-width:225px;width:45%;height:58px;object-fit:contain;object-position:left center}.letterhead-side{text-align:right;font-size:9px;color:var(--muted);line-height:1.5}.letterhead-side strong{display:block;font-size:10px;color:var(--navy);letter-spacing:0}.document-title{text-align:center;margin:0 0 23px}.document-title h1{font-size:16pt;line-height:1.25;color:var(--navy);font-weight:700;margin:0 0 6px}.document-title p{font-size:9pt;color:var(--muted);margin:0}.refline{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px 18px;border-top:1px solid var(--rule);border-bottom:1px solid var(--rule);padding:11px 0;margin:0 0 24px;font-size:9pt}.refline strong{color:var(--navy)}.section{margin:0 0 21px}.section h2{font-size:11pt;color:var(--navy);font-weight:700;margin:0 0 10px;border-bottom:1px solid var(--rule);padding-bottom:6px;break-after:avoid}.details{width:100%;border-collapse:collapse;font-size:9.5pt}.details td{padding:5px 7px 5px 0;vertical-align:top;width:50%;overflow-wrap:anywhere}.details .label{display:block;color:var(--muted);font-size:8pt;margin-bottom:3px}.details strong{font-weight:700;color:var(--ink)}.terms{font-size:9.7pt;line-height:1.53;overflow-wrap:anywhere}.terms p{margin:0 0 9px;orphans:3;widows:3}.terms .term-title{font-size:9.8pt;line-height:1.4;font-weight:700;color:var(--navy);margin:15px 0 7px;break-after:avoid}.terms .term-item{padding-left:15px;text-indent:-12px;margin:0 0 5px}.terms .term-item:before{content:'• ';color:var(--navy)}.signature-section{break-inside:avoid;margin-top:28px}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:35px}.sig{min-height:140px;padding-top:5px}.sig-title{font-size:9pt;font-weight:700;color:var(--navy)}.sig-name{font-size:9.5pt;margin-top:7px;display:block}.sig-image{width:100%;max-width:230px;height:70px;object-fit:contain;object-position:left center;display:block;margin-top:9px}.sig-spacer{height:80px}.sig-bottom{border-top:1px solid #9ba8b7;font-size:8pt;color:var(--muted);padding-top:6px;margin-top:5px}.notice{font-size:8pt;color:var(--muted);margin-top:16px}.footer{display:flex;justify-content:space-between;gap:10px;border-top:1px solid var(--navy);margin-top:20px;padding-top:9px;color:var(--muted);font-size:8pt}.footer span:last-child{white-space:nowrap}.section,.details tr{break-inside:avoid}.terms{break-inside:auto}@page{size:A4;margin:0}@media print{html,body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}.actions{display:none}.paper{width:auto;min-height:0;margin:0;padding:17mm 19mm 16mm;box-shadow:none}.letterhead{margin-bottom:22px}.document-title{margin-bottom:18px}.refline{margin-bottom:20px}.footer{break-inside:avoid}}@media screen and (max-width:850px){.actions{width:100%;padding:0 12px}.paper{width:100%;min-height:0;padding:22px 18px}.letterhead img{max-width:200px}.signatures{gap:18px}}

/* V2.1.2 · Premium letterhead refinement. Plain Arial, restrained navy. */
:root{--navy:#142f4d;--navy-deep:#0e263f;--blue:#426582;--ink:#283443;--muted:#657487;--rule:#d9e1e9}
.paper{position:relative;border-top:5px solid var(--navy-deep);padding-top:15mm}
.letterhead{border-bottom:1px solid #b9c9d9;padding-bottom:16px;margin-bottom:30px;position:relative}
.letterhead:after{content:"";position:absolute;left:0;bottom:-2px;width:74px;height:3px;background:var(--navy)}
.letterhead img{max-width:240px;height:65px}
.letterhead-side{font-size:8.3px;line-height:1.7;letter-spacing:.08px}
.letterhead-side strong{font-size:9.5px}
.document-title{text-align:left;margin-bottom:22px}
.doc-eyebrow{font-size:8pt;font-weight:700;color:var(--blue);margin:0 0 10px;letter-spacing:.3px}
.doc-eyebrow span{color:#a5b5c5;padding:0 5px}
.document-title h1{font-size:16pt;line-height:1.34;letter-spacing:-.25px;color:var(--navy-deep);margin-bottom:5px}
.document-title p{font-size:9pt}
.refline{justify-content:flex-start;gap:0;display:grid;grid-template-columns:1.25fr 1fr .75fr;border:1px solid #dce5ee;border-left:3px solid var(--navy);padding:13px 15px;margin-bottom:27px;background:#f8fafc}
.refline span{display:block;padding:0 12px;border-right:1px solid #d9e1e9;min-width:0}
.refline span:first-child{padding-left:0}
.refline span:last-child{border-right:0}
.refline strong{display:block;color:#63758a;font-size:7.6pt;margin-bottom:5px;font-weight:700}
.section{margin-bottom:24px}
.section h2{font-size:10.5pt;line-height:1.35;border-bottom:1px solid #d6e0ea;padding-bottom:9px;margin-bottom:12px;color:var(--navy-deep)}
.details td{padding:5px 20px 7px 0}
.details .label{font-size:8pt;color:#6d7d8e;margin-bottom:6px}
.details strong{font-size:9.6pt;line-height:1.55}
.terms{font-size:9.6pt;line-height:1.65;text-align:left}
.terms p{margin-bottom:10px}
.terms .term-title{font-size:10pt;color:var(--navy-deep);margin-top:17px;margin-bottom:8px;padding-left:10px;border-left:2px solid #a9bfd3}
.terms .term-item{padding-left:17px;text-indent:-12px;margin-bottom:7px}
.signature-section{margin-top:32px}
.signatures{gap:42px}
.sig{min-height:142px;padding-top:7px}
.sig-title{font-size:8.5pt;letter-spacing:0}
.sig-name{font-size:9.4pt}
.sig-bottom{border-top:1px solid #b7c7d7}
.notice{font-size:7.7pt;color:#778598}
.footer{border-top:1px solid #cbd8e5;padding-top:10px;margin-top:18px;font-size:7.6pt}
@media print{
 .paper{border-top:5px solid var(--navy-deep);padding:15mm 19mm 14mm}
 .letterhead{margin-bottom:26px}
 .refline{margin-bottom:23px}
 .section{margin-bottom:21px}
 .terms{line-height:1.56}
 .signature-section{break-inside:avoid}
}
@media screen and (max-width:620px){
 .refline{grid-template-columns:1fr;gap:9px}
 .refline span{padding:0 0 9px;border-right:0;border-bottom:1px solid #d9e1e9}
 .refline span:last-child{border-bottom:0;padding-bottom:0}
 .signatures{gap:18px}
 .document-title h1{font-size:15pt}
}
</style></head><body>
<div class="actions"><strong><?=h($q['quote_no'])?> · Sözleşme</strong><button onclick="window.print()">Yazdır / PDF Kaydet</button></div>
<main class="paper"><header class="letterhead"><img src="assets/eksioglu-logo.png" alt="EKŞİOĞLU LOJİSTİK"><div class="letterhead-side"><strong>EKŞİOĞLU LOJİSTİK</strong>INTERNATIONAL MOVERS<br>ULUSLARARASI TAŞIMACILIK</div></header>
<div class="document-title"><div class="doc-eyebrow">EKŞİOĞLU LOJİSTİK <span> / </span> INTERNATIONAL MOVERS</div><h1><?=h($lang==='en'?'INTERNATIONAL MOVING AGREEMENT':'ULUSLARARASI EV EŞYASI TAŞIMA SÖZLEŞMESİ')?></h1><p><?=h($lang==='en'?'Service agreement':'Hizmet Sözleşmesi')?></p></div>
<div class="refline" role="group" aria-label="Sözleşme referans bilgileri"><span><strong><?=h($lang==='en'?'Reference':'Sözleşme No')?>:</strong> <?=h($q['quote_no'])?></span><span><strong><?=h($lang==='en'?'Date':'Tarih')?>:</strong> <?=h($documentDate)?></span><span><strong><?=h($lang==='en'?'Volume':'Hacim')?>:</strong> <?=h(number_format((float)$q['volume'],2,',','.'))?> m³</span></div>
<section class="section"><h2><?=h($lang==='en'?'1. Parties':'1. Sözleşmenin Tarafları')?></h2><table class="details"><tr><td><span class="label"><?=h($lang==='en'?'Service provider':'Hizmet Sağlayıcı')?></span><strong>EKŞİOĞLU LOJİSTİK</strong></td><td><span class="label"><?=h($lang==='en'?'Customer':'Müşteri')?></span><strong><?=h($q['name'])?><?=!empty($q['company'])?' / '.h($q['company']):''?></strong></td></tr></table></section>
<section class="section"><h2><?=h($lang==='en'?'2. Moving Information':'2. Taşıma Bilgileri')?></h2><table class="details"><tr><td><span class="label"><?=h($lang==='en'?'Origin':'Yükleme Adresi')?></span><strong><?=h($q['origin']?:'—')?></strong></td><td><span class="label"><?=h($lang==='en'?'Destination':'Teslimat Adresi')?></span><strong><?=h($q['destination']?:'—')?></strong></td></tr></table></section>
<section class="section"><h2><?=h($lang==='en'?'3. Terms and Conditions':'3. Sözleşme Hükümleri')?></h2><div class="terms"><?php
$lines=preg_split('/\\R/u',(string)$contract['body']);$paragraph=[];
$flush=function()use(&$paragraph){if(!$paragraph)return;echo '<p class="term-para">'.h(implode("\n",$paragraph)).'</p>'; $paragraph=[];};
foreach($lines as $line){$trim=trim($line);if($trim===''){$flush();continue;}
if(preg_match('/^\\s*[*•-]\\s*(.+)$/u',$line,$m)){$flush();echo '<p class="term-item">'.h($m[1]).'</p>';continue;}
if(mb_strlen($trim)<115 && (preg_match('/:\\s*$/u',$trim)||preg_match('/^[A-ZÇĞİÖŞÜ0-9\\s\\/(),.:-]{8,}$/u',$trim))){$flush();echo '<h3 class="term-title">'.h($trim).'</h3>';continue;}
$paragraph[]=$line;}
$flush();
?></div></section>
<section class="section signature-section"><h2><?=h($lang==='en'?'4. Signatures':'4. İmza ve Onay')?></h2><div class="signatures"><div class="sig"><div class="sig-title"><?=h($lang==='en'?'CUSTOMER':'MÜŞTERİ')?></div><span class="sig-name"><?=h($signature['signer_name']??$q['name'])?></span><?php if($signature):?><img class="sig-image" src="<?=h($signImage)?>" alt="Müşteri imzası"><div class="sig-bottom"><?=h($lang==='en'?'Signed':'İmzalandı')?> · <?=h(date('d.m.Y H:i',strtotime($signature['signed_at'])))?></div><?php else:?><div class="sig-spacer"></div><div class="sig-bottom"><?=h($lang==='en'?'Signature / Date':'İmza / Tarih')?></div><?php endif;?></div><div class="sig"><div class="sig-title"><?=h($lang==='en'?'SERVICE PROVIDER':'HİZMET SAĞLAYICI')?></div><span class="sig-name">EKŞİOĞLU LOJİSTİK</span><div class="sig-spacer"></div><div class="sig-bottom"><?=h($lang==='en'?'Authorized signature / stamp / date':'Yetkili İmza / Kaşe / Tarih')?></div></div></div></section>
<p class="notice"><?=h($lang==='en'?'This document reflects the saved agreement text.':'Bu belge, sistemde kayıtlı sözleşme metnini içerir.')?></p>
<footer class="footer"><span>EKŞİOĞLU LOJİSTİK · INTERNATIONAL MOVERS</span><span><?=h($q['quote_no'])?></span></footer></main></body></html>