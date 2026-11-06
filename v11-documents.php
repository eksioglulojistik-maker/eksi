<?php
require __DIR__.'/inc/v11.php';
auth();v11_require();
$id=(int)($_GET['id']??$_POST['id']??0);
$q=$id?v11_quote($id):null;
$err='';$ok=isset($_GET['purged'])?'Sözleşme taslağı kalıcı silindi. Teklif ve müşteri kaydı korundu.':'';
// Customer signing links are stored as one-way hashes, until signing or manual revocation.
function ek_share_table(){db()->exec("CREATE TABLE IF NOT EXISTS ek_contract_share_links (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quote_id INT NOT NULL, token_hash CHAR(64) NOT NULL, contract_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_token_hash (token_hash), KEY idx_quote (quote_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}
$shareUrl='';$shareError='';
// Contract details are read-only on GET. Share expiry changes require a dedicated authorized POST.

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='share'){
 try{
  verify();if(!$q||!v111_is_admin())throw new RuntimeException('Yetki veya teklif bulunamadı.');
  ek_share_table();db()->beginTransaction();
  $row=ekLockEditableContract(db(),$id);if(!$row)throw new RuntimeException('Önce sözleşmeyi kaydedin.');
  $ch=ekContractVersion($row);$token=bin2hex(random_bytes(32));
  $st=db()->prepare('INSERT INTO ek_contract_share_links (quote_id,token_hash,contract_hash,expires_at,created_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 30 DAY),NOW())');$st->execute([$id,hash('sha256',$token),$ch]);
  $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$host=$_SERVER['HTTP_HOST']??'';
  if(!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/',$host))throw new RuntimeException('Sunucu adresi geçersiz.');
  $shareUrl=$scheme.'://'.$host.'/customer-contract-sign.php?token='.$token;db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$shareUrl='';$shareError=$e instanceof PDOException?'Bağlantı oluşturulamadı.':$e->getMessage();}
}

$defaultTr="ULUSLARARASI EV EŞYASI TAŞIMA SÖZLEŞMESİ\n\nTaraflar: EKŞİOĞLU LOJİSTİK ve müşteri.\nTaşınma güzergâhı ve hizmet bedeli, kabul edilen teklifte belirtildiği gibidir.\nHizmet kapsamı, ödeme, sigorta, gümrük işlemleri ve sorumluluklar taraflarca ayrıca belirlenir.\nBu metin bir taslaktır; hukuki inceleme gerektirir.";
$defaultEn="INTERNATIONAL HOUSEHOLD MOVING AGREEMENT\n\nParties: EKŞİOĞLU LOJİSTİK and the customer.\nRoute and service fee are as stated in the accepted quotation.\nScope of services, payment, insurance, customs and liabilities are subject to agreed terms.\nThis is a draft requiring legal review.";
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'save')!=='share'){
  verify();
 try {
  if(!$q)throw new RuntimeException('Teklif bulunamadı.');
  $action=(string)($_POST['action']??'save');
  if(!in_array($action,['save','delete'],true))throw new RuntimeException('Geçersiz işlem.');
  if(!v111_is_admin())throw new RuntimeException('Sözleşme değişikliği için yönetici yetkisi gerekir.');
  db()->beginTransaction();
  $lockedContract=ekLockEditableContract(db(),$id);
  if(!is_string($_POST['contract_version']??null)||!hash_equals(ekContractVersion($lockedContract),$_POST['contract_version']))throw new RuntimeException('Sözleşme başka bir işlemde değişmiş. Sayfayı yenileyin.');
  if($action==='delete'){throw new RuntimeException('Kalıcı silme için silme incelemesi ekranını kullanın.');
  }else{
   $lang=($_POST['language']??'tr')==='en'?'en':'tr';
   $body=trim((string)($_POST['body']??''));
   if($body==='')throw new RuntimeException('Sözleşme metni boş olamaz.');
   if(mb_strlen($body)>30000)throw new RuntimeException('Sözleşme metni 30.000 karakteri aşamaz.');
   $s=db()->prepare('INSERT INTO v11_contracts(quote_id,language,body) VALUES (?,?,?) ON DUPLICATE KEY UPDATE language=VALUES(language),body=VALUES(body)');
   $s->execute([$id,$lang,$body]);
   v11_event($id,'contract_saved',['language'=>$lang]);
   $ok='Sözleşme taslağı başarıyla kaydedildi.';
  }
  db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=$e->getMessage();}
}
// Hide archived offers without a saved agreement; retain existing contracts in history.
$contractListWhere=ekTableExists(db(),'quote_archives')
 ? ' WHERE (NOT EXISTS (SELECT 1 FROM quote_archives qa WHERE qa.quote_id=q.id) OR EXISTS (SELECT 1 FROM v11_contracts vc WHERE vc.quote_id=q.id))' : '';
$picker=v11QuotePicker($contractListWhere,true);$quotes=$picker['rows'];
$contract=null;
if($q){$s=db()->prepare('SELECT * FROM v11_contracts WHERE quote_id=?');$s->execute([$id]);$contract=$s->fetch(PDO::FETCH_ASSOC) ?: null;}
$lang=$contract['language']??'tr';
$body=$contract['body']??($lang==='en'?$defaultEn:$defaultTr);
$ekSigned=null;
if($contract){try{$st=db()->prepare('SELECT signer_name,signed_at,contract_hash FROM ek_contract_signatures WHERE quote_id=? LIMIT 1');$st->execute([$id]);$ekSigned=$st->fetch(PDO::FETCH_ASSOC);}catch(Throwable $e){}}
$ekSignedValid=$ekSigned && hash_equals((string)$ekSigned['contract_hash'],hash('sha256',$contract['language']."\n".$contract['body']));
$canEdit=v111_is_admin()&&!$ekSigned;$contractVersion=ekContractVersion($contract);
require __DIR__.'/inc/layout.php';top('Sözleşme Yönetimi');
?>
<link rel="stylesheet" href="assets/contracts-modern.css?v=362701">
<div class="ec-wrap">
<header class="ec-heading"><div><span class="ec-eyebrow">EKŞİOĞLU · BELGE YÖNETİMİ</span><h1>Sözleşmeler</h1><p>Teklifi seçin, sözleşmeyi hazırlayın ve imza sürecini yönetin.</p></div><a class="ec-back" href="operations-hub.php">← Operasyonlar</a></header>
<?php if($err):?><div class="ec-alert ec-error" role="alert"><?=h($err)?></div><?php endif;?>
<?php if($shareError):?><div class="ec-alert ec-error" role="alert"><?=h($shareError)?></div><?php endif;?>
<?php if($ok):?><div class="ec-alert ec-success" role="status"><?=h($ok)?></div><?php endif;?>
<div class="ec-grid">
<aside class="ec-panel ec-sidebar" aria-label="Sözleşme dosyaları"><div class="ec-section-title"><h2>Dosyalar</h2><span id="ec-match-count"><?=count($quotes)?> kayıt</span></div>
<?php v11QuotePickerNav($picker,$id);?><label for="ec-search">Müşteri / teklif ara</label><input id="ec-search" type="search" placeholder="Müşteri veya teklif no" autocomplete="off">
<label for="ec-status-filter">Belge durumu</label><select id="ec-status-filter"><option value="all">Tüm dosyalar</option><option value="saved">Sözleşmesi kayıtlı</option><option value="new">Hazırlanacak</option></select>
<nav class="ec-quote-list" id="ec-quote-list" aria-label="Teklif dosyaları"><?php foreach($quotes as $a):?><a class="ec-quote <?=$id===(int)$a['id']?'selected':''?>" href="v11-documents.php?id=<?=(int)$a['id']?>" <?=$id===(int)$a['id']?'aria-current="page"':''?> data-state="<?=$a['has_contract']?'saved':'new'?>"><strong><?=h($a['name']??'Müşteri bulunamadı')?></strong><small><?=h($a['quote_no'])?></small><span class="ec-file-state"><?=$a['has_contract']?'Kayıtlı sözleşme':'Hazırlanacak'?></span></a><?php endforeach;?></nav>
<p id="ec-empty" class="ec-muted" hidden>Aramanızla eşleşen dosya yok.</p><p class="ec-list-note">Sözleşmeler ve aktif teklifler. Sözleşmesiz arşiv teklifleri gizlidir.</p></aside>
<section class="ec-main" aria-label="Sözleşme çalışma alanı">
<?php if(!$q):?><section class="ec-panel ec-welcome"><span class="ec-eyebrow">SÖZLEŞME HAZIRLIĞI</span><h2>Bir dosya seçin</h2><p>Müşteri veya teklif numarasıyla arayın. Kayıtlı sözleşmeyi inceleyin ya da seçtiğiniz teklif için taslak hazırlayın.</p></section>
<?php else:?>
<section class="ec-panel ec-summary"><div><span class="ec-eyebrow"><?=h($q['quote_no'])?></span><h2><?=h($q['name'])?></h2><?php if(!empty($q['company'])):?><p><?=h($q['company'])?></p><?php endif;?></div><div class="ec-summary-actions"><span class="ec-state <?=$ekSignedValid?'signed':($ekSigned?'attention':($contract?'has':'none'))?>"><?=$ekSignedValid?'İmzalandı':($ekSigned?'İmza incelemesi gerekiyor':($contract?'İmza bekleyen taslak':'Hazırlanacak'))?></span><a class="ec-btn ec-secondary" href="customer-detail.php?id=<?=(int)$q['customer_id']?>">Müşteri profili</a></div>
<div class="ec-context"><span><b>Güzergâh</b><?=h($q['origin']?:'Çıkış belirtilmedi')?> → <?=h($q['destination']?:'Varış belirtilmedi')?></span><span><b>Hacim</b><?=h(number_format((float)$q['volume'],2,',','.'))?> m³</span></div>
<?php if($ekSignedValid):?><p class="ec-signature-note">İmzalayan: <?=h($ekSigned['signer_name'])?> · <?=h($ekSigned['signed_at'])?>. İmzalı metin korunur.</p><?php elseif($ekSigned):?><p class="ec-signature-note">İmza ve kayıtlı metin uyuşmuyor. Kayıt inceleme için kilitli tutuluyor.</p><?php endif;?></section>
<section class="ec-panel ec-workbench"><div class="ec-editor-toolbar"><div><h2>Sözleşme metni</h2><p class="ec-muted"><?=$canEdit?'Metni kontrol edin ve değişiklikleri kaydedin.':'Salt okunur belge görünümü.'?></p></div><div class="ec-actions"><?php if($contract):?><a class="ec-btn ec-secondary" target="_blank" rel="noopener" href="v11-print.php?id=<?=$id?>&amp;type=contract">Yazdır / PDF ↗</a><?php endif;?><a class="ec-text-link" href="view.php?id=<?=$id?>">Teklifi aç ↗</a></div></div>
<div class="ec-tabs" role="tablist" aria-label="Sözleşme görünümü" hidden><button type="button" role="tab" id="ec-tab-edit" aria-controls="ec-edit-panel" data-ec-tab="ec-edit-panel">Metin</button><button type="button" role="tab" id="ec-tab-preview" aria-controls="ec-preview-panel" data-ec-tab="ec-preview-panel">Önizleme</button></div>
<div id="ec-edit-panel" aria-labelledby="ec-tab-edit"><form method="post" id="ec-editor-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="contract_version" value="<?=h($contractVersion)?>"><input type="hidden" name="action" value="save">
<div class="ec-form-head"><label for="ec-lang">Belge dili<select name="language" id="ec-lang" <?=$canEdit?'':'disabled'?>><option value="tr" <?=$lang==='tr'?'selected':''?>>Türkçe</option><option value="en" <?=$lang==='en'?'selected':''?>>English</option></select></label><span class="ec-counter" id="ec-counter">0 / 30.000 karakter</span></div>
<label class="ec-sr-only" for="ec-body">Sözleşme metni</label><textarea name="body" id="ec-body" rows="12" maxlength="30000" required <?=$canEdit?'':'readonly'?>><?=h($body)?></textarea>
<div class="ec-editor-footer"><span class="ec-muted" id="ec-unsaved" role="status"><?=$contract?'Kayıtlı metin':'Henüz sözleşme kaydedilmedi.'?></span><?php if($canEdit):?><button class="ec-btn ec-primary" type="submit"><?=$contract?'Değişiklikleri kaydet':'Taslak Oluştur'?></button><?php endif;?></div></form></div>
<div id="ec-preview-panel" aria-labelledby="ec-tab-preview"><p class="ec-preview-note">Bu önizleme ekrandaki metni gösterir. PDF ve müşteri bağlantısı son kaydedilen metni kullanır.</p><article class="ec-paper"><header>EKŞİOĞLU <small>INTERNATIONAL MOVERS</small></header><pre id="ec-preview-text"><?=h($body)?></pre></article></div>
</section>
<?php if($contract && $canEdit):?><details class="ec-panel ec-share-primary" id="ec-share"<?= $shareUrl||$shareError?' open':'' ?>><summary>Müşteriye gönder / imza bağlantısı</summary><p style="color:#60758b">Müşteriye doğrudan bağlantı gönderin. Müşteri sözleşmeyi belge görünümünde okuyup yalnızca kendi ekranında imzalar. Kod veya giriş gerekmez. Bağlantıya sahip herkes imzalayabilir.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="contract_version" value="<?=h($contractVersion)?>"><input type="hidden" name="action" value="share"><button class="ec-btn ec-primary" type="submit">Müşteri bağlantısı oluştur</button></form><?php if($shareUrl):?><div style="margin-top:13px"><label for="ek-share-link">Müşteriye gönderilecek bağlantı</label><input id="ek-share-link" style="width:100%;padding:11px;margin:8px 0" readonly value="<?=h($shareUrl)?>"><div class="ec-actions"><button type="button" class="ec-btn ec-secondary" onclick="navigator.clipboard.writeText(document.getElementById('ek-share-link').value)">Bağlantıyı kopyala</button><a class="ec-btn ec-secondary" target="_blank" rel="noopener noreferrer" href="https://wa.me/?text=<?=rawurlencode('EKŞİOĞLU LOJİSTİK sözleşmenizi inceleyip imzalamak için: '.$shareUrl)?>">WhatsApp ile gönder</a><a class="ec-btn ec-secondary" href="mailto:<?=rawurlencode((string)($q['email']??''))?>?subject=<?=rawurlencode('EKŞİOĞLU LOJİSTİK · Sözleşmeniz')?>&body=<?=rawurlencode('Merhaba, sözleşmenizi inceleyip imzalamak için: '.$shareUrl)?>">E-posta ile gönder</a></div><small>Bu bağlantı 30 gün geçerlidir. Yalnızca ilgili müşteriyle paylaşın. Müşteri bağlantıyı açıp sözleşmeyi inceleyerek dokunmatik imza atar. Kod doğrulaması gerekmez.</small></div><?php endif;?></details><?php endif;?>
<?php if(!$contract):?><div class="ec-info">İmza bağlantısı ve sözleşme PDF’i, taslağı kaydettikten sonra kullanılabilir.</div><?php endif;?>
<details class="ec-panel ec-more"><summary>Teklif çıktıları ve diğer işlemler</summary><div class="ec-actions"><a class="ec-btn ec-secondary" target="_blank" rel="noopener" href="v11-print.php?id=<?=$id?>&amp;lang=tr&amp;type=quote">Türkçe teklif ↗</a><a class="ec-btn ec-secondary" target="_blank" rel="noopener" href="v11-print.php?id=<?=$id?>&amp;lang=en&amp;type=quote">English quotation ↗</a><?php if($contract&&$canEdit):?><a class="ec-btn ec-danger" href="permanent-delete.php?kind=contract&amp;id=<?=(int)$contract['id']?>">Silme incelemesi</a><?php endif;?></div></details>
<p class="ec-legal">Sözleşme metnini paylaşmadan önce kontrol edin. Sistem otomatik mesaj göndermez; imza bağlantısı oluşturmak sözleşmeyi imzalamaz.</p>
<?php endif;?></section></div></div>
<script src="assets/contracts-workspace.js?v=362701" defer></script>
<?php bottom(); ?>
