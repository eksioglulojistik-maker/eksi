<?php
require __DIR__.'/inc/core.php'; require __DIR__.'/inc/quote-richtext.php'; auth(); require_once __DIR__.'/inc/moving-workflow.php';require __DIR__.'/inc/layout.php';require_once __DIR__.'/inc/survey-import.php';require_once __DIR__.'/inc/quote-editor.php';
$fieldErrors=[];$editorToken='';
$pdo=db(); $id=max(0,(int)($_GET['id']??0)); $existing=null; $error=''; $warning='';
if($id){$s=$pdo->prepare('SELECT * FROM quotes WHERE id=?');$s->execute([$id]);$existing=$s->fetch();if(!$existing){http_response_code(404);exit('Teklif bulunamadı.');}}
// The extension is additive; no existing quotes or columns are removed.
$movingReady=true;
try{$movingReady=(bool)$pdo->query("SHOW TABLES LIKE 'moving_quote_meta'")->fetchColumn();if(!$movingReady)$warning='Taşınma bilgisi tablosu eksik. Yönetici Sistem Kontrolü ekranından kurulumu tamamlamalıdır.';}catch(Throwable $e){$movingReady=false;$warning='Taşınma bilgisi tablosu denetlenemedi.';}

$extra=[];
if($id&&$movingReady){$s=$pdo->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$s->execute([$id]);$extra=json_decode($s->fetchColumn()?:'{}',true)?:[];}
$inventory=null;
if(!$id&&!isset($_GET['survey_id'])&&!isset($_POST['survey_id'])&&$_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['inventory_transfer'])){
 try{
  $transfer=mqEditorToken(is_string($_GET['inventory_transfer'])?$_GET['inventory_transfer']:'');
  $ticket=$_SESSION['mq_editor_tickets'][$transfer];
  if(!$ticket['result']&&isset($ticket['inventory'])){$editorToken=$transfer;$inventory=$ticket['inventory'];}
 }catch(RuntimeException $e){$warning=$e->getMessage();}
}
if(!$id&&is_array($inventory)){$extra['inventory_summary']=$inventory['summary']??'';}
$extraKeys=['origin_country','origin_city','origin_floor','origin_lift','origin_access','destination_country','destination_city','destination_floor','destination_lift','destination_access','move_date','date_flexible','inventory_summary'];
$contentReady=false; $contentTemplates=[]; $selectedContent=null; $currentDetails=[];
try { $contentReady=(bool)$pdo->query("SHOW TABLES LIKE 'moving_content_templates'")->fetchColumn(); if($contentReady)$contentTemplates=$pdo->query("SELECT id,name,transport,language FROM moving_content_templates WHERE active=1 ORDER BY transport,name")->fetchAll(); } catch(Throwable $e) {}
if($id){try{$st=$pdo->prepare('SELECT * FROM quote_details WHERE quote_id=?');$st->execute([$id]);$currentDetails=$st->fetch()?:[];}catch(Throwable $e){}}
$loadedVersion=hash('sha256',json_encode([$existing,$currentDetails,$extra],JSON_UNESCAPED_UNICODE));
$protectionReason=$existing?ekQuoteProtectionReason($pdo,$existing):'';$quoteProtected=$protectionReason!=='';
if($id&&(!isset($_SESSION['quote_revision_nonce'][$id])||isset($_SESSION['quote_revision_results'][$_SESSION['quote_revision_nonce'][$id]])))$_SESSION['quote_revision_nonce'][$id]=bin2hex(random_bytes(32));
if(!empty($currentDetails['transport_process']))$currentDetails['service_scope']=ekRichDisplay($currentDetails['service_scope']??'').'<div>'.ekRichDisplay($currentDetails['transport_process']).'</div>';
$contentFields=['service_scope','payment_terms','delivery_time','additional_terms'];
$surveySource=null;$surveyPlan=null;$surveyImportNotice='';$surveyNonce='';
$surveyRequested=(!$id&&(isset($_GET['survey_id'])||isset($_POST['survey_id'])));
if($surveyRequested){
 $rawSurvey=$_SERVER['REQUEST_METHOD']==='POST'?($_POST['survey_id']??null):($_GET['survey_id']??null);
 $surveyId=filter_var($rawSurvey,FILTER_VALIDATE_INT);
 try{
  if(!$surveyId||$surveyId<1)throw new RuntimeException('Geçerli bir ekspertiz seçin.');
  $surveyPlan=ekSurveyImport($pdo,$surveyId,(int)$_SESSION['uid']);$surveySource=$surveyPlan['survey'];
  $extra['inventory_summary']=$surveyPlan['summary'];
  if($_SERVER['REQUEST_METHOD']==='GET'){
   $surveyNonce=bin2hex(random_bytes(32));
   $_SESSION['survey_import_tickets'][$surveyNonce]=['survey_id'=>$surveyId,'version'=>$surveyPlan['fingerprint'],'expires'=>time()+86400];
   foreach($_SESSION['survey_import_tickets'] as $key=>$ticket)if(($ticket['expires']??0)<time())unset($_SESSION['survey_import_tickets'][$key]);
  }else $surveyNonce=is_string($_POST['survey_nonce']??null)?$_POST['survey_nonce']:'';
  $surveyImportNotice='Onaylı ekspertiz #'.$surveyId.' forma aktarıldı. Kaydettiğinizde eşya satırları teklif envanterine kopyalanır; müşteri ve onaylı hacim korunur. Fiyat ve hizmet kapsamını kontrol edin.';
  if(trim($surveySource['origin_address'])===''||trim($surveySource['destination_address'])==='')$surveyImportNotice.=' Kaynakta eksik adres var; kaydetmeden önce tamamlayın.';
 }catch(Throwable $e){http_response_code(403);exit(h($e instanceof PDOException?'Ekspertiz aktarımı okunamadı.':$e->getMessage()));}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();try{
  if(!$id){$editorToken=mqEditorToken(is_string($_POST['editor_token']??null)?$_POST['editor_token']:'');$result=(int)$_SESSION['mq_editor_tickets'][$editorToken]['result'];if($result){$check=$pdo->prepare('SELECT id FROM quotes WHERE id=?');$check->execute([$result]);if(!$check->fetchColumn())throw new RuntimeException('Önceki teklif bulunamadı. Yeni form açın.');redirect('view.php?id='.$result);}}
  if(!$id&&!$surveyRequested)$inventory=$_SESSION['mq_editor_tickets'][$editorToken]['inventory']??null;
  if($surveyRequested){
   $ticket=$_SESSION['survey_import_tickets'][$surveyNonce]??[];
   if(($ticket['expires']??0)<time()||($ticket['survey_id']??0)!==$surveyId||!is_string($_POST['survey_version']??null)||!hash_equals($ticket['version']??'',$_POST['survey_version']))throw new RuntimeException('Ekspertiz aktarım onayı geçersiz veya süresi dolmuş. Bağlantıyı yeniden açın.');
   if(isset($_SESSION['survey_import_results'][$surveyNonce])){
    $saved=(int)$_SESSION['survey_import_results'][$surveyNonce];$s=$pdo->prepare('SELECT id FROM quotes WHERE id=?');$s->execute([$saved]);if(!$s->fetchColumn())throw new RuntimeException('Önceki teklif bulunamadı. Yeni aktarım açın.');redirect('view.php?id='.$saved);
   }
  }

  foreach($_POST as $field=>$value){
   if($field==='services'){if(!is_array($value)||array_filter($value,fn($v)=>!is_string($v)))throw new RuntimeException('Hizmet seçimi geçersiz.');}
   elseif(!is_scalar($value)&&$value!==null)throw new RuntimeException('Form alanı geçersiz: '.$field);
  }
  $extraPrice=(float)($existing['extra_price']??0);
  $fieldErrors=mqEditorErrors($_POST,$extraPrice);if($fieldErrors)throw new RuntimeException('İşaretli alanları kontrol edin. Bilgileriniz korundu.');
  $customer=(int)($_POST['customer_id']??0);$s=$pdo->prepare('SELECT COUNT(*) FROM customers WHERE id=?');$s->execute([$customer]);if(!$s->fetchColumn())throw new Exception('Geçerli bir müşteri seçin.');
  $origin=trim((string)($_POST['origin']??''));$destination=trim((string)($_POST['destination']??''));if($origin===''||$destination==='')throw new Exception('Çıkış ve varış adresleri zorunludur.');
  $volume=ekNumber($_POST['volume']??0,'Hacim',100000);$unit=ekNumber($_POST['unit_price']??0,'Birim fiyat');$discount=ekNumber($_POST['discount']??0,'İndirim');$total=round(max(0,$volume*$unit+$extraPrice-$discount),2);
  if(!is_finite($total)||$total>999999999)throw new Exception('Teklif tutarı geçersiz.');
  $currency=in_array($_POST['currency']??'',['EUR','TRY','USD','GBP'],true)?$_POST['currency']:'EUR';
  $transport=in_array($_POST['transport_type']??'', ['road','sea_fcl','sea_lcl'],true)?$_POST['transport_type']:'';
  if($transport==='')throw new Exception('Karayolu veya denizyolu taşıma tipini seçin.');
  $status=in_array($_POST['status']??'',['draft','priced','sent','accepted','rejected','expired'],true)?$_POST['status']:'draft';
  if(!$id&&!in_array($status,['draft','priced'],true))throw new RuntimeException('Yeni teklif taslak veya fiyatlandırıldı olarak kaydedilir. Gönderim ve kabul kayıt sonrası yönetilir.');
  $valid=($_POST['valid_until']??'')?:null;if($valid&&!ekDate($valid))throw new Exception('Geçerlilik tarihi geçersiz.');
  // Legacy terms are retained for compatibility; only the unified content template is selectable.
  $terms=$existing['terms_snapshot']??'';
  $contentId=max(0,(int)($_POST['content_template_id']??0));
  $contentValues=[];
  foreach($contentFields as $field){$contentValues[$field]=ekRichClean($_POST[$field]??'');if(mb_strlen($contentValues[$field])>12000)throw new Exception('Teklif içeriği çok uzun.');}
  if($contentId){if(!$contentReady)throw new Exception('İçerik şablonu kurulumu eksik.');$st=$pdo->prepare('SELECT id FROM moving_content_templates WHERE id=? AND active=1');$st->execute([$contentId]);if(!$st->fetchColumn())throw new Exception('Seçilen şablon bulunamadı.');}
  $notes=trim((string)($_POST['notes']??''));
  $meta=[];foreach($extraKeys as $key)$meta[$key]=trim((string)($_POST[$key]??''));
  if($meta['move_date']!==''&&!ekDate($meta['move_date']))throw new RuntimeException('Taşınma tarihi geçersiz.');
  if(strlen(json_encode($meta,JSON_UNESCAPED_UNICODE))>30000)throw new Exception('Ek bilgiler çok uzun.');
  $originalId=$id;$pdo->beginTransaction();
  if($surveyRequested)$surveyPlan=ekSurveyImport($pdo,$surveyId,(int)$_SESSION['uid'],true);
  if($id){
   $st=$pdo->prepare('SELECT * FROM quotes WHERE id=? FOR UPDATE');$st->execute([$id]);$lockedQuote=$st->fetch();
   if(!$lockedQuote)throw new RuntimeException('Teklif bulunamadı.');
   ekAssertQuoteEditable($pdo,$lockedQuote);
   $st=$pdo->prepare('SELECT * FROM quote_details WHERE quote_id=?');$st->execute([$id]);$lockedDetails=$st->fetch()?:[];
   $st=$pdo->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$st->execute([$id]);$lockedExtra=json_decode($st->fetchColumn()?:'{}',true)?:[];
   $meta=array_merge($lockedExtra,$meta);
   if((int)($lockedExtra['revision_parent_id']??0)>0&&$status!=='draft')throw new RuntimeException('Bu revizyon yalnız taslak olarak saklanabilir. Onay ve gönderim için sözleşme/tahsilat mutabakatı gerekir.');
   $currentVersion=hash('sha256',json_encode([$lockedQuote,$lockedDetails,$lockedExtra],JSON_UNESCAPED_UNICODE));
   if(!is_string($_POST['record_version']??null)||!hash_equals($currentVersion,$_POST['record_version']))throw new RuntimeException('Bu teklif başka bir işlemde değiştirildi. Verilerinizi kopyalayıp sayfayı yenileyin; güncel kayıt üzerine yazılmadı.');
  }
  $customerLock=$pdo->prepare('SELECT id FROM customers WHERE id=? FOR UPDATE');$customerLock->execute([$customer]);if(!$customerLock->fetchColumn())throw new RuntimeException('Müşteri değişmiş veya silinmiş. Sayfayı yenileyin.');
  if($id){
   ekSnapshotQuote($pdo,$lockedQuote,$lockedDetails,$lockedExtra,(int)$_SESSION['uid']);
  }
  if($id && $lockedQuote['currency']!==$currency){
   // A paid quote must not silently switch currencies: no FX rate exists in this module.
   $paymentTable=$pdo->query("SHOW TABLES LIKE 'ek_quote_payments'")->fetchColumn();
   if($paymentTable){$pc=$pdo->prepare('SELECT COUNT(*) FROM ek_quote_payments WHERE quote_id=?');$pc->execute([$id]);
    if((int)$pc->fetchColumn()>0)throw new RuntimeException('Bu teklifte tahsilat bulunduğu için para birimi değiştirilemez. Önce finansal mutabakat yapın.');}
  }
  if($id){$s=$pdo->prepare('UPDATE quotes SET mode=?,customer_id=?,origin=?,destination=?,volume=?,unit_price=?,extra_price=?,discount=?,total=?,currency=?,status=?,valid_until=?,notes=?,terms_snapshot=? WHERE id=?');$s->execute([$transport,$customer,$origin,$destination,$volume,$unit,$extraPrice,$discount,$total,$currency,$status,$valid,$notes,$terms,$id]);}
  else{$number=nextNumber($pdo);$s=$pdo->prepare('INSERT INTO quotes(customer_id,mode,trade_direction,origin,destination,volume,weight,pieces,unit_price,extra_price,discount,tax_rate,total,currency,status,valid_until,notes,terms_snapshot,quote_no,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$customer,$transport,'',$origin,$destination,$volume,0,(int)($inventory['pieces']??0),$unit,$extraPrice,$discount,0,$total,$currency,$status,$valid,$notes,$terms,$number,$_SESSION['uid']]);$id=(int)$pdo->lastInsertId();}
  if($surveyRequested){$meta['survey_import']=ekCopySurveyInventory($pdo,$id,$surveyPlan,$customer,$volume,$_POST['survey_version']);$meta['inventory_summary']=$surveyPlan['summary'];$s=$pdo->prepare('UPDATE quotes SET pieces=? WHERE id=?');$s->execute([$surveyPlan['pieces'],$id]);}
  if(!$originalId&&$inventory&&!empty($inventory['rows'])){
   $itemInsert=$pdo->prepare("INSERT INTO v11_inventory(quote_id,room,item_name,quantity,unit_volume,notes) VALUES (?,?,?,?,?,'')");
   foreach($inventory['rows'] as $item)$itemInsert->execute([$id,...$item]);
   $meta['inventory_summary']=$inventory['summary'];
  }
  if(!$movingReady)throw new Exception('Ek taşınma bilgileri için veritabanı tablosu hazır değil; kayıt yapılmadı.');
  $s=$pdo->prepare('INSERT INTO moving_quote_meta (quote_id,payload) VALUES (?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)');$s->execute([$id,json_encode($meta,JSON_UNESCAPED_UNICODE)]);
  $st=$pdo->prepare("INSERT INTO quote_details (quote_id,service_scope,payment_terms,delivery_time,additional_terms,transport_process) VALUES (?,?,?,?,?,'') ON DUPLICATE KEY UPDATE transport_process='',service_scope=VALUES(service_scope),payment_terms=VALUES(payment_terms),delivery_time=VALUES(delivery_time),additional_terms=VALUES(additional_terms)");
  $st->execute([$id,$contentValues['service_scope'],$contentValues['payment_terms'],$contentValues['delivery_time'],$contentValues['additional_terms']]);
  if($status==='accepted'){if($valid&&$valid<date('Y-m-d')&&($existing['status']??'')!=='accepted')throw new RuntimeException('Teklifin geçerlilik süresi dolmuş.');if(!ekAdmin())throw new RuntimeException('Teklif kabulü yönetici tarafından yapılmalıdır.');$st=$pdo->prepare('SELECT q.*,c.name FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=? FOR UPDATE');$st->execute([$id]);ekAcceptanceDocuments($pdo,$st->fetch(),'quote_editor');}
  $pdo->commit();if(!$originalId)$_SESSION['mq_editor_tickets'][$editorToken]['result']=$id;$_SESSION['moving_saved_draft_context']=$surveyRequested?'survey-'.$surveyId.'-'.$_POST['survey_version']:($originalId?'quote-'.$originalId:($inventory?'quote-inventory-'.$editorToken:'quote-new-customer-'.(int)($_GET['customer_id']??0)));if($surveyRequested)$_SESSION['survey_import_results'][$surveyNonce]=$id;if(!$originalId)unset($_SESSION['mq_editor_tickets'][$editorToken]['inventory']);$_SESSION['moving_saved_id']=$id;redirect('view.php?id='.$id);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$id=$originalId??$id;error_log('Quote save failed: '.$e->getMessage());$error=$e instanceof PDOException?'Kayıt tamamlanamadı. Sistem Kontrolü ekranını inceleyin.':$e->getMessage();}
}
$customers=$pdo->query('SELECT id,name,company FROM customers ORDER BY name')->fetchAll();
// Legacy templates remain in the database; they are not shown as a second selector.
$v=$existing??[];if(!$id&&isset($_GET['customer_id']))$v['customer_id']=max(0,(int)$_GET['customer_id']);if(!$id&&is_array($inventory)){$v['volume']=$inventory['volume']??0;}
if($surveySource){$v['customer_id']=(int)$surveySource['customer_id'];$v['origin']=(string)$surveySource['origin_address'];$v['destination']=(string)$surveySource['destination_address'];$v['volume']=(float)$surveySource['approved_volume'];}
$val=fn($k,$default='')=>h($_POST[$k]??$v[$k]??$extra[$k]??$default);
if(!$id&&$editorToken==='')$editorToken=mqEditorToken();
$draftScope=$surveySource?'survey-'.$surveySource['id'].'-'.$surveyPlan['fingerprint']:($id?'quote-'.$id:($inventory?'quote-inventory-'.$editorToken:'quote-new-customer-'.(int)($_GET['customer_id']??0)));
$fe=function($name)use($fieldErrors){if(isset($fieldErrors[$name]))echo '<small class="mq-field-error" role="alert">'.h($fieldErrors[$name]).'</small>';};
top($quoteProtected?'Korumalı Teklif':($id?'Teklif Düzenle':'Yeni Teklif'));
?>
<link rel="stylesheet" href="assets/moving-quote.css?v=364101"><link rel="stylesheet" href="assets/quote-richtext.css?v=331301">
<div class="mq-workspace">
<header class="mq-heading"><div><small>ULUSLARARASI EV TAŞIMA</small><h1><?= $quoteProtected?'Korumalı teklif':($id?'Teklifi düzenle':'Yeni teklif') ?></h1><p>Müşteriyi seçin, taşımayı planlayın ve fiyatı belirleyin.</p></div><a class="mq-btn mq-outline" href="quotes.php">Teklif listesi</a></header>
<?php if($quoteProtected):?><div class="mq-alert" role="status"><?=h($protectionReason)?></div><form method="post" action="quote-revise.php" class="mq-actions"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="quote_id" value="<?=$id?>"><input type="hidden" name="record_version" value="<?=h($loadedVersion)?>"><input type="hidden" name="revision_nonce" value="<?=h($_SESSION['quote_revision_nonce'][$id])?>"><?php if(empty($extra['revision_effective_child_id'])&&($extra['revision_offer']['state']??'')!=='offered'):?><button type="submit" class="mq-btn">Yeni taslak revizyon oluştur</button><?php else:?><a href="<?=!empty($extra['revision_effective_child_id'])?'quote.php?id='.(int)$extra['revision_effective_child_id']:'revision-review.php?id='.(int)$id?>">Geçerli revizyon işlemlerini aç</a><?php endif;?><a class="mq-btn mq-outline" href="view.php?id=<?=$id?>">Kayıtlı teklifi görüntüle</a></form><?php endif;?>
<?php if(!empty($extra['revision_parent_id'])):?><p class="mq-alert">Bu taslak, <a href="view.php?id=<?=(int)$extra['revision_parent_id']?>">kaynak teklifin</a> revizyonudur. Kaynak kayıt, imza ve tahsilatlar korunur. Müşteri sunumu ve kabul durumu revizyon karşılaştırmasından yönetilir; kayıtlı eski imza yeni belgeye aktarılmaz. <a href="revision-review.php?id=<?=$id?>">Revizyonu karşılaştır</a></p><?php endif;?>
<?php if($error):?><div class="mq-alert"><?=h($error)?></div><?php endif;?>
<?php if($surveySource):
$surveyCustomerLabel='—';
foreach($customers as $customerOption){
 if((int)$customerOption['id']===(int)$surveySource['customer_id']){
  $surveyCustomerLabel=$customerOption['name'].($customerOption['company']?' · '.$customerOption['company']:'');
  break;
 }
}
?><section class="mq-card mq-survey-summary" aria-labelledby="mq-survey-title">
<div class="mq-cardhead"><h2 id="mq-survey-title">Ekspertizden aktarıldı</h2><p><?=h($surveyImportNotice)?></p></div>
<dl class="mq-survey-fields">
<div><dt>Müşteri</dt><dd><?=h($surveyCustomerLabel)?></dd></div>
<div><dt>Onaylı hacim</dt><dd><?=number_format($surveyPlan['volume'],2,',','.')?> m³</dd></div>
<div><dt>Çıkış adresi</dt><dd><?=h($surveySource['origin_address']?:'Belirtilmedi')?></dd></div>
<div><dt>Varış adresi</dt><dd><?=h($surveySource['destination_address']?:'Belirtilmedi')?></dd></div>
</dl>
<details class="mq-details"><summary>Eşya listesi <span><?=(int)$surveyPlan['pieces']?> adet</span></summary>
<div class="mq-details-body"><ul class="mq-survey-items">
<?php foreach($surveyPlan['items'] as $item):?><li><?=h($item['room'].' / '.$item['item_name'])?> · <?=(int)$item['quantity']?> adet</li><?php endforeach;?>
</ul></div></details>
</section><?php endif;?>
<?php if($warning):?><div class="mq-alert"><?=h($warning)?></div><?php endif;?>
<div class="mq-layout"><div class="mq-main"><nav class="mq-steps" aria-label="Teklif aşamaları">
<button type="button" class="mq-step active" data-nav="0"><span>1</span><b>Müşteri</b></button><button type="button" class="mq-step" data-nav="1"><span>2</span><b>Taşıma</b></button><button type="button" class="mq-step" data-nav="2"><span>3</span><b>Fiyat ve içerik</b></button></nav>
<form method="post" id="mq-form" data-legacy-extra="<?=h((string)($existing['extra_price']??0))?>" data-survey="<?=$surveySource?(int)$surveySource['id']:0?>" data-survey-version="<?=h($surveyPlan['fingerprint']??'')?>" data-protected="<?=$quoteProtected?'1':'0'?>" data-draft-scope="<?=h($draftScope)?>" data-record-version="<?=h($loadedVersion)?>" autocomplete="on" novalidate data-user="<?=h($_SESSION['uid'])?>" data-saved="<?=h($_SESSION['moving_saved_id']??'')?>" data-calculator-transfer="<?=$inventory?1:0?>" data-posted="<?= $_SERVER['REQUEST_METHOD']==='POST'?'1':'0' ?>"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="record_version" value="<?=h($_POST['record_version']??$loadedVersion)?>"><?php if($surveySource):?><input type="hidden" name="customer_id" value="<?=(int)$surveySource['customer_id']?>"><input type="hidden" name="survey_id" value="<?=(int)$surveySource['id']?>"><input type="hidden" name="survey_version" value="<?=h(is_string($_POST['survey_version']??null)?$_POST['survey_version']:$surveyPlan['fingerprint'])?>"><input type="hidden" name="survey_nonce" value="<?=h($surveyNonce)?>"><?php endif;?>
<?php if(!$id):?><input type="hidden" name="editor_token" value="<?=h($editorToken)?>"><?php endif;?>
<fieldset class="mq-protected-fields" <?=$quoteProtected?'disabled':''?>>
<section class="mq-card mq-panel" data-panel="0"><div class="mq-cardhead"><h2>Müşteri seçimi</h2><p>Zorunlu alanlar * ile işaretlidir.</p></div>
<div class="mq-fields"><label class="mq-full">Müşteri *<select name="customer_id" id="mq-customer" required <?=$surveySource?'disabled':''?>><option value="">Müşteri seçin</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>" <?=((int)($_POST['customer_id']??$v['customer_id']??0)===(int)$c['id'])?'selected':''?>><?=h($c['name'].($c['company']?' · '.$c['company']:''))?></option><?php endforeach;?></select><?php $fe('customer_id');?></label></div>
<?php if($surveySource):?><p class="mq-helper">Müşteri onaylı ekspertize bağlıdır; bu teklif içinde değiştirilemez.</p><?php endif;?>
<button type="button" class="mq-link" id="mq-add-customer" aria-expanded="false" aria-controls="mq-customerbox" <?=$surveySource?'hidden':''?>>＋ Yeni müşteri</button>
<div class="mq-customerbox" id="mq-customerbox" hidden><h3>Yeni müşteri</h3><div class="mq-fields"><label>Ad soyad *<input id="mq-new-name" maxlength="190"></label><label>Telefon<input id="mq-new-phone" type="tel" maxlength="50"></label><label>E-posta<input id="mq-new-email" type="email" maxlength="190"></label><label>Müşteri türü<select id="mq-new-type"><option value="individual">Bireysel</option><option value="company">Kurumsal</option></select></label><label class="mq-full">Firma adı<input id="mq-new-company" maxlength="190"></label></div><div class="mq-inline-actions"><button type="button" class="mq-btn" id="mq-create-customer">Kaydet ve seç</button><button type="button" class="mq-btn mq-outline" id="mq-cancel-customer">Vazgeç</button></div><p id="mq-customer-msg" role="status"></p></div></section>
<section class="mq-card mq-panel" data-panel="1" hidden><div class="mq-cardhead"><h2>Taşıma planı</h2><p>Güzergâh, hacim ve taşınma tarihi.</p></div>
<div class="mq-fields"><label>Taşıma yöntemi *<select name="transport_type" id="mq-transport-type" required><?php $selectedTransport=$_POST['transport_type']??($v['mode']??'');foreach([''=>'Yöntem seçin','road'=>'Karayolu','sea_fcl'=>'Denizyolu · FCL','sea_lcl'=>'Denizyolu · LCL'] as $tk=>$tl):?><option value="<?=$tk?>" <?=$selectedTransport===$tk?'selected':''?>><?=$tl?></option><?php endforeach;?></select><?php $fe('transport_type');?></label><label>Eşya hacmi · m³ *<input data-ek-volume type="number" min="0.001" max="100000" step="0.001" name="volume" id="mq-volume" <?=$surveySource?'readonly':''?> value="<?=$val('volume','0')?>" required><?php $fe('volume');?></label></div>
<?php if($surveySource):?><p class="mq-helper">Hacim ve envanter onaylı ekspertizden gelir. Değişiklik için ekspertizde revizyon açın.</p><?php else:?><p class="mq-helper"><a href="inventory.php" target="_blank" rel="noopener">Hacim hesaplayıcıyı aç ↗</a> · Hacmi elle girebilir veya hesaplayıcıdan yeni teklife eşya listesiyle aktarabilirsiniz.</p><?php endif;?>
<div class="mq-route"><?php foreach(['origin'=>'Çıkış / yükleme','destination'=>'Varış / teslimat'] as $side=>$label):?><div class="mq-routecard"><h3><?=$label?></h3><div class="mq-fields"><label>Ülke<input name="<?=$side?>_country" value="<?=$val($side.'_country')?>" maxlength="190"></label><label>Şehir<input name="<?=$side?>_city" value="<?=$val($side.'_city')?>" maxlength="190"></label><label class="mq-full">Açık adres *<textarea name="<?=$side?>" rows="3" maxlength="4000" required><?=$val($side)?></textarea><?php $fe($side);?></label></div></div><?php endforeach;?></div>
<div class="mq-fields mq-spaced"><label>Taşınma tarihi<input type="date" name="move_date" value="<?=$val('move_date')?>"><?php $fe('move_date');?></label><label class="mq-checkline"><input type="checkbox" name="date_flexible" value="1" <?=$val('date_flexible')==='1'?'checked':''?>> Tarih esnek</label></div>

<details class="mq-details"><summary>Bina erişimi ve envanter ayrıntıları <span>İsteğe bağlı</span></summary><div class="mq-details-body mq-fields">
<?php foreach(['origin'=>'Çıkış','destination'=>'Varış'] as $side=>$label):?><label><?=$label?> kat<input name="<?=$side?>_floor" value="<?=$val($side.'_floor')?>" maxlength="100"></label><label><?=$label?> asansör<select name="<?=$side?>_lift"><option value="">Belirtilmedi</option><?php foreach(['Var','Yok','Bilinmiyor'] as $o):?><option <?=$val($side.'_lift')===$o?'selected':''?>><?=$o?></option><?php endforeach;?></select></label><label class="mq-full"><?=$label?> bina / araç erişimi<input name="<?=$side?>_access" value="<?=$val($side.'_access')?>" maxlength="1000"></label><?php endforeach;?>
<?php if($surveySource):?><textarea name="inventory_summary" hidden readonly><?=$val('inventory_summary')?></textarea><?php else:?>
<label class="mq-full">Eşya envanteri özeti<textarea name="inventory_summary" rows="4" maxlength="12000"><?=$val('inventory_summary')?></textarea></label><?php endif;?>
</div></details></section>
<section class="mq-card mq-panel" data-panel="2" hidden><div class="mq-cardhead"><h2>Fiyat ve teklif içeriği</h2><p>Kaydetmek müşteriye teklif göndermez.</p></div>
<div class="mq-fields"><label>m³ başına bedel<input type="number" name="unit_price" id="mq-unit" min="0" max="999999999" step="0.01" value="<?=$val('unit_price','0')?>"><?php $fe('unit_price');?></label><label>Para birimi<select name="currency" id="mq-currency"><?php foreach(['EUR','TRY','USD','GBP'] as $c):?><option <?=$val('currency','EUR')===$c?'selected':''?>><?=$c?></option><?php endforeach;?></select></label></div>
<details class="mq-details"><summary>Fiyat ayrıntıları <span>İndirim</span></summary><div class="mq-details-body mq-fields"><label>İndirim<input type="number" name="discount" id="mq-discount" min="0" max="999999999" step="0.01" value="<?=$val('discount','0')?>"><?php $fe('discount');?></label></div></details>
<div class="mq-finance"><span>Teklif toplamı <small>Hacim × birim bedel<?=!empty($existing['extra_price'])&&(float)$existing['extra_price']!==0.0?' + kayıtlı ek bedel':''?> − indirim</small></span><strong id="mq-big-total">0,00 EUR</strong></div>
<div class="mq-fields mq-spaced"><label>Geçerlilik tarihi<input type="date" name="valid_until" value="<?=h(array_key_exists('valid_until',$_POST)?(string)$_POST['valid_until']:($id?($v['valid_until']??''):date('Y-m-t')))?>"><?php $fe('valid_until');?></label><label>Kayıt durumu<select name="status"><?php foreach($id?['draft','priced','sent','accepted','rejected','expired']:['draft','priced'] as $s):?><option value="<?=$s?>" <?=!empty($extra['revision_parent_id'])&&$s!=='draft'?'disabled':''?> <?=$val('status','draft')===$s?'selected':''?>><?=h(statusText($s))?></option><?php endforeach;?></select></label></div>
<div class="mq-content"><div class="mq-cardhead"><h3>Teklif metni</h3><p>Dahil olan ve olmayan hizmetleri şablondan doldurun veya kapsam metninde düzenleyin.</p></div><label>İçerik şablonu<select name="content_template_id" id="content-template"><option value="0">Şablon seçmeden devam et</option><?php foreach($contentTemplates as $ct):?><option value="<?=(int)$ct['id']?>" <?=$val('content_template_id')===(string)$ct['id']?'selected':''?>><?=h($ct['name'].' · '.$ct['language'])?></option><?php endforeach;?></select></label>
<?php foreach(['service_scope'=>'Hizmet kapsamı','payment_terms'=>'Ödeme koşulları','delivery_time'=>'Teslimat süresi','additional_terms'=>'Diğer koşullar'] as $key=>$label):?><details class="mq-details mq-rich-details" <?=$key==='service_scope'?'open':''?>><summary><?=h($label)?></summary><div class="mq-details-body"><textarea data-ek-rich data-rich-label="<?=h($label)?>" name="<?=$key?>" id="content-<?=$key?>" rows="3" maxlength="12000"><?=h(ekRichEditor($_POST[$key]??$currentDetails[$key]??''))?></textarea></div></details><?php endforeach;?>
<label>Müşteriye özel not<textarea name="notes" rows="3" maxlength="8000" placeholder="Bu teklife özel açıklama…"><?=$val('notes')?></textarea></label></div></section>
</fieldset><div class="mq-actions"><button type="button" class="mq-btn mq-outline" id="mq-prev">Geri</button><span id="mq-save-status" role="status" aria-live="polite"><?=$quoteProtected?'Korumalı kayıt.':'Kaydedilmemiş taslak bu sekmede saklanır.'?></span><button type="button" class="mq-btn" id="mq-next">Taşıma bilgilerine geç</button><button type="submit" class="mq-btn" id="mq-submit" <?=$quoteProtected?'disabled':''?> hidden>Teklifi kaydet</button></div></form></div>
<section class="mq-sidebar" aria-label="Teklif özeti"><details class="mq-preview" open><summary><span>Teklif özeti</span><b id="mq-p-total">0,00 EUR</b></summary><div class="mq-preview-body"><dl><?php if(!$surveySource):?><dt>Müşteri</dt><dd id="mq-p-customer">Henüz seçilmedi</dd><dt>Güzergâh</dt><dd id="mq-p-route">Çıkış → Varış</dd><dt>Hacim</dt><dd id="mq-p-volume">0,00 m³</dd><?php endif;?><dt>Taşıma</dt><dd id="mq-p-transport">Henüz seçilmedi</dd><dt>Tarih</dt><dd id="mq-p-date">Belirtilmedi</dd></dl><p class="mq-helper">Kaydetme sonrası belge önizlemesini açabilirsiniz.</p></div></details></section></div></div>
<script src="assets/moving-quote.js?v=364501"></script><script src="assets/quote-richtext.js?v=34001"></script>
<script>
(function(){
 const selector=document.getElementById('content-template');
 if(!selector)return;
 const keys=['service_scope','payment_terms','delivery_time','additional_terms'];
 let lastSelection=selector.value;
 selector.addEventListener('change',async function(){
  const id=this.value;
  if(!id){lastSelection='0';return;}
  const hasContent=keys.some(k=>(document.getElementById('content-'+k)?.value||'').trim());
  if(hasContent && !await window.ekConfirm('Bu teklifin mevcut açıklamaları seçilen şablon metinleriyle değiştirilecek. Müşteri ve fiyat bilgileri korunur. Devam edilsin mi?')){
   this.value=lastSelection;return;
  }
  this.disabled=true;
  try{
   const response=await fetch('v119-template-api.php?id='+encodeURIComponent(id),{credentials:'same-origin',cache:'no-store'});
   if(!response.ok)throw new Error('Şablon yüklenemedi.');
   const payload=await response.json();
   if(!payload.fields)throw new Error('Şablon içeriği bulunamadı.');
   keys.forEach(k=>{const el=document.getElementById('content-'+k);if(el){if(el.ekRichSet)el.ekRichSet(payload.fields[k]||'');else el.value=payload.fields[k]||'';el.dispatchEvent(new Event('input',{bubbles:true}));}});
   lastSelection=id;
  }catch(err){window.ekAlert(err.message);this.value=lastSelection;}
  finally{this.disabled=false;}
 });
})();
</script>
<?php bottom(); ?>
