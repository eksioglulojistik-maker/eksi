<?php
require __DIR__.'/inc/core.php';auth();require_once __DIR__.'/inc/v11.php';require_once __DIR__.'/inc/quote-share-display.php';
$pdo=db();$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit('Geçersiz teklif.');}
$s=$pdo->prepare('SELECT q.*,c.name,c.company,c.email,c.phone,c.address FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');$s->execute([$id]);$q=$s->fetch();if(!$q){http_response_code(404);exit('Teklif bulunamadı.');}
$d=$pdo->prepare('SELECT * FROM quote_details WHERE quote_id=?');$d->execute([$id]);$detail=$d->fetch()?:[];
$shareMeta=ekRevisionMeta($pdo,$id);
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!ekAdmin()||!empty($shareMeta['revision_parent_id'])){http_response_code(403);exit('Bu bağlantı burada yenilenemez.');}
 verify();
 if(($_POST['action']??'')!=='rotate_share'){http_response_code(400);exit('Geçersiz işlem.');}
 $pdo->beginTransaction();
 try{
  ekLockQuote($pdo,$id);
  $shareMeta=ekRevisionMeta($pdo,$id);
  $shareMeta['quote_share_nonce']=bin2hex(random_bytes(32));
  ekWriteRevisionMeta($pdo,$id,$shareMeta);
  $pdo->commit();
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  error_log('Quote link rotation failed: '.$e->getMessage());
  http_response_code(500);exit('Teklif bağlantısı yenilenemedi.');
 }
 redirect('view.php?id='.$id);
}
$link=v11_link($q);
$shareDeadline=(string)($q['valid_until']??'');
$isRevision=!empty($shareMeta['revision_parent_id']);
$revisionOffer=$shareMeta['revision_offer']??[];
$shareBlocked=ekQuoteShareBlock($q,$shareMeta,$link,ekQuoteShareArchived($pdo,$id));
$shareAllowed=$shareBlocked==='';
if($isRevision&&$shareAllowed)$shareDeadline=substr($revisionOffer['expires_at'],0,10);
$shareMessage="EKŞİOĞLU LOJİSTİK - Teklif ".(string)$q['quote_no']."\n".
"Sayın ".(string)$q['name'].", teklifinizi aşağıdaki bağlantıdan inceleyebilirsiniz:\n".$link.
"\nTeklif geçerlilik tarihi: ".$shareDeadline;
$shareEmail=filter_var((string)($q['email']??''),FILTER_VALIDATE_EMAIL)?(string)$q['email']:'';
$sharePhone=preg_replace('/\\D+/','',(string)($q['phone']??''));
if(strlen($sharePhone)===10 && $sharePhone[0]==='5')$sharePhone='90'.$sharePhone;
if(strlen($sharePhone)===11 && str_starts_with($sharePhone,'05'))$sharePhone='9'.$sharePhone;
if(!preg_match('/^[1-9][0-9]{9,14}$/D',$sharePhone))$sharePhone='';
require __DIR__.'/inc/layout.php';top('Teklif Önizleme');
?>
<div class="heading no-print"><div><h1>Teklif Önizleme</h1><p><?=h($q['quote_no'])?> · <?=h(statusText($q['status']))?></p></div><div class="actionbar"><?php if(in_array($q['status'],['accepted','approved','completed'],true)):?><a class="btn secondary" href="move-file.php?id=<?=$id?>">Taşınma dosyası</a><?php endif;?><a class="btn secondary" href="quote-actions.php?id=<?=$id?>">Tüm Teklif İşlemleri</a><a class="btn secondary" href="quote-revisions.php?id=<?=$id?>">Revizyonlar</a><a class="btn secondary" href="quote-payments.php?id=<?=$id?>">Tahsilatlar</a><a class="btn secondary" href="quote.php?id=<?=$id?>">Teklifi Düzenle</a><a class="btn secondary" href="quote-details.php?id=<?=$id?>">Hizmet Bölümleri</a><button type="button" onclick="window.print()">Yazdır / PDF Kaydet</button></div></div>
<section id="customer-share" class="panel no-print sharepanel" aria-labelledby="customer-share-title">
<h2 id="customer-share-title">Müşteriye Gönder</h2>
<?php if($shareAllowed):?>
<p>Bağlantıyı yalnızca ilgili müşteriyle paylaşın. E-posta ve WhatsApp düğmeleri mesaj taslağı açar.</p>
<label for="customerLink">Müşteri bağlantısı</label>
<div class="sharelink">
<input readonly id="customerLink" value="<?=h($link)?>">
<button type="button" id="copyCustomerLink">Kopyala</button>
</div>
<p id="customerShareStatus" role="status" hidden></p>
<div class="actionbar">
<?php if($shareEmail):?><a class="btn secondary" href="<?=h('mailto:'.$shareEmail.'?subject='.rawurlencode('EKŞİOĞLU LOJİSTİK - Teklif '.$q['quote_no']).'&body='.rawurlencode($shareMessage))?>">E-posta taslağını aç</a><?php endif;?>
<?php if($sharePhone):?><a class="btn secondary" href="<?=h('https://wa.me/'.$sharePhone.'?text='.rawurlencode($shareMessage))?>" target="_blank" rel="noopener noreferrer">WhatsApp taslağını aç</a><?php endif;?>
<?php if(!$isRevision&&ekAdmin()):?>
<form method="post" onsubmit="return confirm('Önceki müşteri bağlantısı geçersiz olacak. Yeni bağlantı oluşturulsun mu?')">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="btn secondary" name="action" value="rotate_share">Bağlantıyı Yenile</button>
</form><?php endif;?>
</div>
<?php if(!$shareEmail||!$sharePhone):?><p><?=h(!$shareEmail&&!$sharePhone?'Geçerli e-posta adresi ve telefon numarası kayıtlı değil.':(!$shareEmail?'Geçerli e-posta adresi kayıtlı değil.':'Geçerli telefon numarası kayıtlı değil.'))?></p><?php endif;?>
<script>
document.getElementById('copyCustomerLink').addEventListener('click', async () => {
 const input = document.getElementById('customerLink');
 const status = document.getElementById('customerShareStatus');
 try {
  await navigator.clipboard.writeText(input.value);
  status.textContent = 'Bağlantı kopyalandı.';
 } catch {
  input.focus();
  input.select();
  status.textContent = 'Bağlantıyı seçili alandan kopyalayabilirsiniz.';
 }
 status.hidden = false;
});
</script>
<?php else:?><p><?=h($shareBlocked)?></p><?php endif;?>
</section>
<?php if((int)($_SESSION['moving_saved_id']??0)===$id):$draftContext=$_SESSION['moving_saved_draft_context']??('quote-'.$id);unset($_SESSION['moving_saved_id'],$_SESSION['moving_saved_draft_context']);if(is_string($draftContext)&&preg_match('/^(quote-(new|[0-9]+|new-customer-[0-9]+)|survey-[0-9]+-[a-f0-9]{64})$/D',$draftContext)):?><script>sessionStorage.removeItem(<?=json_encode('eksioglu-moving-draft-v334-'.(int)$_SESSION['uid'].'-'.$draftContext,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>);sessionStorage.removeItem(<?=json_encode((str_starts_with($draftContext,'quote-')?'eksioglu-moving-draft-v150-'.(int)$_SESSION['uid'].'-'.substr($draftContext,6):'eksioglu-moving-draft-v321-'.(int)$_SESSION['uid'].'-'.$draftContext),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>);</script><?php endif;endif;?>
<?php $viewMeta=ekRevisionMeta($pdo,(int)$id);if(!empty($viewMeta['revision_effective_child_id'])):?><p class="notice">Bu teklif tarihsel kayıttır. <a href="view.php?id=<?=(int)$viewMeta['revision_effective_child_id']?>">Geçerli revizyonu görüntüle</a></p><?php endif;?>
<?php if(!empty($viewMeta['survey_import']['survey_id'])):?><p class="notice no-print">Kaynak: <a href="ekspertiz.php?id=<?=(int)$viewMeta['survey_import']['survey_id']?>">Ekspertiz #<?=(int)$viewMeta['survey_import']['survey_id']?></a> · Envanter, teklif oluşturulurken kopyalandı.</p><?php endif;?>
<?php include __DIR__.'/inc/quote-document.php'; bottom(); ?>